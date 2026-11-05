<?php

namespace modules\lightspeed\console\controllers;

use Craft;
use craft\console\Controller;
use modules\lightspeed\services\Importer;
use modules\lightspeed\services\ProductExport;
use yii\console\ExitCode;

/**
 * Imports the Lightspeed product CSV export into Craft. Run `lightspeed/setup` first.
 */
class ImportController extends Controller
{
    public string $file = '@storage/lightspeed/products.csv';

    /**
     * @var int|null only import the first X products (for testing)
     */
    public ?int $limit = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'file', 'limit'];
    }

    /**
     * Imports everything in dependency order.
     */
    public function actionIndex(): int
    {
        return $this->actionCategories() ?: $this->actionBrands() ?: $this->actionProducts();
    }

    public function actionCategories(): int
    {
        $this->stdout('Categories: ' . $this->importer()->importCategories() . "\n");
        return ExitCode::OK;
    }

    public function actionBrands(): int
    {
        $this->stdout('Brands: ' . $this->importer()->importBrands() . "\n");
        return ExitCode::OK;
    }

    public function actionProducts(): int
    {
        $importer = $this->importer();
        $count = $importer->importProducts(function(int $done, int $total) {
            if ($done % 50 === 0) {
                $this->stdout("$done / $total\n");
            }
        });

        $this->stdout("Products: $count\n");
        foreach ($importer->failedImages as $url) {
            $this->stderr("Image not downloaded: $url\n");
        }

        return ExitCode::OK;
    }

    private function importer(): Importer
    {
        $products = ProductExport::read(Craft::getAlias($this->file));
        return new Importer($this->limit ? array_slice($products, 0, $this->limit, true) : $products);
    }
}
