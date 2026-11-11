<?php

namespace modules\lightspeed\services;

/**
 * A small, fixed part of the API export for testing: 1 order per case, the customers and products of
 * those orders, and products and customers that cover the other cases. The same input always gives the same sample.
 * Email addresses are made invalid, so no mail can reach a real customer.
 */
class Sample
{
    /**
     * Lists the sample limits the per-item data (Extractor::RELATIONS) of. Other lists: all items.
     * Orders: all, because the sample is chosen by their order lines.
     */
    public const LIMITED_LISTS = ['products', 'invoices'];

    private const ORDERS_PER_CUSTOMER = 3;
    private const VIP_MEMBERS = 3;
    private const EMAIL_SUFFIX = 'x';

    private ApiData $data;

    /**
     * @var array{orders: int[], products: int[], customers: int[], invoices: int[]}|null
     */
    private ?array $ids = null;

    public function __construct(ApiData $data)
    {
        $this->data = clone $data;
    }

    public function apply(): ApiData
    {
        ['orders' => $orderIds, 'products' => $productIds, 'customers' => $customerIds] = $this->ids();

        foreach ($this->data->products as $language => $products) {
            $this->data->products[$language] = array_intersect_key($products, array_flip($productIds));
            $this->data->variants[$language] = array_intersect_key($this->data->variants[$language], array_flip($productIds));
        }
        $this->data->orders = array_map($this->breakEmail(...), array_intersect_key($this->data->orders, array_flip($orderIds)));
        $this->data->customers = array_map($this->breakEmail(...), array_intersect_key($this->data->customers, array_flip($customerIds)));

        return $this->data;
    }

    /**
     * @param string $list one of LIMITED_LISTS
     * @return int[]
     */
    public function itemIds(string $list): array
    {
        return $this->ids()[$list];
    }

    private function ids(): array
    {
        if ($this->ids === null) {
            $orderIds = $this->selectOrders();
            $invoices = array_merge(...array_map(fn(int $id) => $this->data->invoices[$id] ?? [], $orderIds));

            $this->ids = [
                'orders' => $orderIds,
                'products' => $this->selectProducts($orderIds),
                'customers' => $this->selectCustomers($orderIds),
                'invoices' => array_column($invoices, 'id'),
            ];
        }

        return $this->ids;
    }

    /**
     * @return int[]
     */
    private function selectOrders(): array
    {
        $orders = $this->data->orders;
        $lines = fn(array $order) => $this->data->orderLines[$order['id']] ?? [];
        $linked = fn(array $order) => array_filter($lines($order), fn(array $line) => isset($this->data->products['nl'][ApiData::id($line['product'])]));

        $cases = [
            'paid, shipped, product linked' => fn(array $o) => $o['paymentStatus'] === 'paid' && $o['shipmentStatus'] === 'shipped' && $linked($o) && count($linked($o)) === count($lines($o)),
            'several lines, all linked' => fn(array $o) => count($lines($o)) > 1 && count($linked($o)) === count($lines($o)),
            'product deleted in Lightspeed' => fn(array $o) => $lines($o) && !$linked($o),
            'guest checkout' => fn(array $o) => $this->data->isGuest((int)ApiData::id($o['customer'])),
            'GDPR deleted customer' => fn(array $o) => !filter_var($o['email'], FILTER_VALIDATE_EMAIL),
            '21% VAT' => fn(array $o) => array_sum(array_column($o['taxRates'], 'amount')) > 0,
            'discount code' => fn(array $o) => $o['discountCouponCode'] !== '',
            'shipping costs + payment fee' => fn(array $o) => $o['shipmentPriceIncl'] > 0 && $o['paymentPriceIncl'] > 0,
            'credit note' => fn(array $o) => in_array(true, array_column($this->data->invoices[$o['id']] ?? [], 'isCreditNote'), true),
        ];
        foreach (array_unique(array_column($orders, 'status')) as $status) {
            $cases["status $status"] = fn(array $o) => $o['status'] === $status;
        }

        $selected = $this->select($orders, $cases);

        // 1 customer with several orders
        $byCustomer = [];
        foreach ($orders as $id => $order) {
            $byCustomer[ApiData::id($order['customer'])][] = $id;
        }
        foreach ($byCustomer as $customerId => $ids) {
            if (count($ids) > 1 && isset($this->data->customers[$customerId])) {
                array_push($selected, ...array_slice($ids, 0, self::ORDERS_PER_CUSTOMER));
                break;
            }
        }

        return array_values(array_unique($selected));
    }

