<?php

namespace modules\lightspeed\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\Address;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use yii\base\Component;
use yii\base\Exception;

/**
 * Imports the API orders as completed Commerce orders. Orders are history: an order that
 * already exists (matched on reference) is skipped. Commerce does not recalculate them, change stock or send emails.
 */
class OrderImporter extends Component
{
    use SavesElements;

    /**
     * Lightspeed order statuses, 1:1 as Craft order statuses: handle => [name, colour].
     * The first one is the default for new orders, like in Lightspeed.
     */
    public const STATUSES = [
        'processingAwaitingPayment' => ['Awaiting payment', 'orange'],
        'processingAwaitingShipment' => ['Awaiting shipment', 'yellow'],
        'processingAwaitingPickup' => ['Awaiting pickup', 'yellow'],
        'processingReadyForPickup' => ['Ready for pickup', 'blue'],
        'completedShipped' => ['Shipped', 'green'],
        'completedPickedUp' => ['Picked up', 'green'],
        'cancelled' => ['Cancelled', 'red'],
        'withdrawn' => ['Withdrawn', 'red'],
    ];

    public const SHIPMENT_STATUSES = ['not_shipped' => 'Not shipped', 'shipped' => 'Shipped', 'cancelled' => 'Cancelled'];

    public const GATEWAY = 'lightspeed';

    /**
     * @var string[] problems that did not stop the import
     */
    public array $warnings = [];

    public function __construct(
        private readonly ApiData $data,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @param callable(int $done, int $total): void|null $onProgress
     * @return int number of new orders
     */
    public function import(?callable $onProgress = null): int
    {
        $ids = $this->ids();
        $count = 0;
        foreach ($ids as $i => $id) {
            $count += (int)$this->importOrder($id);
            $onProgress && $onProgress($i + 1, count($ids));
        }

        return $count;
    }

    /**
     * @return int[] oldest first
     */
    public function ids(): array
    {
        return array_keys($this->data->orders);
    }

    /**
     * @return bool false when the order already exists
     */
    public function importOrder(int $id): bool
    {
        $data = $this->data->orders[$id];
        if (Order::find()->reference(Db::escapeParam($data['number']))->exists()) {
            return false;
        }

        $commerce = Commerce::getInstance();
        $date = new DateTime($data['createdAt']);

        $order = new Order();
        $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);
        $order->number = $commerce->getCarts()->generateCartNumber();
        $order->reference = $data['number'];
        $order->origin = Order::ORIGIN_REMOTE;
        $order->isCompleted = true;
        $order->dateOrdered = $order->dateCreated = $date;
        $order->orderStatusId = $commerce->getOrderStatuses()->getOrderStatusByHandle(StringHelper::toCamelCase($data['status']), $order->storeId)->id;
        $order->gatewayId = $commerce->getGateways()->getGatewayByHandle(self::GATEWAY)->id;
        $order->shippingMethodName = $data['shipmentTitle'];

        if ($site = Craft::$app->getSites()->getSiteByHandle($data['language']['code'] ?? '')) {
            $order->orderSiteId = $site->id;
            $order->orderLanguage = $site->language;
        }

        if (!$this->isAnonymous($data)) {
            $order->setCustomer($this->customer($data));
            $order->setBillingAddress($this->address($data, AddressMapper::BILLING));
            $order->setShippingAddress($this->address($data, AddressMapper::SHIPPING));
        }

        $order->setLineItems(array_map(fn(array $line) => $this->lineItem($order, $line), $this->data->orderLines[$data['id']] ?? []));
        $order->setAdjustments($this->adjustments($order, $data));

        $invoices = $this->data->invoices[$data['id']] ?? [];
        $numbers = fn(bool $creditNote) => implode(', ', array_column(array_filter($invoices, fn(array $invoice) => $invoice['isCreditNote'] === $creditNote), 'number')) ?: null;
        $order->setFieldValues([
            'lightspeedIdField' => (string)$data['id'],
            'shipmentStatusField' => $data['shipmentStatus'],
            'invoiceNumbersField' => $numbers(false),
            'creditNotesField' => $numbers(true),
            'paymentMethodField' => $data['paymentTitle'] ?: null,
            'discountCodeField' => $data['discountCouponCode'] ? "{$data['discountCouponCode']} (−{$data['discountAmount']})" : null,
            'customerCommentField' => ApiData::html($data['comment']),
            'memoField' => ApiData::html($data['memo']),
        ]);
        $this->save($order);

        if (abs($order->getTotalPrice() - $data['priceIncl']) > 0.01) {
            $this->warnings[] = "Order {$data['number']}: total {$order->getTotalPrice()} in Craft, {$data['priceIncl']} in Lightspeed";
        }

        if ($data['paymentStatus'] === 'paid') {
            $this->markAsPaid($order, $date);
        }

        return true;
    }

