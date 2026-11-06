<?php

namespace modules\lightspeed\console\controllers;

use Craft;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\commerce\elements\Order;
use craft\commerce\gateways\Manual;
use craft\commerce\models\OrderStatus;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\models\TaxCategory;
use craft\commerce\models\TaxRate;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\TaxRate as TaxRateRecord;
use craft\commerce\services\Orders;
use craft\console\Controller;
use craft\elements\User;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Assets as AssetsField;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\services\ProjectConfig;
use modules\lightspeed\Module;
use modules\lightspeed\services\CustomerImporter;
use modules\lightspeed\services\OrderImporter;
use yii\base\Exception;
use yii\console\ExitCode;

/**
 * Creates the content model for the Lightspeed import. Safe to run again.
 */
class SetupController extends Controller
{
    public function actionIndex(): int
    {
        $this->setupSites();
        $this->setupStore();
        $this->setupTax();

        $volume = $this->volume('productImages', 'Product images', 'uploads/products');
        $lightspeedId = $this->field(PlainText::class, 'lightspeedId', 'Lightspeed ID');
        $this->addToLayout($volume->getFieldLayout(), [$lightspeedId]);
        Craft::$app->getVolumes()->saveVolume($volume) || $this->fail($volume);

        $translatable = ['translationMethod' => Field::TRANSLATION_METHOD_SITE];
        $fullTitle = $this->field(PlainText::class, 'fullTitle', 'Full title', $translatable);
        $shortDescription = $this->field(PlainText::class, 'shortDescription', 'Short description', ['multiline' => true, 'initialRows' => 3] + $translatable);
        $richText = $this->field(CkeditorField::class, 'richText', 'Text', $translatable);

        $categories = $this->section('categories', 'Categories', 'category', Section::TYPE_STRUCTURE, 'categories/{slug}', [$fullTitle, $richText, $lightspeedId]);
        $brandVolume = $this->volume('brandImages', 'Brand images', 'uploads/brands');
        $this->addToLayout($brandVolume->getFieldLayout(), [$lightspeedId]);
        Craft::$app->getVolumes()->saveVolume($brandVolume) || $this->fail($brandVolume);
        $brandImage = $this->field(AssetsField::class, 'brandImage', 'Image', [
            'restrictLocation' => true,
            'restrictedLocationSource' => "volume:$brandVolume->uid",
            'restrictFiles' => true,
            'allowedKinds' => ['image'],
            'maxRelations' => 1,
        ]);
        $brands = $this->section('brands', 'Brands', 'brand', Section::TYPE_CHANNEL, 'brands/{slug}', [$brandImage, $richText, $lightspeedId]);

        $productCategories = $this->field(EntriesField::class, 'productCategories', 'Categories', [
            'sources' => ["section:$categories->uid"],
        ]);
        $brand = $this->field(EntriesField::class, 'brand', 'Brand', [
            'sources' => ["section:$brands->uid"],
            'maxRelations' => 1,
        ]);
        // One folder per product: Craft moves related assets into it on every product save.
        // ID, not slug: the slug differs per site, so the folder would change on every site save.
        $productImages = $this->field(AssetsField::class, 'productImages', 'Images', [
            'restrictLocation' => true,
            'restrictedLocationSource' => "volume:$volume->uid",
            'restrictedLocationSubpath' => '{id}',
            'restrictFiles' => true,
            'allowedKinds' => ['image'],
        ]);

        $featured = $this->field(Lightswitch::class, 'featured', 'Featured (TOP 50)');
        $vipPreview = $this->field(Lightswitch::class, 'vipPreview', 'VIP preview');
        $priceOnRequest = $this->field(Lightswitch::class, 'priceOnRequest', 'Price on request');
        $video = $this->field(Link::class, 'video', 'Video', ['types' => ['url']]);
        $viewerUrl = $this->field(Link::class, 'viewerUrl', '3D / interactive viewer', ['types' => ['url']]);
        $ean = $this->field(PlainText::class, 'ean', 'EAN');

        $this->productType(
            [$fullTitle, $shortDescription, $richText, $productImages, $video, $viewerUrl, $productCategories, $brand, $featured, $vipPreview, $priceOnRequest, $lightspeedId],
            [$ean, $lightspeedId],
        );

        $this->setupCustomers($lightspeedId);
        $this->setupOrders($lightspeedId);

        $this->stdout("Setup done\n");
        return ExitCode::OK;
    }

