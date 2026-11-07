<?php

namespace modules\lightspeed\console\controllers;

use Craft;
use craft\base\FieldInterface;
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
use modules\lightspeed\services\ProductImporter;
use yii\base\Exception;
use yii\console\ExitCode;

/**
 * Creates the content model for the Lightspeed import. Safe to run again.
 */
class SetupController extends Controller
{
    private const PRODUCT_FOLDER = "{{ object.id }}-{{ (object.site.primary ? object : object.getLocalized().site(craft.app.sites.primarySite.handle).status(null).one() ?? object).slug }}";

    public function actionIndex(): int
    {
        $this->setupSites();
        $this->setupStore();
        $this->setupTax();
        $this->setupBaseFields();

        $volume = $this->volume('productImages', 'Product images', 'uploads/products');
        $lightspeedId = $this->field(PlainText::class, 'lightspeedIdField', 'Lightspeed ID');
        $this->addToLayout($volume->getFieldLayout(), [$lightspeedId]);
        Craft::$app->getVolumes()->saveVolume($volume) || $this->fail($volume);

        $fullTitle = $this->instance('singleLineTextField', 'fullTitleField', 'Full title');
        $shortDescription = $this->instance('multilineTextField', 'shortDescriptionField', 'Short description');
        $richText = $this->instance('richTextField', 'richTextField', 'Text');

        $categories = $this->section('productCategories', 'Product categories', 'category', Section::TYPE_STRUCTURE, "{parent ? parent.uri ~ '/' : ''}{slug}", [$fullTitle, $shortDescription, $richText, $lightspeedId]);
        $brandVolume = $this->volume('brandImages', 'Brand images', 'uploads/brands');
        $this->addToLayout($brandVolume->getFieldLayout(), [$lightspeedId]);
        Craft::$app->getVolumes()->saveVolume($brandVolume) || $this->fail($brandVolume);
        $brandImage = $this->field(AssetsField::class, 'brandImageField', 'Image', [
            'restrictLocation' => true,
            'restrictedLocationSource' => "volume:$brandVolume->uid",
            'restrictFiles' => true,
            'allowedKinds' => ['image'],
            'maxRelations' => 1,
        ]);
        $brands = $this->section('brands', 'Brands', 'brand', Section::TYPE_CHANNEL, 'brands/{slug}', [$brandImage, $richText, $lightspeedId]);

        $productCategories = $this->field(EntriesField::class, 'productCategoriesField', 'Categories', [
            'sources' => ["section:$categories->uid"],
        ]);
        $brand = $this->field(EntriesField::class, 'brandField', 'Brand', [
            'sources' => ["section:$brands->uid"],
            'maxRelations' => 1,
        ]);
        // One folder per product (`{Craft ID}-{slug}`): Craft moves related assets into it on every product save.
        // Always the primary site slug: the slug differs per site, so the folder would change on every site save.
        $productImages = $this->field(AssetsField::class, 'productImagesField', 'Images', [
            'restrictLocation' => true,
            'restrictedLocationSource' => "volume:$volume->uid",
            'restrictedLocationSubpath' => self::PRODUCT_FOLDER,
            'restrictFiles' => true,
            'allowedKinds' => ['image'],
        ]);

        $priceOnRequest = $this->instance('lightswitchField', 'priceOnRequestField', 'Price on request');
        $video = $this->instance('urlField', 'videoField', 'Video');
        $viewerUrl = $this->instance('urlField', 'viewerUrlField', '3D / interactive viewer');
        $ean = $this->instance('singleLineTextField', 'eanField', 'EAN');

        $this->productType(
            [$fullTitle, $shortDescription, $richText, $productImages, $video, $viewerUrl, $productCategories, $brand, $priceOnRequest, $lightspeedId],
            [$ean, $lightspeedId],
        );

        $this->setupCustomers($lightspeedId);
        $this->setupOrders($lightspeedId);

        $this->stdout("Setup done\n");
        return ExitCode::OK;
    }

    /**
     * Generic fields next to the boilerplate ones (singleLineTextField, …), reused in layouts with their own handle.
     */
    private function setupBaseFields(): void
    {
        $this->field(Lightswitch::class, 'lightswitchField', 'Lightswitch');
        $this->field(Date::class, 'dateField', 'Date', ['showDate' => true, 'showTime' => false]);
    }

    /**
     * A base field in a layout under its own handle and label.
     */
    private function instance(string $baseHandle, string $handle, string $label): CustomField
    {
        $field = Craft::$app->getFields()->getFieldByHandle($baseHandle)
            ?? throw new Exception("Base field $baseHandle is missing");

        return new CustomField($field, ['handle' => $handle, 'label' => $label]);
    }