    /**
     * Snapshot of the line as it was sold, linked to the variant when it still exists.
     */
    private function lineItem(Order $order, array $line): LineItem
    {
        $service = Commerce::getInstance()->getLineItems();
        $qty = max(1, (int)$line['quantityOrdered']);
        $variantId = ApiData::id($line['variant']);
        $variant = $variantId ? Variant::find()->lightspeedIdField($variantId)->status(null)->one() : null;

        if ($variant) {
            $lineItem = $service->create($order, ['purchasable' => $variant, 'qty' => $qty]);
        } else {
            $lineItem = $service->create($order, ['qty' => $qty], LineItemType::Custom);
            $lineItem->taxCategoryId = Commerce::getInstance()->getTaxCategories()->getDefaultTaxCategory()->id;
            $lineItem->shippingCategoryId = Commerce::getInstance()->getShippingCategories()->getDefaultShippingCategory($order->storeId)->id;
        }

        $lineItem->setDescription(trim($line['productTitle'] . ' ' . $line['variantTitle']));
        $lineItem->setSku($line['sku'] ?: ($variant?->getSku() ?? ''));
        // priceIncl is the line total, discount included.
        $lineItem->setPrice($line['priceIncl'] / $qty);
        $lineItem->setPromotionalPrice(null);

        return $lineItem;
    }

    /**
     * VAT is included in the prices.
     *
     * @return OrderAdjustment[]
     */
    private function adjustments(Order $order, array $data): array
    {
        $adjustments = [];
        $add = function(string $type, string $name, float $amount, bool $included = false) use ($order, &$adjustments) {
            if ($amount > 0) {
                $adjustment = new OrderAdjustment(['type' => $type, 'name' => $name, 'amount' => $amount, 'included' => $included]);
                $adjustment->setOrder($order);
                $adjustments[] = $adjustment;
            }
        };

        $add('shipping', $data['shipmentTitle'] ?: 'Shipping', $data['shipmentPriceIncl']);
        $add('paymentFee', $data['paymentTitle'] ?: 'Payment fee', $data['paymentPriceIncl']);
        $add('tax', 'VAT', array_sum(array_column($data['taxRates'], 'amount')), true);

        return $adjustments;
    }

    private function markAsPaid(Order $order, DateTime $date): void
    {
        $transactions = Commerce::getInstance()->getTransactions();
        $transaction = $transactions->createTransaction($order, null, TransactionRecord::TYPE_PURCHASE);
        $transaction->status = TransactionRecord::STATUS_SUCCESS;
        $transaction->message = $order->getFieldValue('paymentMethodField') ?? '';
        $transactions->saveTransaction($transaction) || throw new Exception("Could not save transaction for order $order->reference");

        // Load again: the order caches its transactions.
        $order = Order::find()->id($order->id)->one();
        $order->datePaid = $order->dateFirstPaid = $date;
        $this->save($order);
    }

    /**
     * Lightspeed replaces the personal data of deleted customers (GDPR) with "GDPR deleted".
     */
    private function isAnonymous(array $data): bool
    {
        return !filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    }

    private function customer(array $data): User
    {
        return User::find()->lightspeedIdField(ApiData::id($data['customer']))->status(null)->one()
            ?? Craft::$app->getUsers()->ensureUserByEmail($data['email']);
    }

    private function address(array $data, string $prefix): ?Address
    {
        $values = AddressMapper::values($data, $prefix);
        if (!$values || !$values['countryCode']) {
            $this->warnings[] = "Order {$data['number']}: " . ($prefix === AddressMapper::SHIPPING ? 'shipping' : 'billing') . ' address missing or no country';
            return null;
        }

        $address = new Address();
        AddressMapper::fill($address, $values);

        return $address;
    }
}