    /**
     * Customer data from Lightspeed: extra fields on users and on addresses.
     */
    private function setupCustomers(FieldInterface $lightspeedId): void
    {
        $customerType = $this->field(Dropdown::class, 'customerType', 'Customer type', [
            'options' => $this->dropdownOptions(['' => '', 'private' => 'Private', 'company' => 'Company']),
        ]);
        $cocNumber = $this->field(PlainText::class, 'cocNumber', 'Company number');
        $birthdate = $this->field(Date::class, 'birthdate', 'Birthdate', ['showDate' => true, 'showTime' => false]);
        $gender = $this->field(Dropdown::class, 'gender', 'Gender', [
            'options' => $this->dropdownOptions(['' => '', 'male' => 'Male', 'female' => 'Female']),
        ]);
        $emailConfirmed = $this->field(Lightswitch::class, 'emailConfirmed', 'Email confirmed in Lightspeed');

        $userGroups = Craft::$app->getUserGroups();
        foreach (CustomerImporter::GROUPS as $handle => $name) {
            $group = $userGroups->getGroupByHandle($handle) ?? new UserGroup(['handle' => $handle]);
            $group->name = $name;
            $userGroups->saveGroup($group) || $this->fail($group);
        }
        // New sign-ups on the site are VIP members.
        Craft::$app->getProjectConfig()->set(ProjectConfig::PATH_USERS . '.defaultGroup', $userGroups->getGroupByHandle('vipMembers')->uid);

        $users = Craft::$app->getUsers();
        $userLayout = Craft::$app->getFields()->getLayoutByType(User::class);
        $this->addToLayout($userLayout, [$customerType, $cocNumber, $birthdate, $gender, $emailConfirmed, $lightspeedId]);
        $users->saveLayout($userLayout) || $this->fail($userLayout);

        $phone = $this->field(PlainText::class, 'phone', 'Phone');
        $mobile = $this->field(PlainText::class, 'mobile', 'Mobile');

        $addresses = Craft::$app->getAddresses();
        $addressLayout = $addresses->getFieldLayout();
        $this->addToLayout($addressLayout, [$phone, $mobile]);
        $addresses->saveFieldLayout($addressLayout) || $this->fail($addressLayout);
    }

    /**
     * Old Lightspeed orders: statuses, a gateway for their payments, and the order data Commerce has no place for.
     */
    private function setupOrders(FieldInterface $lightspeedId): void
    {
        $commerce = Commerce::getInstance();
        $storeId = $commerce->getStores()->getPrimaryStore()->id;
        $statuses = $commerce->getOrderStatuses();

        foreach (['new' => ['New', 'green'], 'shipped' => ['Shipped', 'blue'], 'cancelled' => ['Cancelled', 'red']] as $handle => [$name, $color]) {
            if (!$statuses->getOrderStatusByHandle($handle, $storeId)) {
                $status = new OrderStatus(['storeId' => $storeId, 'handle' => $handle, 'name' => $name, 'color' => $color]);
                $statuses->saveOrderStatus($status) || $this->fail($status);
            }
        }

        $gateways = $commerce->getGateways();
        if (!$gateways->getGatewayByHandle(OrderImporter::GATEWAY)) {
            $gateway = $gateways->createGateway([
                'type' => Manual::class,
                'name' => 'Lightspeed (old orders)',
                'handle' => OrderImporter::GATEWAY,
                'isFrontendEnabled' => false,
            ]);
            $gateways->saveGateway($gateway) || $this->fail($gateway);
        }

        $fields = [
            $this->field(PlainText::class, 'invoiceNumbers', 'Invoice numbers'),
            $this->field(PlainText::class, 'creditNotes', 'Credit notes'),
            $this->field(PlainText::class, 'paymentMethod', 'Payment method'),
            $this->field(PlainText::class, 'discountCode', 'Discount code'),
            $this->field(PlainText::class, 'customerComment', 'Customer comment', ['multiline' => true]),
            $this->field(PlainText::class, 'memo', 'Memo', ['multiline' => true]),
            $lightspeedId,
        ];
        $layout = Craft::$app->getFields()->getLayoutByType(Order::class);
        $this->addToLayout($layout, $fields);
        $layout->uid ??= StringHelper::UUID();
        Craft::$app->getProjectConfig()->set(Orders::CONFIG_FIELDLAYOUT_KEY, [$layout->uid => $layout->getConfig()]);
    }