    /**
     * Customer data from Lightspeed: extra fields on users and on addresses.
     */
    private function setupCustomers(FieldInterface $lightspeedId): void
    {
        $customerType = $this->field(Dropdown::class, 'customerTypeField', 'Customer type', [
            'options' => $this->dropdownOptions(['' => '', 'private' => 'Private', 'company' => 'Company']),
        ]);
        $cocNumber = $this->instance('singleLineTextField', 'cocNumberField', 'Company number');
        $birthdate = $this->instance('dateField', 'birthdateField', 'Birthdate');
        $gender = $this->field(Dropdown::class, 'genderField', 'Gender', [
            'options' => $this->dropdownOptions(['' => '', 'male' => 'Male', 'female' => 'Female']),
        ]);
        $emailConfirmed = $this->instance('lightswitchField', 'emailConfirmedField', 'Email confirmed in Lightspeed');

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

        $phone = $this->instance('singleLineTextField', 'phoneField', 'Phone');
        $mobile = $this->instance('singleLineTextField', 'mobileField', 'Mobile');

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

        // The Lightspeed statuses, 1:1. The first one is the default.
        $sortOrder = 0;
        foreach (OrderImporter::STATUSES as $handle => [$name, $color]) {
            $status = $statuses->getOrderStatusByHandle($handle, $storeId)
                ?? new OrderStatus(['storeId' => $storeId, 'handle' => $handle]);
            $status->name = $name;
            $status->color = $color;
            $status->sortOrder = ++$sortOrder;
            $status->default = $sortOrder === 1;
            $statuses->saveOrderStatus($status) || $this->fail($status);
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
            $this->field(Dropdown::class, 'shipmentStatusField', 'Shipment status', [
                'options' => $this->dropdownOptions(['' => '', ...OrderImporter::SHIPMENT_STATUSES]),
            ]),
            $this->instance('singleLineTextField', 'invoiceNumbersField', 'Invoice numbers'),
            $this->instance('singleLineTextField', 'creditNotesField', 'Credit notes'),
            $this->instance('singleLineTextField', 'paymentMethodField', 'Payment method'),
            $this->instance('singleLineTextField', 'discountCodeField', 'Discount code'),
            $this->instance('multilineTextField', 'customerCommentField', 'Customer comment'),
            $this->instance('multilineTextField', 'memoField', 'Memo'),
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
     * Lightspeed tax rates: margin scheme (0%, default), reduced (6%) and standard (21%), included in the price.
     */
    private function setupTax(): void
    {
        $this->taxCategory('marginScheme', 'Margin scheme', true);
        $this->taxRate($this->taxCategory('reduced', 'Reduced 6%', false), 'VAT 6%', 0.06);
        $this->taxRate($this->taxCategory('standard', 'Standard 21%', false), 'VAT 21%', 0.21);
    }

    private function taxRate(TaxCategory $category, string $name, float $rate): void
    {
        $taxRates = Commerce::getInstance()->getTaxRates();
        $storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;

        if ($taxRates->getAllTaxRates($storeId)->firstWhere('taxCategoryId', $category->id)) {
            return;
        }

        $taxRate = new TaxRate([
            'storeId' => $storeId,
            'name' => $name,
            'rate' => $rate,
            'include' => true,
            'taxable' => TaxRateRecord::TAXABLE_PRICE,
            'taxCategoryId' => $category->id,
        ]);
        $taxRates->saveTaxRate($taxRate) || $this->fail($taxRate);
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
     * @param array<FieldInterface|BaseField> $fields
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
     * @param array<FieldInterface|BaseField> $fields
     * @param array<FieldInterface|BaseField> $variantFields
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
        $productType->setTaxCategories(array_map(
            fn(string $handle) => $taxCategories->getTaxCategoryByHandle($handle)->id,
            array_values(ProductImporter::TAX_CATEGORIES),
        ));

        $this->addToLayout($productType->getFieldLayout(), $fields);
        $this->addToLayout($productType->getVariantFieldLayout(), $variantFields);
        $productTypes->saveProductType($productType) || $this->fail($productType);
    }

    /**
     * Adds fields to the first tab (native fields on top); mandatory fields are added by setTabs().
     *
     * @param array<FieldInterface|BaseField> $fields custom fields, base field instances or native layout fields
     */
    private function addToLayout(FieldLayout $layout, array $fields): void
    {
        $tabs = $layout->getTabs();
        $tab = $tabs[0] ?? new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $elements = $tab->getElements();

        foreach ($fields as $field) {
            $isNative = $field instanceof BaseField && !$field instanceof CustomField;
            // Layout elements are cloned: the same instance can go in several layouts.
            $element = $field instanceof BaseField ? clone $field : new CustomField($field);
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
