<?php

namespace modules\lightspeed\console\controllers;

use craft\console\Controller;
use modules\lightspeed\Module;
use modules\lightspeed\services\ApiData;
use modules\lightspeed\services\CustomerImporter;
use modules\lightspeed\services\OrderImporter;
use modules\lightspeed\services\ProductImporter;
use modules\lightspeed\services\Sample;
use yii\console\ExitCode;

/**
 * Imports the API export (`lightspeed/extract`) into Craft. Run `lightspeed/setup` first.
 * Safe to run again.
 *
 * @property Module $module
 */
class ImportController extends Controller
{
    /**
     * @var bool only import a small, fixed sample (for development)
     */
    public bool $sample = false;

    private ?ApiData $data = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'sample'];
    }

    public function beforeAction($action): bool
    {
        if (!$this->sample && !$this->confirm('Full import: everything in the API export. Continue? (Use --sample for a small test set.)')) {
            return false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Imports everything in dependency order.
     */
    public function actionIndex(): int
    {
        return $this->actionCategories()
            ?: $this->actionBrands()
            ?: $this->actionProducts()
            ?: $this->actionCustomers()
            ?: $this->actionOrders();
    }

    public function actionCategories(): int
    {
        $this->stdout('Categories: ' . (new ProductImporter($this->data()))->importCategories() . "\n");
        return ExitCode::OK;
    }

    public function actionBrands(): int
    {
        $this->stdout('Brands: ' . (new ProductImporter($this->data()))->importBrands() . "\n");
        return ExitCode::OK;
    }

    public function actionProducts(): int
    {
        $importer = new ProductImporter($this->data());
        $count = $importer->importProducts($this->progress(...));

        $this->stdout("Products: $count\n");
        $this->warnings(array_map(fn(string $url) => "Image not downloaded: $url", $importer->failedImages));

        return ExitCode::OK;
    }

    public function actionCustomers(): int
    {
        $importer = new CustomerImporter($this->data());
        $this->stdout('Customers: ' . $importer->import($this->progress(...)) . "\n");
        $this->warnings($importer->warnings);

        return ExitCode::OK;
    }

    public function actionOrders(): int
    {
        $importer = new OrderImporter($this->data());
        $this->stdout('New orders: ' . $importer->import($this->progress(...)) . "\n");
        $this->warnings($importer->warnings);

        return ExitCode::OK;
    }

    private function data(): ApiData
    {
        if ($this->data === null) {
            $this->data = ApiData::load($this->module->store);
            if ($this->sample) {
                $this->data = (new Sample($this->data))->apply();
            }
        }

        return $this->data;
    }

    private function progress(int $done, int $total): void
    {
        if ($done % 100 === 0) {
            $this->stdout("$done / $total\n");
        }
    }

    private function warnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->stderr("$warning\n");
        }
    }
}
