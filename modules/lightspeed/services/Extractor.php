<?php

namespace modules\lightspeed\services;

use yii\base\Component;
use yii\base\Exception;

/**
 * Downloads Lightspeed data from the API (read-only) into the Store, one JSON file per list.
 */
class Extractor extends Component
{
    /**
     * File name => endpoint. The same in every language: fetched once.
     */
    public const SHARED = [
        'shop' => 'shop',
        'languages' => 'languages',
        'taxes' => 'taxes',
        'suppliers' => 'suppliers',
        'discounts' => 'discounts',
        'customers' => 'customers',
        'groups' => 'groups',
        'groups-customers' => 'groups/customers',
        'subscriptions' => 'subscriptions',
        'orders' => 'orders',
        'shipments' => 'shipments',
        'invoices' => 'invoices',
        'returns' => 'returns',
        'quotes' => 'quotes',
        'categories-products' => 'categories/products',
        'tags-products' => 'tags/products',
        'types-attributes' => 'types/attributes',
    ];

    /**
     * File name => endpoint. Has texts per language: fetched for every language.
     */
    public const TRANSLATED = [
        'categories' => 'categories',
        'products' => 'products',
        'variants' => 'variants',
        'brands' => 'brands',
        'tags' => 'tags',
        'filters' => 'filters',
        'types' => 'types',
        'attributes' => 'attributes',
        'textpages' => 'textpages',
        'blogs' => 'blogs',
        'blogs-articles' => 'blogs/articles',
        'reviews' => 'reviews',
        'redirects' => 'redirects',
        'shippingmethods' => 'shippingmethods',
        'paymentmethods' => 'paymentmethods',
        'deliverydates' => 'deliverydates',
    ];

    /**
     * File name => endpoint per item of a list, saved as item ID => list.
     * `requires`: skipped when that list is empty. `if`: only items where this field is set.
     * `orders-products` first: the sample needs it to pick its products (see Sample::LIMITED_LISTS).
     */
    public const RELATIONS = [
        'orders-products' => ['list' => 'orders', 'path' => 'orders/{id}/products'],
        'products-images' => ['list' => 'products', 'path' => 'products/{id}/images'],
        'products-relations' => ['list' => 'products', 'path' => 'products/{id}/relations'],
        'products-metafields' => ['list' => 'products', 'path' => 'products/{id}/metafields'],
        'products-filtervalues' => ['list' => 'products', 'path' => 'products/{id}/filtervalues', 'requires' => 'filters'],
        'products-attributes' => ['list' => 'products', 'path' => 'products/{id}/attributes', 'translated' => true, 'if' => 'type'],
        'filters-values' => ['list' => 'filters', 'path' => 'filters/{id}/values', 'translated' => true],
        'invoices-items' => ['list' => 'invoices', 'path' => 'invoices/{id}/items'],
    ];

    /**
     * @var int|null only the first items of every list
     */
    public ?int $max = null;

    /**
     * @var string[]|null active shop languages, default first
     */
    private ?array $languages = null;

    public function __construct(
        private readonly Client $client,
        private readonly Store $store,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @return string[] every file name, in download order (relations last: they need their list)
     */
    public static function names(): array
    {
        return [...array_keys(self::SHARED), ...array_keys(self::TRANSLATED), ...array_keys(self::RELATIONS)];
    }

    public static function isRelation(string $name): bool
    {
        return isset(self::RELATIONS[$name]);
    }

    public static function isTranslated(string $name): bool
    {
        return isset(self::TRANSLATED[$name]) || (self::RELATIONS[$name]['translated'] ?? false);
    }

    /**
     * @return string[] the languages a file is downloaded in
     */
    public function languagesOf(string $name): array
    {
        return self::isTranslated($name) ? $this->languages() : [$this->primaryLanguage()];
    }

    /**
     * Downloads a list (not a relation) and saves it.
     */
    public function extractList(string $name, string $language): int
    {
        $data = $this->client->all($language, self::SHARED[$name] ?? self::TRANSLATED[$name], $this->max);
        $this->store->write($name, $data, self::isTranslated($name) ? $language : null);

        return count($data);
    }

    /**
     * @return int[] the item IDs to download for a relation
     * @throws Exception when its list is not downloaded yet
     */
    public function relationIds(string $name): array
    {
        $relation = self::RELATIONS[$name];
        if (isset($relation['requires']) && !$this->stored($relation['requires'])) {
            return [];
        }

        $items = $this->stored($relation['list']) ?? throw new Exception("$name: download {$relation['list']} first.");
        if (isset($relation['if'])) {
            $items = array_filter($items, fn(array $item) => $item[$relation['if']]);
        }

        return array_column($items, 'id');
    }

    public function fetchRelation(string $name, string $language, int $id): array
    {
        return $this->client->all($language, str_replace('{id}', (string)$id, self::RELATIONS[$name]['path']));
    }

    /**
     * Items downloaded so far, so a stopped download can resume.
     */
    public function readPartial(string $name, string $language): array
    {
        return $this->store->read("$name.partial", $this->fileLanguage($name, $language)) ?? [];
    }

    public function writePartial(string $name, string $language, array $data): void
    {
        $this->store->write("$name.partial", $data, $this->fileLanguage($name, $language));
    }

    /**
     * Saves the complete relation and removes the partial file.
     */
    public function finishRelation(string $name, string $language, array $data): void
    {
        $this->store->write($name, $data, $this->fileLanguage($name, $language));
        $this->store->delete("$name.partial", $this->fileLanguage($name, $language));
    }

    public function primaryLanguage(): string
    {
        return $this->languages()[0];
    }

    /**
     * @return string[]
     */
    public function languages(): array
    {
        if ($this->languages === null) {
            $any = $this->client->getLanguages()[0] ?? throw new Exception('Set LIGHTSPEED_LANGUAGES in .env');
            $languages = array_filter($this->client->all($any, 'languages'), fn(array $language) => $language['isActive']);
            usort($languages, fn(array $a, array $b) => $b['isDefault'] <=> $a['isDefault']);
            $this->languages = array_column($languages, 'code');
        }

        return $this->languages;
    }

    /**
     * A list that was downloaded before. Translated lists: the primary language (IDs are the same in every language).
     */
    private function stored(string $name): ?array
    {
        return $this->store->read($name, isset(self::TRANSLATED[$name]) ? $this->primaryLanguage() : null);
    }

    private function fileLanguage(string $name, string $language): ?string
    {
        return self::isTranslated($name) ? $language : null;
    }
}
