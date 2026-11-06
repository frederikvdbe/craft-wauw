<?php

namespace modules\lightspeed\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use GuzzleHttp\Exception\ClientException;
use modules\lightspeed\Module;
use yii\base\Exception;
use yii\console\ExitCode;

/**
 * Downloads all Lightspeed data from the API (read-only) to JSON files in storage/lightspeed/api.
 *
 * @property Module $module
 */
class ExtractController extends Controller
{
    private const SAMPLE_SIZE = 25;
    private const SAMPLE_DIR = '@storage/lightspeed/api-sample';

    /**
     * File name => endpoint. The same in every language: fetched once.
     */
    private const SHARED = [
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
    private const TRANSLATED = [
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
     */
    private const RELATIONS = [
        'products-images' => ['list' => 'products', 'path' => 'products/{id}/images'],
        'products-relations' => ['list' => 'products', 'path' => 'products/{id}/relations'],
        'products-metafields' => ['list' => 'products', 'path' => 'products/{id}/metafields'],
        'products-filtervalues' => ['list' => 'products', 'path' => 'products/{id}/filtervalues', 'requires' => 'filters'],
        'products-attributes' => ['list' => 'products', 'path' => 'products/{id}/attributes', 'translated' => true, 'if' => 'type'],
        'filters-values' => ['list' => 'filters', 'path' => 'filters/{id}/values', 'translated' => true],
        'orders-products' => ['list' => 'orders', 'path' => 'orders/{id}/products'],
        'invoices-items' => ['list' => 'invoices', 'path' => 'invoices/{id}/items'],
    ];

    /**
     * @var bool|int only the first items of every list (`--sample` = 25, `--sample=500`), in storage/lightspeed/api-sample
     */
    public bool|int|null $sample = null;

    /**
     * @var string[]|null active shop languages, default first
     */
    private ?array $languages = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'sample'];
    }

    public function beforeAction($action): bool
    {
        if ($this->sample !== null) {
            $this->module->store->dir = self::SAMPLE_DIR;
        } elseif ($action->id === 'index' && !$this->confirm('Full export of the live shop (read-only). This takes a few hours. Continue? (Use --sample for a small test set.)')) {
            return false;
        }

        $this->module->client->onWait = fn(int $seconds) => $this->stdout("Rate limit: waiting {$seconds}s\n", Console::FG_YELLOW);

        return parent::beforeAction($action);
    }

    /**
     * Shows the number of records per list.
     */
    public function actionCounts(): int
    {
        $rows = [];
        foreach ([...self::SHARED, ...self::TRANSLATED] as $name => $path) {
            try {
                $rows[] = [$name, $this->module->client->count($this->primaryLanguage(), $path)];
            } catch (ClientException $e) {
                $rows[] = [$name, 'n/a (' . $e->getResponse()->getStatusCode() . ')'];
            }
        }

        $this->table(['List', 'Count'], $rows);
        return ExitCode::OK;
    }

    /**
     * Downloads everything, or only the given file name (e.g. `products-images`).
     */
    public function actionIndex(?string $name = null): int
    {
        $all = [...array_keys(self::SHARED), ...array_keys(self::TRANSLATED), ...array_keys(self::RELATIONS)];
        if ($name !== null && !in_array($name, $all, true)) {
            $this->stderr("Unknown: $name. Use one of: " . implode(', ', $all) . "\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        foreach ($name ? [$name] : $all as $name) {
            $languages = $this->isTranslated($name) ? $this->languages() : [$this->primaryLanguage()];
            foreach ($languages as $language) {
                try {
                    $data = $this->fetch($name, $language);
                    if ($data === null) {
                        $this->stderr("[$language] $name: skipped, download " . self::RELATIONS[$name]['list'] . " first\n", Console::FG_RED);
                        continue;
                    }
                } catch (ClientException $e) {
                    $this->stderr("[$language] $name: not available (HTTP {$e->getResponse()->getStatusCode()})\n", Console::FG_RED);
                    continue;
                }

                $this->module->store->write($name, $data, $this->isTranslated($name) ? $language : null);
                $this->stdout("[$language] $name: " . count($data) . "\n");
            }
        }

        return ExitCode::OK;
    }

    /**
     * @return array|null null when the list of a relation is not downloaded
     */
    private function fetch(string $name, string $language): ?array
    {
        $client = $this->module->client;
        $max = match (true) {
            $this->sample === null => null,
            $this->sample === true => self::SAMPLE_SIZE,
            default => (int)$this->sample,
        };

        if ($path = self::SHARED[$name] ?? self::TRANSLATED[$name] ?? null) {
            return $client->all($language, $path, $max);
        }

        $relation = self::RELATIONS[$name];
        if (isset($relation['requires']) && !$this->stored($relation['requires'])) {
            return [];
        }

        $items = $this->stored($relation['list']);
        if ($items === null) {
            return null;
        }

        $data = [];
        foreach ($items as $item) {
            if (!isset($relation['if']) || $item[$relation['if']]) {
                $data[$item['id']] = $client->all($language, str_replace('{id}', (string)$item['id'], $relation['path']));
            }
        }

        return $data;
    }

    /**
     * A list that was downloaded before. Translated lists: the primary language (IDs are the same in every language).
     */
    private function stored(string $name): ?array
    {
        return $this->module->store->read($name, isset(self::TRANSLATED[$name]) ? $this->primaryLanguage() : null);
    }

    private function isTranslated(string $name): bool
    {
        return isset(self::TRANSLATED[$name]) || (self::RELATIONS[$name]['translated'] ?? false);
    }

    private function primaryLanguage(): string
    {
        return $this->languages()[0];
    }

    private function languages(): array
    {
        if ($this->languages === null) {
            $any = $this->module->client->getLanguages()[0] ?? throw new Exception('Set LIGHTSPEED_LANGUAGES in .env');
            $languages = array_filter($this->module->client->all($any, 'languages'), fn(array $language) => $language['isActive']);
            usort($languages, fn(array $a, array $b) => $b['isDefault'] <=> $a['isDefault']);
            $this->languages = array_column($languages, 'code');
        }

        return $this->languages;
    }
}
