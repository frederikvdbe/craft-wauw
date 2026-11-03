<?php

namespace modules\lightspeed\console\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\models\TaxCategory;
use craft\commerce\models\TaxRate;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\TaxRate as TaxRateRecord;
use craft\console\Controller;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets as AssetsField;
use craft\fields\Entries as EntriesField;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
use yii\base\Exception;
use yii\console\ExitCode;

/**
 * Creates the content model for the Lightspeed import. Safe to run again.
 */
class SetupController extends Controller
{
    public function actionIndex(): int
    {
        $this->setupStore();
        $this->setupTax();

        $volume = $this->volume('productImages', 'Product images', 'uploads/products');
        $lightspeedId = $this->field(PlainText::class, 'lightspeedId', 'Lightspeed ID');
        $this->addToLayout($volume->getFieldLayout(), [$lightspeedId]);
        Craft::$app->getVolumes()->saveVolume($volume) || $this->fail($volume);

        $fullTitle = $this->field(PlainText::class, 'fullTitle', 'Full title');
        $shortDescription = $this->field(PlainText::class, 'shortDescription', 'Short description', ['multiline' => true, 'initialRows' => 3]);
        $richText = $this->field(CkeditorField::class, 'richText', 'Text');

        $categories = $this->section('categories', 'Categories', 'category', Section::TYPE_STRUCTURE, 'categories/{slug}', [$fullTitle, $richText, $lightspeedId]);
        $brands = $this->section('brands', 'Brands', 'brand', Section::TYPE_CHANNEL, 'brands/{slug}', [$richText, $lightspeedId]);

        $productCategories = $this->field(EntriesField::class, 'productCategories', 'Categories', [
            'sources' => ["section:$categories->uid"],
        ]);
        $brand = $this->field(EntriesField::class, 'brand', 'Brand', [
            'sources' => ["section:$brands->uid"],
            'maxRelations' => 1,
        ]);
        $productImages = $this->field(AssetsField::class, 'productImages', 'Images', [
            'sources' => ["volume:$volume->uid"],
            'restrictFiles' => true,
            'allowedKinds' => ['image'],
            'defaultUploadLocationSource' => "volume:$volume->uid",
        ]);

        $this->productType([$fullTitle, $shortDescription, $richText, $productImages, $productCategories, $brand, $lightspeedId]);

        $this->stdout("Setup done\n");
        return ExitCode::OK;
    }

    private function setupStore(): void
    {
        $stores = Commerce::getInstance()->getStores();
        $store = $stores->getPrimaryStore();

        if ($store->getCurrency()->getCode() !== 'EUR') {
            $store->setCurrency('EUR');
            $stores->saveStore($store) || $this->fail($store);
        }
    }

    /**
     * Lightspeed has "Marge" (margin scheme, 0%, default) and 21% VAT. Prices include VAT.
     */
    private function setupTax(): void
    {
        $this->taxCategory('marginScheme', 'Margin scheme', true);
        $standard = $this->taxCategory('standard', 'Standard 21%', false);

        $taxRates = Commerce::getInstance()->getTaxRates();
        $storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;

        if ($taxRates->getAllTaxRates($storeId)->firstWhere('taxCategoryId', $standard->id)) {
            return;
        }

        $rate = new TaxRate([
            'storeId' => $storeId,
            'name' => 'VAT 21%',
            'rate' => 0.21,
            'include' => true,
            'taxable' => TaxRateRecord::TAXABLE_PRICE,
            'taxCategoryId' => $standard->id,
        ]);
        $taxRates->saveTaxRate($rate) || $this->fail($rate);
    }

    private function taxCategory(string $handle, string $name, bool $default): TaxCategory
    {
        $service = Commerce::getInstance()->getTaxCategories();
        $category = $service->getTaxCategoryByHandle($handle)
            ?? new TaxCategory(['handle' => $handle, 'name' => $name, 'default' => $default]);

        if (!$category->id) {
            $service->saveTaxCategory($category) || $this->fail($category);
        }

        return $category;
    }

