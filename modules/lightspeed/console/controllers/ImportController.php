<?php

namespace modules\lightspeed\console\controllers;

use craft\console\Controller;
use modules\lightspeed\Module;
use modules\lightspeed\services\Importer;
use yii\console\ExitCode;

/**
 * Imports the downloaded Lightspeed JSON into Craft. Run `lightspeed/extract` first.
 *
 * @property Module $module
 */
class ImportController extends Controller
{
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
        $this->stdout('Products: ' . $this->importer()->importProducts() . "\n");
        return ExitCode::OK;
    }

    private function importer(): Importer
    {
        return new Importer($this->module->store, $this->module->client->getLanguages()[0]);
    }
}