    /**
     * @param array<string, string> $options value => label
     */
    private function dropdownOptions(array $options): array
    {
        return array_map(
            fn(string $value, string $label) => ['label' => $label, 'value' => $value, 'default' => false],
            array_keys($options),
            $options,
        );
    }

    /**
     * Creates a site per language; the first one in Module::SITES is the primary site.
     */
    private function setupSites(): void
    {
        $sites = Craft::$app->getSites();
        $groupId = $sites->getPrimarySite()->groupId;
        $sortOrder = 1;

        foreach (Module::SITES as $handle => $config) {
            $site = $sites->getSiteByHandle($handle) ?? new Site(['groupId' => $groupId, 'handle' => $handle]);
            $site->primary = $sortOrder === 1;
            $site->setName($config['name']);
            $site->setBaseUrl($config['baseUrl']);
            $site->language = $config['language'];
            $site->hasUrls = true;
            $site->sortOrder = $sortOrder++;
            $sites->saveSite($site) || $this->fail($site);
        }
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

        if ($field) {
            Craft::configure($field, $settings);
        } else {
            $field = $fields->createField(['type' => $type, 'handle' => $handle, 'name' => $name] + $settings);
        }

        $fields->saveField($field) || $this->fail($field);
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
        // Craft sets hasTitleField from the layout, so the title field must be in it.
        $this->addToLayout($entryType->getFieldLayout(), [new EntryTitleField(), ...$fields]);
        $entries->saveEntryType($entryType) || $this->fail($entryType);

        $section = $entries->getSectionByHandle($handle) ?? new Section([
            'handle' => $handle,
            'name' => $name,
            'type' => $type,
        ]);
        $section->setEntryTypes([$entryType]);

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $siteSettings[$siteId] = new Section_SiteSettings([
                'siteId' => $siteId,
                'hasUrls' => true,
                'uriFormat' => $uriFormat,
                'template' => "$handle/_entry",
            ]);
        }
        $section->setSiteSettings($siteSettings);
        $entries->saveSection($section) || $this->fail($section);

        return $section;
    }

    /**
     * @param FieldInterface[] $fields
     * @param FieldInterface[] $variantFields
     */
    private function productType(array $fields, array $variantFields): void
    {
        $productTypes = Commerce::getInstance()->getProductTypes();
        $productType = $productTypes->getProductTypeByHandle('products')
            ?? new ProductType(['handle' => 'products', 'name' => 'Products']);

        // Most products are one-off items with 1 variant; a few have options (colour, set of 2).
        $productType->maxVariants = null;
        $productType->hasVariantTitleField = true;
        $productType->hasDimensions = true;

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $siteSettings[$siteId] = new ProductTypeSite([
                'siteId' => $siteId,
                'hasUrls' => true,
                'uriFormat' => 'products/{slug}',
                'template' => 'products/_entry',
            ]);
        }
        $productType->setSiteSettings($siteSettings);

        $taxCategories = Commerce::getInstance()->getTaxCategories();
        $productType->setTaxCategories([
            $taxCategories->getTaxCategoryByHandle('marginScheme')->id,
            $taxCategories->getTaxCategoryByHandle('standard')->id,
        ]);

        $this->addToLayout($productType->getFieldLayout(), $fields);
        $this->addToLayout($productType->getVariantFieldLayout(), $variantFields);
        $productTypes->saveProductType($productType) || $this->fail($productType);
    }

    /**
     * Adds fields to the first tab (native fields on top); mandatory fields are added by setTabs().
     *
     * @param array<FieldInterface|BaseField> $fields custom fields or native layout fields
     */
    private function addToLayout(FieldLayout $layout, array $fields): void
    {
        $tabs = $layout->getTabs();
        $tab = $tabs[0] ?? new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $elements = $tab->getElements();

        foreach ($fields as $field) {
            $isNative = $field instanceof BaseField;
            $element = $isNative ? $field : new CustomField($field);
            if ($layout->isFieldIncluded($element->attribute())) {
                continue;
            }
            $isNative ? array_unshift($elements, $element) : $elements[] = $element;
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
