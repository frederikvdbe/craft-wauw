<?php

namespace modules\lightspeed\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use GuzzleHttp\Exception\ClientException;
use modules\lightspeed\Module;
use yii\console\ExitCode;

/**
 * Downloads Lightspeed data to local JSON files.
 *
 * @property Module $module
 */
class ExtractController extends Controller
{
    /**
     * File name => API list endpoint.
     */
    private const RESOURCES = [
        'languages' => 'languages',
        'taxes' => 'taxes',
        'categories' => 'categories',
        'categories-products' => 'categories/products',
        'brands' => 'brands',
        'products' => 'products',
        'variants' => 'variants',
        'tags' => 'tags',
        'tags-products' => 'tags/products',
        'textpages' => 'textpages',
        'blogs' => 'blogs',
        'customers' => 'customers',
        'orders' => 'orders',
        'reviews' => 'reviews',
        'redirects' => 'redirects',
    ];

    /**
     * File name => endpoint per product.
     */
    private const PRODUCT_RELATIONS = [
        'products-images' => 'products/{id}/images',
        'products-attributes' => 'products/{id}/attributes',
    ];

    /**
     * Shows the number of records per resource.
     */
    public function actionCounts(): int
    {
        $language = $this->module->client->getLanguages()[0];
        $rows = [];

        foreach (self::RESOURCES as $name => $path) {
            try {
                $rows[] = [$name, $this->module->client->count($language, $path)];
            } catch (ClientException $e) {
                $rows[] = [$name, 'n/a (' . $e->getResponse()->getStatusCode() . ')'];
            }
        }

        $this->table(['Resource', 'Count'], $rows);
        return ExitCode::OK;
    }

    /**
     * Downloads all resources, or only the given one, for every language.
     */
    public function actionIndex(?string $resource = null): int
    {
        $names = $resource ? [$resource] : [...array_keys(self::RESOURCES), ...array_keys(self::PRODUCT_RELATIONS)];

        foreach ($this->module->client->getLanguages() as $language) {
            foreach ($names as $name) {
                $data = match (true) {
                    isset(self::RESOURCES[$name]) => $this->module->client->all($language, self::RESOURCES[$name]),
                    isset(self::PRODUCT_RELATIONS[$name]) => $this->extractPerProduct($language, self::PRODUCT_RELATIONS[$name]),
                    default => null,
                };

                if ($data === null) {
                    $this->stderr("Unknown resource: $name\n", Console::FG_RED);
                    return ExitCode::USAGE;
                }

                $this->module->store->write($language, $name, $data);
                $this->stdout("[$language] $name: " . count($data) . "\n");
            }
        }

        return ExitCode::OK;
    }

    /**
     * Uses the already downloaded products, keyed by product ID.
     */
    private function extractPerProduct(string $language, string $pathTemplate): array
    {
        $products = $this->module->store->read($language, 'products');
        if (!$products) {
            $products = $this->module->client->all($language, 'products');
            $this->module->store->write($language, 'products', $products);
        }

        $data = [];
        foreach ($products as $product) {
            $path = str_replace('{id}', (string)$product['id'], $pathTemplate);
            $data[$product['id']] = $this->module->client->all($language, $path);
        }

        return $data;
    }
}
