<?php

namespace modules\lightspeed\services;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\FileHelper;
use GuzzleHttp\Exception\GuzzleException;
use modules\lightspeed\Module;
use yii\base\Component;

/**
 * Imports the Lightspeed product CSV export into Craft, for every site. Safe to run again:
 * products and variants are matched on `lightspeedId`, categories and brands on their primary-site title.
 */
class ProductImporter extends Component
{
    use SavesElements;

    private const TAX_CATEGORIES = [
        '0' => 'marginScheme',
        '21' => 'standard',
    ];

    /**
     * Lightspeed has no "price on request" or "sold" flag: the shop sets the price to 0–2 instead.
     */
    private const PRICE_HACK_MAX = 2;

    private const COLLECTION_CATEGORY = 'collection in stock';
    private const FEATURED_CATEGORY = 'TOP 50';
    private const VIP_PREVIEW_CATEGORY = 'PREVIEW';

    /**
     * @var string[] image URLs that could not be downloaded
     */
    public array $failedImages = [];

    /**
     * @param array<string, array<int, array<string, string>>> $products CSV rows grouped by product
     * @param array<string, array> $brands API brands per site handle
     */
    public function __construct(
        private readonly array $products,
        private readonly array $brands = [],
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function importCategories(): int
    {
        $rows = [];
        foreach ($this->products as [$row]) {
            if ($row['NL_Category_1'] === self::COLLECTION_CATEGORY && $row['NL_Category_2'] !== '') {
                $rows[$this->translated($row, $this->primaryLang(), 'Category_2')] ??= $row;
            }
        }

        foreach ($rows as $title => $row) {
            $entry = $this->findEntryByTitle('categories', $title) ?? $this->newEntry('categories', 'category');
            $this->saveForAllSites($entry, function(Entry $entry, string $lang) use ($row) {
                $entry->title = $this->translated($row, $lang, 'Category_2');
                $entry->slug = ElementHelper::generateSlug($entry->title);
            });
        }

        return count($rows);
    }

    /**
     * From the API: the product CSV only has the brand name.
     */
    public function importBrands(): int
    {
        $translations = array_map(fn(array $brands) => array_column($brands, null, 'id'), $this->brands);
        $groups = $this->brandGroups();

        foreach ($groups as $group) {
            $brand = $group[0];
            $image = $brand['image'] ? $this->findOrDownloadImage($brand['image']['src'], 'brandImages') : null;
            $entry = $this->findEntry('brands', $brand['id'], $brand['title']) ?? $this->newEntry('brands', 'brand');

            $this->saveForAllSites($entry, function(Entry $entry, string $lang) use ($brand, $group, $translations, $image) {
                $translated = $translations[strtolower($lang)][$brand['id']] ?? $brand;
                $entry->title = $translated['title'];
                $entry->slug = $translated['url'];
                $entry->enabled = in_array(true, array_column($group, 'isVisible'), true);
                $entry->setFieldValues([
                    'lightspeedId' => (string)$brand['id'],
                    'brandImage' => $image ? [$image->id] : [],
                    'richText' => $translated['content'] ?: null,
                ]);
            });

            foreach (array_slice($group, 1) as $duplicate) {
                $entry = Entry::find()->section('brands')->lightspeedId($duplicate['id'])->status(null)->one();
                $entry && Craft::$app->getElements()->deleteElement($entry, true);
            }
        }

        return count($groups);
    }

    /**
     * Lightspeed has duplicate brands (e.g. "Fontana Arte" twice): 1 entry per title.
     * The first brand of a group is kept: the one with an image, else the oldest.
     *
     * @return array<string, array[]> title => brands
     */
    private function brandGroups(): array
    {
        $brands = $this->brands[$this->primarySite()];
        usort($brands, fn(array $a, array $b) => [!$a['image'], $a['id']] <=> [!$b['image'], $b['id']]);

        $groups = [];
        foreach ($brands as $brand) {
            $groups[mb_strtolower(trim($brand['title']))][] = $brand;
        }

        return $groups;
    }

    /**
     * @param callable(int $done, int $total): void|null $onProgress
     */
    public function importProducts(?callable $onProgress = null): int
    {
        $productTypeId = Commerce::getInstance()->getProductTypes()->getProductTypeByHandle('products')->id;
        $done = 0;

        foreach ($this->products as $id => $rows) {
            $product = Product::find()->type('products')->site($this->primarySite())->lightspeedId($id)->status(null)->one()
                ?? new Product(['typeId' => $productTypeId]);
            $fieldValues = $this->productFieldValues($rows);

            $this->saveForAllSites($product, function(Product $product, string $lang) use ($rows, $fieldValues) {
                $this->fillProduct($product, $rows, $lang, $fieldValues);
            });
            $this->updateStock($product, $rows);

            $onProgress && $onProgress(++$done, count($this->products));
        }

        return count($this->products);
    }

    /**
     * Field values that are the same on every site.
     */
    private function productFieldValues(array $rows): array
    {
        $row = $rows[0];
        $media = $this->media($row);

        return [
            'lightspeedId' => $row['Internal_ID'],
            'brand' => $this->entryIds('brands', $row['Brand']),
            'productCategories' => $row['NL_Category_1'] === self::COLLECTION_CATEGORY
                ? $this->entryIds('categories', $row['NL_Category_2'] !== '' ? $this->translated($row, $this->primaryLang(), 'Category_2') : '')
                : [],
            'featured' => $row['NL_Category_1'] === self::FEATURED_CATEGORY,
            'vipPreview' => $row['NL_Category_1'] === self::VIP_PREVIEW_CATEGORY,
            'priceOnRequest' => (float)$row['Price'] <= self::PRICE_HACK_MAX,
            'productImages' => array_values(array_filter(array_map(fn(string $url) => $this->findOrDownloadImage($url)?->id, $media['images']))),
            'video' => $media['video'] ? ['type' => 'url', 'value' => $media['video']] : null,
            'viewerUrl' => $media['viewer'] ? ['type' => 'url', 'value' => $media['viewer']] : null,
        ];
    }

    private function fillProduct(Product $product, array $rows, string $lang, array $fieldValues): void
    {
        $row = $rows[0];
        $product->title = $this->translated($row, $lang, 'Title_Short');
        $product->slug = $this->translated($row, $lang, 'URL');
        $product->enabled = $row['Visible'] === 'Y';
        $product->setEnabledForSite($row["{$lang}_Title_Short"] !== '');
        $product->setFieldValues($fieldValues + [
            'fullTitle' => $this->translated($row, $lang, 'Title_Long'),
            'shortDescription' => $this->translated($row, $lang, 'Description_Short'),
            'richText' => $this->translated($row, $lang, 'Description_Long') ?: null,
        ]);

        $existing = [];
        foreach ($product->getVariants(true) as $variant) {
            $existing[$variant->getFieldValue('lightspeedId')] = $variant;
        }

        $variants = [];
        foreach ($rows as $i => $variantRow) {
            $variant = $existing[$this->variantId($variantRow)] ?? new Variant();
            $variantTitle = $this->translated($variantRow, $lang, 'Variant');
            $variant->title = strcasecmp($variantTitle, 'default') === 0 ? $product->title : $variantTitle;
            $variant->isDefault = $i === 0;
            $this->fillVariant($variant, $variantRow);
            $variants[] = $variant;
        }

        $product->setVariants($variants);
        // Commerce only saves variants when this attribute is dirty.
        $product->setDirtyAttributes(['variants']);
    }

    private function fillVariant(Variant $variant, array $row): void
    {
        $price = (float)$row['Price'];
        $oldPrice = (float)$row['Price_Old'];
        $onSale = $price > self::PRICE_HACK_MAX && $oldPrice > $price;

        $variant->setSku($row['SKU'] ?: "LS-{$this->variantId($row)}");
        // With the price hack, the real price is in the old price.
        $variant->setBasePrice($price <= self::PRICE_HACK_MAX || $onSale ? $oldPrice : $price);
        $variant->setBasePromotionalPrice($onSale ? $price : null);
        $variant->inventoryTracked = $row['Stock_Track'] === 'Y';
        $variant->length = (float)$row['Size_X_Value'] ?: null;
        $variant->width = (float)$row['Size_Y_Value'] ?: null;
        $variant->height = (float)$row['Size_Z_Value'] ?: null;
        $variant->weight = (float)$row['Weight_Value'] ?: null;
        $variant->setFieldValues([
            'lightspeedId' => $this->variantId($row),
            'ean' => $row['EAN'] ?: null,
        ]);

        $taxHandle = self::TAX_CATEGORIES[$row['Tax']] ?? 'marginScheme';
        $variant->setTaxCategoryId(Commerce::getInstance()->getTaxCategories()->getTaxCategoryByHandle($taxHandle)->id);
    }

    /**
     * Inventory items only exist after the first save, so stock is set afterwards.
     */
    private function updateStock(Product $product, array $rows): void
    {
        $inventory = Commerce::getInstance()->getInventory();
        $variants = Variant::find()->productId($product->id)->site($this->primarySite())->status(null)->all();

        foreach ($rows as $row) {
            foreach ($variants as $variant) {
                if ($variant->getFieldValue('lightspeedId') === $this->variantId($row)) {
                    $inventory->updatePurchasableInventoryLevel($variant, max(0, (int)$row['Stock_Level']));
                }
            }
        }
    }

    /**
     * Sorts the product images and the custom data fields (Data_01–03) into images, a video and an interactive viewer.
     *
     * @return array{images: string[], video: ?string, viewer: ?string}
     */
    private function media(array $row): array
    {
        $images = array_filter(array_map('trim', explode(',', $row['Images'])));
        $video = $viewer = null;

        // Data_02 first: it is the dedicated viewer field.
        foreach ([$row['Data_02'], $row['Data_01'], $row['Data_03']] as $url) {
            if ($url === '') {
                continue;
            }
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $path)) {
                $images[] = $url;
            } elseif (preg_match('/\.mp4$/i', $path) || preg_match('/youtu\.?be/', $url)) {
                $video ??= $url;
            } else {
                $viewer ??= $url;
            }
        }

