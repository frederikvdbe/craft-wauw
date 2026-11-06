<?php

namespace modules\lightspeed\services;

/**
 * A small, fixed part of the CSV exports for testing: 1 order per case, the customers and products of
 * those orders, and unsold products that cover the product cases. The same input always gives the same sample.
 */
class Sample
{
    private const ORDERS_PER_CUSTOMER = 3;
    private const VIP_MEMBERS = 3;
    private const EMAIL_SUFFIX = 'x';

    /**
     * @var array<string, array<int, array<string, string>>>
     */
    public array $products = [];

    /**
     * @var array<string, array<int, array<string, string>>>
     */
    public array $orders = [];

    /**
     * @var array<int, array<string, string>>
     */
    public array $customers = [];

    /**
     * @param string[] $guestIds
     */
    public function __construct(array $products, array $orders, array $customers, array $guestIds)
    {
        $matcher = new ProductMatcher($products);
        $this->orders = $this->selectOrders($orders, $guestIds, $matcher);
        $this->products = $this->selectProducts($products, $matcher);
        $this->customers = $this->selectCustomers($customers, $orders, $guestIds);

        $this->customers = $this->breakEmails($this->customers);
        $this->orders = array_map($this->breakEmails(...), $this->orders);
    }

    private function breakEmails(array $rows): array
    {
        return array_map(function(array $row) {
            if (filter_var($row['E-mail'], FILTER_VALIDATE_EMAIL)) {
                $row['E-mail'] .= self::EMAIL_SUFFIX;
            }
            return $row;
        }, $rows);
    }

    /**
     * The customers of the sample orders, and VIP members (account, no orders).
     */
    private function selectCustomers(array $customers, array $orders, array $guestIds): array
    {
        $sampleIds = array_map(fn(array $rows) => $rows[0]['Customer'], $this->orders);
        $buyerIds = array_flip(array_map(fn(array $rows) => $rows[0]['Customer'], $orders));

        $selected = array_filter($customers, fn(array $row) => in_array($row['Internal_ID'], $sampleIds, true));
        $vipMembers = array_filter($customers, fn(array $row) => !isset($buyerIds[$row['Internal_ID']]) && !in_array($row['Internal_ID'], $guestIds, true));

        return [...array_values($selected), ...array_slice(array_values($vipMembers), 0, self::VIP_MEMBERS)];
    }

    private function selectOrders(array $orders, array $guestIds, ProductMatcher $matcher): array
    {
        $matched = fn(array $rows) => array_filter($rows, fn(array $line) => $matcher->match($line) !== null);
        $status = fn(string $status) => fn(array $rows) => $rows[0]['Status'] === $status;
        $cases = [
            'paid, shipped, product matched' => fn(array $rows) => $rows[0]['Status'] === 'Paid, shipped' && count($matched($rows)) === count($rows),
            'several lines, all matched' => fn(array $rows) => count($rows) > 1 && count($matched($rows)) === count($rows),
            'product not matched' => fn(array $rows) => !$matched($rows),
            'guest checkout' => fn(array $rows) => in_array($rows[0]['Customer'], $guestIds, true),
            'cancelled' => $status('Cancelled'),
            'paid, not shipped' => $status('Paid, not shipped'),
            'not paid' => $status('Not paid, not shipped'),
            '21% VAT' => fn(array $rows) => CsvExport::number($rows[0]['Price_vat']) > 0,
            'discount code' => fn(array $rows) => $rows[0]['Discount_code'] !== '',
            'shipping costs + payment fee' => fn(array $rows) => CsvExport::number($rows[0]['Price_shipping']) > 0 && CsvExport::number($rows[0]['Price_payment']) > 0,
        ];

        $selected = [];
        foreach ($cases as $case) {
            $id = $this->first($orders, $case, $selected);
            if ($id !== null) {
                $selected[$id] = $orders[$id];
            }
        }

        // 1 customer with several orders
        $byCustomer = [];
        foreach ($orders as $id => $rows) {
            $byCustomer[$rows[0]['Customer']][$id] = $rows;
        }
        foreach ($byCustomer as $customerOrders) {
            if (count($customerOrders) > 1) {
                $selected += array_slice($customerOrders, 0, self::ORDERS_PER_CUSTOMER, true);
                break;
            }
        }

        return $selected;
    }

    private function selectProducts(array $products, ProductMatcher $matcher): array
    {
        $selected = [];
        foreach ($this->orders as $rows) {
            foreach ($rows as $line) {
                if ($match = $matcher->match($line)) {
                    $selected[$match[0]] = $products[$match[0]];
                }
            }
        }

        $inStock = fn(array $rows) => (int)$rows[0]['Stock_Level'] > 0;
        $category = fn(string $name) => fn(array $rows) => $rows[0]['NL_Category_1'] === $name;

        $cases = [
            'price on request' => fn(array $rows) => (float)$rows[0]['Price'] <= 2,
            'several variants' => fn(array $rows) => count($rows) > 1,
            'on sale' => fn(array $rows) => (float)$rows[0]['Price'] > 2 && (float)$rows[0]['Price_Old'] > (float)$rows[0]['Price'],
            'collection category' => fn(array $rows) => $rows[0]['NL_Category_1'] === 'collection in stock' && $rows[0]['NL_Category_2'] !== '',
            'TOP 50' => $category('TOP 50'),
            'PREVIEW' => $category('PREVIEW'),
            'brand' => fn(array $rows) => $rows[0]['Brand'] !== '',
            'no French text' => fn(array $rows) => $rows[0]['FR_Title_Short'] === '',
            'hidden' => fn(array $rows) => $rows[0]['Visible'] === 'N',
            '21% VAT' => fn(array $rows) => $rows[0]['Tax'] === '21',
            'extra images, video or viewer' => fn(array $rows) => $rows[0]['Data_01'] !== '' || $rows[0]['Data_02'] !== '',
        ];

        foreach ($cases as $case) {
            // Prefer a product that is not sold.
            foreach ([fn($rows) => $case($rows) && $inStock($rows), $case] as $test) {
                $id = $this->first($products, $test, $selected);
                if ($id !== null) {
                    $selected[$id] = $products[$id];
                    break;
                }
            }
        }

        return $selected;
    }

    private function first(array $items, callable $test, array $exclude): int|string|null
    {
        foreach ($items as $id => $rows) {
            if (!isset($exclude[$id]) && $test($rows)) {
                return $id;
            }
        }

        return null;
    }
}