    /**
     * The products of the orders, and 1 product per case. Products in stock are preferred.
     *
     * @return int[]
     */
    private function selectProducts(array $orderIds): array
    {
        $selected = [];
        foreach ($orderIds as $orderId) {
            foreach ($this->data->orderLines[$orderId] ?? [] as $line) {
                $productId = ApiData::id($line['product']);
                isset($this->data->products['nl'][$productId]) && $selected[] = $productId;
            }
        }

        $products = $this->data->products['nl'];
        $variant = fn(array $p) => $this->data->variants['nl'][$p['id']][0];

        $cases = [
            'price on request' => fn(array $p) => $variant($p)['priceIncl'] <= 2 && $variant($p)['stockLevel'] > 0,
            'several variants' => fn(array $p) => count($this->data->variants['nl'][$p['id']]) > 1,
            'on sale' => fn(array $p) => $variant($p)['oldPriceIncl'] > $variant($p)['priceIncl'] && $variant($p)['priceIncl'] > 2,
            'several categories' => fn(array $p) => count($this->data->productCategories[$p['id']] ?? []) > 2,
            'brand' => fn(array $p) => (bool)$p['brand'],
            'no French text' => fn(array $p) => $this->data->products['fr'][$p['id']]['title'] === '',
            'hidden' => fn(array $p) => !$p['isVisible'],
            '21% VAT' => fn(array $p) => ($this->data->taxRates[ApiData::id($variant($p)['tax'])] ?? 0) > 0,
            'video or viewer' => fn(array $p) => $p['data01'] !== '' || $p['data02'] !== '',
        ];
        foreach (array_keys($this->data->categories['nl']) as $categoryId) {
            $cases["category $categoryId"] = fn(array $p) => in_array($categoryId, $this->data->productCategories[$p['id']] ?? [], true);
        }

        $inStock = fn(callable $case) => fn(array $p) => $case($p) && $variant($p)['stockLevel'] > 0;
        foreach ($cases as $case) {
            $id = $this->first($products, $inStock($case), $selected) ?? $this->first($products, $case, $selected);
            $id !== null && $selected[] = $id;
        }

        return array_values(array_unique($selected));
    }

    /**
     * The customers of the orders, VIP members without orders, and 1 customer per Lightspeed group.
     *
     * @return int[]
     */
    private function selectCustomers(array $orderIds): array
    {
        $selected = array_filter(array_map(fn(int $id) => ApiData::id($this->data->orders[$id]['customer']), $orderIds));
        $buyerIds = array_flip(array_map(fn(array $o) => ApiData::id($o['customer']), $this->data->orders));

        $vipMembers = array_filter($this->data->customers, fn(array $c) => !isset($buyerIds[$c['id']]) && !$this->data->isGuest($c['id']));
        array_push($selected, ...array_slice(array_keys($vipMembers), 0, self::VIP_MEMBERS));

        $groups = [];
        foreach ($this->data->customerGroups as $customerId => $names) {
            foreach ($names as $name) {
                $groups[$name] ??= $customerId;
            }
        }
        array_push($selected, ...array_values($groups));

        return array_values(array_unique(array_filter($selected, fn(int $id) => isset($this->data->customers[$id]))));
    }

    /**
     * The first item per case; an item is only used for 1 case.
     *
     * @param array<string, callable> $cases
     * @return int[]
     */
    private function select(array $items, array $cases): array
    {
        $selected = [];
        foreach ($cases as $case) {
            $id = $this->first($items, $case, $selected);
            $id !== null && $selected[] = $id;
        }

        return $selected;
    }

    private function first(array $items, callable $test, array $exclude): ?int
    {
        foreach ($items as $id => $item) {
            if (!in_array($id, $exclude, true) && $test($item)) {
                return $id;
            }
        }

        return null;
    }

    private function breakEmail(array $item): array
    {
        if (filter_var($item['email'], FILTER_VALIDATE_EMAIL)) {
            $item['email'] .= self::EMAIL_SUFFIX;
        }

        return $item;
    }
}