        return ['images' => array_values(array_unique($images)), 'video' => $video, 'viewer' => $viewer];
    }

    private function findOrDownloadImage(string $url, string $volumeHandle = 'productImages'): ?Asset
    {
        // Lightspeed CDN URLs contain a file ID; other hosts get a hash of the URL.
        $id = preg_match('#/files/(\d+)/#', $url, $m) ? $m[1] : 'url-' . md5($url);
        $asset = Asset::find()->volume($volumeHandle)->lightspeedId($id)->one();
        if ($asset) {
            return $asset;
        }

        $filename = basename(parse_url($url, PHP_URL_PATH));
        $tempPath = Craft::$app->getPath()->getTempPath() . '/' . uniqid('ls-', true) . "-$filename";

        try {
            Craft::createGuzzleClient()->get($url, ['sink' => $tempPath]);
        } catch (GuzzleException $e) {
            Craft::warning("Image download failed: $url ({$e->getMessage()})", __METHOD__);
            $this->failedImages[] = $url;
            FileHelper::unlink($tempPath);
            return null;
        }

        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        $asset = new Asset([
            'tempFilePath' => $tempPath,
            'filename' => $filename,
            'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
            'volumeId' => $volume->id,
            'avoidFilenameConflicts' => true,
        ]);
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $asset->setFieldValue('lightspeedId', $id);

        $this->save($asset);
        FileHelper::unlink($tempPath);

        return $asset;
    }

    /**
     * Saves the element on the primary site first, then fills and saves it on every other site.
     *
     * @param callable(Element $element, string $lang): void $fill $lang = CSV column prefix, e.g. `NL`
     */
    private function saveForAllSites(Element $element, callable $fill): void
    {
        foreach (array_keys(Module::SITES) as $handle) {
            $siteId = Craft::$app->getSites()->getSiteByHandle($handle)->id;
            if ($element->id && $element->siteId !== $siteId) {
                $element = $element::find()->id($element->id)->siteId($siteId)->status(null)->one();
            }

            $fill($element, strtoupper($handle));
            $this->save($element);
        }
    }

    /**
     * Value in the given language, with the primary language as fallback (French is missing for some products).
     */
    private function translated(array $row, string $lang, string $column): string
    {
        return $row["{$lang}_$column"] ?: $row["{$this->primaryLang()}_$column"] ?: $row["NL_$column"];
    }

    private function primaryLang(): string
    {
        return strtoupper($this->primarySite());
    }

    private function variantId(array $row): string
    {
        return str_replace('!ID:', '', $row['Internal_Variant_ID']);
    }

    private function primarySite(): string
    {
        return array_key_first(Module::SITES);
    }

    private function findEntryByTitle(string $section, string $title): ?Entry
    {
        return Entry::find()->section($section)->site($this->primarySite())->title(Db::escapeParam($title))->status(null)->one();
    }

    /**
     * By Lightspeed ID; entries from before the ID was imported by title.
     */
    private function findEntry(string $section, int|string $lightspeedId, string $title): ?Entry
    {
        $query = Entry::find()->section($section)->site($this->primarySite())->status(null);
        return (clone $query)->lightspeedId($lightspeedId)->one()
            ?? $query->lightspeedId(':empty:')->title(Db::escapeParam($title))->one();
    }

    private function entryIds(string $section, string $title): array
    {
        $entry = $title !== '' ? $this->findEntryByTitle($section, $title) : null;
        return $entry ? [$entry->id] : [];
    }

    private function newEntry(string $section, string $entryType): Entry
    {
        return new Entry([
            'sectionId' => Craft::$app->getEntries()->getSectionByHandle($section)->id,
            'typeId' => Craft::$app->getEntries()->getEntryTypeByHandle($entryType)->id,
        ]);
    }
}
