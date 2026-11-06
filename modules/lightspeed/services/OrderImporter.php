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
use DateTime;
use yii\base\Component;
use yii\base\Exception;

/**
 * Imports the Lightspeed order export as completed Commerce orders. Orders are history: an order that
 * already exists (matched on reference) is skipped. Commerce does not recalculate them, change stock or send emails.
 */
class OrderImporter extends Component
{
    use SavesElements;

    /**
     * Lightspeed status => Craft order status handle
     */
    public const STATUSES = [
        'Paid, shipped' => 'shipped',
        'Not paid, shipped' => 'shipped',
        'Paid, not shipped' => 'new',
        'Not paid, not shipped' => 'new',
        'Cancelled' => 'cancelled',
    ];

    public const GATEWAY = 'lightspeed';

    private const PAID_STATUSES = ['Paid, shipped', 'Paid, not shipped'];

    /**
     * @var string[] problems that did not stop the import
     */
    public array $warnings = [];

    /**
     * @param array<string, array<int, array<string, string>>> $orders CSV rows grouped by order
     * @param string[] $guestIds Lightspeed IDs of guest checkouts (no account)
     */
    public function __construct(
        private readonly array $orders,
        private readonly ProductMatcher $matcher,
        private readonly array $guestIds,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * Creates the customers of the orders that do not exist yet, with the data of their last order.
     * Everyone who ordered is added to the Customers group.
     */
    public function importCustomers(): int
    {
        $rows = $lastOrders = [];
        foreach ($this->orders as [$row]) {
            if ($this->isAnonymous($row)) {
                continue;
            }
            $firstOrder = $rows[$row['Customer']]['Registered'] ?? null;
            $rows[$row['Customer']] = $this->customerRow($row, $firstOrder);
            $lastOrders[$row['Customer']] = $row;
        }

        $importer = new CustomerImporter(array_values($rows), $this->guestIds, onlyNew: true);
        $count = $importer->import();
        array_push($this->warnings, ...$importer->warnings);

        $customers = CustomerImporter::group('customers');
        foreach ($lastOrders as $row) {
            CustomerImporter::addToGroup($this->customer($row), $customers);
        }

        return $count;
    }

    /**
     * @param callable(int $done, int $total): void|null $onProgress
     * @return int number of new orders
     */
    public function importOrders(?callable $onProgress = null): int
    {
        $count = $done = 0;
        foreach ($this->orders as $lines) {
            if (!Order::find()->reference(Db::escapeParam($lines[0]['Order']))->exists()) {
                $this->importOrder($lines);
                $count++;
            }
            $onProgress && $onProgress(++$done, count($this->orders));
        }

        return $count;
    }

    private function importOrder(array $lines): void
    {
        $row = $lines[0];
        $commerce = Commerce::getInstance();
        $date = $this->date($row['Added']);

        $order = new Order();
        $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);
        $order->number = $commerce->getCarts()->generateCartNumber();
        $order->reference = $row['Order'];
        $order->origin = Order::ORIGIN_REMOTE;
        $order->isCompleted = true;
        $order->dateOrdered = $order->dateCreated = $date;
        $order->orderStatusId = $commerce->getOrderStatuses()->getOrderStatusByHandle(self::STATUSES[$row['Status']], $order->storeId)->id;
        $order->gatewayId = $commerce->getGateways()->getGatewayByHandle(self::GATEWAY)->id;
        $order->shippingMethodName = $row['Shipping'];
        if (!$this->isAnonymous($row)) {
            $order->setCustomer($this->customer($row) ?? Craft::$app->getUsers()->ensureUserByEmail($row['E-mail']));

            $customerRow = $this->customerRow($row);
            $order->setBillingAddress($this->address($customerRow, '', $row));
            $order->setShippingAddress($this->address($customerRow, 'Shipping ', $row));
        }
        $order->setLineItems(array_map(fn(array $line) => $this->lineItem($order, $line), $lines));
        $order->setAdjustments($this->adjustments($order, $row));
        $order->setFieldValues([
            'lightspeedId' => $row['Order_ID'],
            'invoiceNumbers' => $row['Invoices'] ?: null,
            'creditNotes' => $row['Credit_notes'] ?: null,
            'paymentMethod' => $row['Payment'] ?: null,
            'discountCode' => $row['Discount_code'] ? "{$row['Discount_code']} (−{$row['Discount_price']})" : null,
            'customerComment' => $row['Comment'] ?: null,
            'memo' => $row['Memo'] ?: null,
        ]);
        $this->save($order);

        $total = CsvExport::number($row['Price_total']);
        if (abs($order->getTotalPrice() - $total) > 0.01) {
            $this->warnings[] = "Order {$row['Order']}: total {$order->getTotalPrice()} in Craft, $total in Lightspeed";
        }

        if (in_array($row['Status'], self::PAID_STATUSES, true)) {
            $this->markAsPaid($order, $date);
        }
    }

