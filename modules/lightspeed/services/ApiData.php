<?php

namespace modules\lightspeed\services;

use modules\lightspeed\Module;
use yii\base\Exception;

/**
 * The API export (`lightspeed/extract`), indexed by ID. Lists with texts are per language: `$products['nl'][$id]`.
 */
class ApiData
{
    /** @var array<string, array<int, array>> language => product ID => product */
    public array $products = [];
    /** @var array<string, array<int, array[]>> language => product ID => variants, sorted */
    public array $variants = [];
    /** @var array<string, array<int, array>> language => category ID => category, parents first */
    public array $categories = [];
    /** @var array<string, array[]> language => brands */
    public array $brands = [];
    /** @var array<int, int[]> product ID => category IDs */
    public array $productCategories = [];
    /** @var array<int, string[]> product ID => image URLs, sorted */
    public array $productImages = [];
    /** @var array<int, float> tax ID => rate */
    public array $taxRates = [];
    /** @var array<int, array> customer ID => customer */
    public array $customers = [];
    /** @var array<int, string[]> customer ID => Lightspeed group names */
    public array $customerGroups = [];
    /** @var array<int, array> order ID => order, oldest first */
    public array $orders = [];
    /** @var array<int, array[]> order ID => order lines */
    public array $orderLines = [];
    /** @var array<int, array[]> order ID => invoices and credit notes */
    public array $invoices = [];

    /**
     * @param bool $sample per-item lists of Sample::LIMITED_LISTS may be partial
     */
    public static function load(Store $store, bool $sample = false): self
    {
        $data = new self();
        $read = fn(string $name, ?string $language = null) => $store->read($name, $language)
            ?? throw new Exception("Missing $name" . ($language ? " ($language)" : '') . ': run lightspeed/extract first.');

        foreach (array_keys(Module::SITES) as $language) {
            $data->products[$language] = array_column($read('products', $language), null, 'id');
            $data->brands[$language] = $read('brands', $language);

            $categories = $read('categories', $language);
            usort($categories, fn(array $a, array $b) => [$a['depth'], $a['sortOrder']] <=> [$b['depth'], $b['sortOrder']]);
            $data->categories[$language] = array_column($categories, null, 'id');

            $variants = $read('variants', $language);
            usort($variants, fn(array $a, array $b) => [!$a['isDefault'], $a['sortOrder']] <=> [!$b['isDefault'], $b['sortOrder']]);
            foreach ($variants as $variant) {
                $data->variants[$language][self::id($variant['product'])][] = $variant;
            }
        }

        $links = $read('categories-products');
        usort($links, fn(array $a, array $b) => $a['sortOrder'] <=> $b['sortOrder']);
        foreach ($links as $link) {
            $data->productCategories[self::id($link['product'])][] = self::id($link['category']);
        }

        $productImages = $sample
            ? $store->read('products-images') ?? $store->read('products-images.partial') ?? []
            : $read('products-images');
        foreach ($productImages as $productId => $images) {
            usort($images, fn(array $a, array $b) => $a['sortOrder'] <=> $b['sortOrder']);
            $data->productImages[$productId] = array_column($images, 'src');
        }

        $data->taxRates = array_column($read('taxes'), 'rate', 'id');
        $data->customers = array_column($read('customers'), null, 'id');

        $groups = array_column($read('groups'), 'title', 'id');
        foreach ($read('groups-customers') as $link) {
            $data->customerGroups[self::id($link['customer'])][] = $groups[self::id($link['group'])];
        }

        $orders = $read('orders');
        usort($orders, fn(array $a, array $b) => $a['id'] <=> $b['id']);
        $data->orders = array_column($orders, null, 'id');
        $data->orderLines = $read('orders-products');

        foreach ($read('invoices') as $invoice) {
            $data->invoices[self::id($invoice['order'])][] = $invoice;
        }

        return $data;
    }

    /**
     * Linked objects are `{"resource": {"id": 123, …}}`, or `false` when not set.
     */
    public static function id(array|false $resource): ?int
    {
        return $resource ? ($resource['resource']['id'] ?: null) : null;
    }

    public function isGuest(int $customerId): bool
    {
        return ($this->customers[$customerId]['type'] ?? null) === 'guest';
    }
}
