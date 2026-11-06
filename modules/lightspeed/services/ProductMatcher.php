<?php

namespace modules\lightspeed\services;

/**
 * Finds the product of an order line. Order lines have no product ID, only a title, SKU and EAN.
 */
class ProductMatcher
{
    /**
     * @var array<string, array{string, string}> SKU or EAN => [product ID, variant ID]
     */
    private array $codes = [];

    /**
     * @var array<string, string[]> lower-case title (any language) => product IDs
     */
    private array $titles = [];

    /**
     * @var array<string, array<string, string>> product ID => lower-case variant title (any language) => variant ID
     */
    private array $variants = [];

    /**
     * @param array<string, array<int, array<string, string>>> $products CSV rows grouped by product
     */
    public function __construct(array $products)
    {
        foreach ($products as $productId => $rows) {
            foreach ($rows as $row) {
                $variantId = str_replace('!ID:', '', $row['Internal_Variant_ID']);
                foreach (['SKU', 'EAN'] as $column) {
                    if ($row[$column] !== '') {
                        $this->codes[$row[$column]] = [(string)$productId, $variantId];
                    }
                }
                foreach (['NL', 'EN', 'FR'] as $lang) {
                    $this->variants[$productId][$this->key($row["{$lang}_Variant"])] ??= $variantId;
                    if ($row["{$lang}_Title_Short"] !== '') {
                        $this->titles[$this->key($row["{$lang}_Title_Short"])][] = (string)$productId;
                    }
                }
            }
        }

        $this->titles = array_map('array_unique', $this->titles);
    }

    /**
     * @return array{string, string}|null [product ID, variant ID]; null when there is no match or the title is not unique
     */
    public function match(array $line): ?array
    {
        foreach (['Product_SKU', 'Product_EAN'] as $column) {
            if ($line[$column] !== '' && isset($this->codes[$line[$column]])) {
                return $this->codes[$line[$column]];
            }
        }

        $productIds = $this->titles[$this->key($line['Product_title'])] ?? [];
        if (count($productIds) !== 1) {
            return null;
        }

        $productId = reset($productIds);
        $variants = $this->variants[$productId];

        return [$productId, $variants[$this->key($line['Product_variant'])] ?? reset($variants)];
    }

    private function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