    /**
     * Snapshot of the line as it was sold, linked to the product when it can be found.
     */
    private function lineItem(Order $order, array $line): LineItem
    {
        $service = Commerce::getInstance()->getLineItems();
        $qty = (int)$line['Quantity'] ?: 1;
        $match = $this->matcher->match($line);
        $variant = $match ? Variant::find()->lightspeedId($match[1])->status(null)->one() : null;

        if ($variant) {
            $lineItem = $service->create($order, ['purchasable' => $variant, 'qty' => $qty]);
        } else {
            $lineItem = $service->create($order, ['qty' => $qty], LineItemType::Custom);
            $lineItem->taxCategoryId = Commerce::getInstance()->getTaxCategories()->getDefaultTaxCategory()->id;
            $lineItem->shippingCategoryId = Commerce::getInstance()->getShippingCategories()->getDefaultShippingCategory($order->storeId)->id;
        }

        $lineItem->setDescription(trim($line['Product_title'] . ' ' . $line['Product_variant']));
        $lineItem->setSku($line['Product_SKU'] ?: ($variant?->getSku() ?? ''));
        $lineItem->setPrice(CsvExport::number($line['Product_price']));
        $lineItem->setPromotionalPrice(null);

        return $lineItem;
    }

    /**
     * The line prices already include the discount. VAT is included in the prices.
     *
     * @return OrderAdjustment[]
     */
    private function adjustments(Order $order, array $row): array
    {
        $adjustments = [];
        $add = function(string $type, string $name, float $amount, bool $included = false) use ($order, &$adjustments) {
            if ($amount > 0) {
                $adjustment = new OrderAdjustment(['type' => $type, 'name' => $name, 'amount' => $amount, 'included' => $included]);
                $adjustment->setOrder($order);
                $adjustments[] = $adjustment;
            }
        };

        $add('shipping', $row['Shipping'], CsvExport::number($row['Price_shipping']));
        $add('paymentFee', 'Payment fee', CsvExport::number($row['Price_payment']));
        $add('tax', 'VAT', CsvExport::number($row['Price_vat']), true);

        return $adjustments;
    }

    private function markAsPaid(Order $order, DateTime $date): void
    {
        $transactions = Commerce::getInstance()->getTransactions();
        $transaction = $transactions->createTransaction($order, null, TransactionRecord::TYPE_PURCHASE);
        $transaction->status = TransactionRecord::STATUS_SUCCESS;
        $transaction->message = $order->getFieldValue('paymentMethod') ?? '';
        $transactions->saveTransaction($transaction) || throw new Exception("Could not save transaction for order $order->reference");

        // Load again: the order caches its transactions.
        $order = Order::find()->id($order->id)->one();
        $order->datePaid = $order->dateFirstPaid = $date;
        $this->save($order);
    }

    /**
     * Lightspeed replaces the personal data of deleted customers (GDPR) with "GDPR deleted".
     */
    private function isAnonymous(array $row): bool
    {
        return !filter_var($row['E-mail'], FILTER_VALIDATE_EMAIL);
    }

    private function customer(array $row): ?User
    {
        return User::find()->lightspeedId($row['Customer'])->status(null)->one()
            ?? User::find()->email(Db::escapeParam($row['E-mail']))->status(null)->one();
    }

    private function address(array $customerRow, string $prefix, array $row): ?Address
    {
        $values = AddressMapper::values($customerRow, $prefix);
        if (!$values || !$values['countryCode']) {
            $this->warnings[] = "Order {$row['Order']}: " . ($prefix ? 'shipping' : 'billing') . ' address missing or unknown country';
            return null;
        }

        $address = new Address();
        AddressMapper::fill($address, $values);

        return $address;
    }

    /**
     * Order columns in the format of the customer export.
     */
    private function customerRow(array $row, ?string $registered = null): array
    {
        return [
            'Internal_ID' => $row['Customer'],
            'Registered' => $registered ?? $this->date($row['Added'])->format('Y-m-d H:i:s'),
            'Language' => '',
            'Firstname' => $row['Firstname'],
            'LastnamePreposition' => '',
            'Lastname' => $row['Lastname'],
            'E-mail' => $row['E-mail'],
            'Phone' => $row['Phone'],
            'Mobile' => $row['Mobile'],
            'Customer type' => $row['Company'] !== '' ? 'company' : 'default',
            'Company' => $row['Company'],
            'Coc number' => '',
            'Vat number' => $row['Vat'],
            'Attn' => $row['Attn'],
            'Streetname' => $row['Invoice_street'],
            'Address 2' => $row['Invoice_street_2'],
            'Number' => $row['Invoice_housenumber'],
            'Extension' => $row['Invoice_housenumber_ext'],
            'Zipcode' => $row['Invoice_zipcode'],
            'City' => $row['Invoice_city'],
            'Region' => $row['Invoice_region'],
            'Country' => $row['Invoice_country'],
            'Shipping Attn' => $row['Attn'],
            'Shipping company' => $row['Shipping_company'],
            'Shipping Streetname' => $row['Streetname'],
            'Shipping Address 2' => $row['Second_address_line'],
            'Shipping Number' => $row['Number'],
            'Shipping Extension' => $row['Extension'],
            'Shipping Zipcode' => $row['Zipcode'],
            'Shipping City' => $row['City'],
            'Shipping Region' => $row['Region'],
            'Shipping Country' => $row['Country'],
            'Groups' => '',
            'Birthdate' => '',
            'Gender' => '',
            'Confirmed' => '',
        ];
    }

    /**
     * `28-12-2016 @ 10:24`, in the system time zone (Europe/Brussels)
     */
    private function date(string $value): DateTime
    {
        return DateTime::createFromFormat('d-m-Y @ H:i', $value) ?: throw new Exception("Invalid order date: $value");
    }
}