    private function volume(string $handle, string $name, string $path): Volume
    {
        $fsService = Craft::$app->getFs();
        $fs = $fsService->getFilesystemByHandle($handle);

        if (!$fs) {
            $fs = $fsService->createFilesystem([
                'type' => Local::class,
                'name' => $name,
                'handle' => $handle,
                'hasUrls' => true,
                'url' => "@web/$path",
                'settings' => ['path' => "@webroot/$path"],
            ]);
            $fsService->saveFilesystem($fs) || $this->fail($fs);
        }

        $volume = Craft::$app->getVolumes()->getVolumeByHandle($handle);

        if (!$volume) {
            $volume = new Volume(['name' => $name, 'handle' => $handle]);
            $volume->setFsHandle($handle);
            Craft::$app->getVolumes()->saveVolume($volume) || $this->fail($volume);
        }

        return $volume;
    }

    private function field(string $type, string $handle, string $name, array $settings = []): FieldInterface
    {
        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByHandle($handle);

        if (!$field) {
            $field = $fields->createField(['type' => $type, 'handle' => $handle, 'name' => $name] + $settings);
            $fields->saveField($field) || $this->fail($field);
        }

        return $field;
    }

    /**
     * @param FieldInterface[] $fields
     */
    private function section(string $handle, string $name, string $entryTypeHandle, string $type, string $uriFormat, array $fields): Section
    {
        $entries = Craft::$app->getEntries();

        $entryType = $entries->getEntryTypeByHandle($entryTypeHandle)
            ?? new EntryType(['handle' => $entryTypeHandle, 'name' => ucfirst($entryTypeHandle)]);
        $this->addToLayout($entryType->getFieldLayout(), $fields);
        $entries->saveEntryType($entryType) || $this->fail($entryType);

        $section = $entries->getSectionByHandle($handle);
        if ($section) {
            return $section;
        }

        $section = new Section([
            'handle' => $handle,
            'name' => $name,
            'type' => $type,
        ]);
        $section->setEntryTypes([$entryType]);
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $section->setSiteSettings([
            $siteId => new Section_SiteSettings([
                'siteId' => $siteId,
                'hasUrls' => true,
                'uriFormat' => $uriFormat,
                'template' => "$handle/_entry",
            ]),
        ]);
        $entries->saveSection($section) || $this->fail($section);

        return $section;
    }

    /**
     * @param FieldInterface[] $fields
     */
    private function productType(array $fields): void
    {
        $productTypes = Commerce::getInstance()->getProductTypes();
        $productType = $productTypes->getProductTypeByHandle('products');

        if (!$productType) {
            $productType = new ProductType([
                'handle' => 'products',
                'name' => 'Products',
                'maxVariants' => 1,
                'hasVariantTitleField' => false,
                'variantTitleFormat' => '{product.title}',
            ]);
            $siteId = Craft::$app->getSites()->getPrimarySite()->id;
            $productType->setSiteSettings([
                $siteId => new ProductTypeSite([
                    'siteId' => $siteId,
                    'hasUrls' => true,
                    'uriFormat' => 'products/{slug}',
                    'template' => 'products/_entry',
                ]),
            ]);
        }

        $taxCategories = Commerce::getInstance()->getTaxCategories();
        $productType->setTaxCategories([
            $taxCategories->getTaxCategoryByHandle('marginScheme')->id,
            $taxCategories->getTaxCategoryByHandle('standard')->id,
        ]);

        $this->addToLayout($productType->getFieldLayout(), $fields);
        $productTypes->saveProductType($productType) || $this->fail($productType);
    }

    /**
     * Adds fields to the first tab; mandatory fields are added by setTabs().
     *
     * @param FieldInterface[] $fields
     */
    private function addToLayout(FieldLayout $layout, array $fields): void
    {
        $tabs = $layout->getTabs();
        $tab = $tabs[0] ?? new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $elements = $tab->getElements();

        foreach ($fields as $field) {
            if (!$layout->isFieldIncluded($field->handle)) {
                $elements[] = new CustomField($field);
            }
        }

        $tab->setElements($elements);
        $layout->setTabs($tabs ?: [$tab]);
    }

    private function fail(object $model): never
    {
        $errors = method_exists($model, 'getErrorSummary') ? implode(', ', $model->getErrorSummary(true)) : '';
        throw new Exception('Could not save ' . $model::class . ": $errors");
    }
}
