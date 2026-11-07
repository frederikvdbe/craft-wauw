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
use craft\helpers\FileHelper;
use GuzzleHttp\Exception\GuzzleException;
use modules\lightspeed\Module;
use yii\base\Component;

/**
 * Imports the API categories, brands and products into Craft, for every site. Safe to run again:
 * everything is matched on `lightspeedId`.
 */
class ProductImporter extends Component
{
    use SavesElements;

    /**
     * Lightspeed tax rate => Craft tax category
     */
    public const TAX_CATEGORIES = [
        '0' => 'marginScheme',
        '0.06' => 'reduced',
        '0.21' => 'standard',
    ];

    /**
     * Lightspeed has no "price on request" or "sold" flag: the shop sets the price to 0–2 instead.
     */
    private const PRICE_HACK_MAX = 2;

    /**
     * @var string[] image URLs that could not be downloaded
     */
    public array $failedImages = [];

    /**
     * @var array<int, int>|null Lightspeed brand ID => ID of the brand it is merged into
     */
    private ?array $brandIds = null;

    public function __construct(
        private readonly ApiData $data,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * The full Lightspeed category tree, 1:1.
     */
    public function importCategories(): int
    {
        $ids = $this->categoryIds();
        foreach ($ids as $id) {
            $this->importCategory($id);
        }

        return count($ids);
    }

    /**
     * @return int[] parents first
     */
    public function categoryIds(): array
    {
        return array_keys($this->data->categories[$this->primarySite()]);
    }

    public function importCategory(int $id): void
    {
        $category = $this->data->categories[$this->primarySite()][$id];
        $entry = $this->findEntry('categories', $id, $category['title']) ?? $this->newEntry('categories', 'category');
        $parentId = ApiData::id($category['parent']);
        $parent = $parentId ? Entry::find()->section('categories')->lightspeedId($parentId)->status(null)->one() : null;

        $this->saveForAllSites($entry, function(Entry $entry, string $site) use ($id, $category, $parent) {
            $entry->title = $this->text('categories', $site, $id, 'title');
            $entry->slug = $this->text('categories', $site, $id, 'url');
            $entry->enabled = $category['isVisible'];
            $entry->setParentId($parent?->id);
            $entry->setFieldValues([
                'lightspeedId' => (string)$id,
                'fullTitle' => $this->text('categories', $site, $id, 'fulltitle'),
                'shortDescription' => $this->text('categories', $site, $id, 'description') ?: null,
                'richText' => $this->text('categories', $site, $id, 'content') ?: null,
            ]);
        });
    }

    public function importBrands(): int
    {
        $groups = $this->brandGroups();
        foreach ($groups as $group) {
            $this->importBrandGroup($group);
        }

        return count($groups);
    }

    /**
     * @param array[] $group brands with the same title, see brandGroups()
     */
    public function importBrandGroup(array $group): void
    {
        $brand = $group[0];
        $image = $brand['image'] ? $this->findOrDownloadImage($brand['image']['src'], 'brandImages') : null;
        $entry = $this->findEntry('brands', $brand['id'], $brand['title']) ?? $this->newEntry('brands', 'brand');

        $this->saveForAllSites($entry, function(Entry $entry, string $site) use ($brand, $group, $image) {
            $translated = array_column($this->data->brands[$site], null, 'id')[$brand['id']] ?? $brand;
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

    /**
     * @param callable(int $done, int $total): void|null $onProgress
     */
    public function importProducts(?callable $onProgress = null): int
    {
        $ids = $this->productIds();
        foreach ($ids as $i => $id) {
            $this->importProduct($id);
            $onProgress && $onProgress($i + 1, count($ids));
        }

        return count($ids);
    }

    /**
     * @return int[]
     */
    public function productIds(): array
    {
        return array_keys($this->data->products[$this->primarySite()]);
    }

    public function importProduct(int $id): void
    {
        $product = Product::find()->type('products')->site($this->primarySite())->lightspeedId($id)->status(null)->one()
            ?? new Product(['typeId' => Commerce::getInstance()->getProductTypes()->getProductTypeByHandle('products')->id]);
        $fieldValues = $this->productFieldValues($id);

        $this->saveForAllSites($product, function(Product $product, string $site) use ($id, $fieldValues) {
            $this->fillProduct($product, $id, $site, $fieldValues);
        });
        $this->updateStock($product, $id);
    }

    /**
     * Field values that are the same on every site.
     */
    private function productFieldValues(int $id): array
    {
        $product = $this->data->products[$this->primarySite()][$id];
        $variant = $this->data->variants[$this->primarySite()][$id][0];
        $media = $this->media($id);
        $brandId = ApiData::id($product['brand']);

        return [
            'lightspeedId' => (string)$id,
            'brand' => $brandId ? $this->entryIds('brands', [$this->brandIds()[$brandId] ?? $brandId]) : [],
            'productCategories' => $this->entryIds('categories', $this->data->productCategories[$id] ?? []),
            'priceOnRequest' => $variant['priceIncl'] <= self::PRICE_HACK_MAX,
            'productImages' => array_values(array_filter(array_map(fn(string $url) => $this->findOrDownloadImage($url)?->id, $media['images']))),
            'video' => $media['video'] ? ['type' => 'url', 'value' => $media['video']] : null,
            'viewerUrl' => $media['viewer'] ? ['type' => 'url', 'value' => $media['viewer']] : null,
        ];
    }

    private function fillProduct(Product $product, int $id, string $site, array $fieldValues): void
    {
        $product->title = $this->text('products', $site, $id, 'title');
        $product->slug = $this->text('products', $site, $id, 'url');
        $product->enabled = $this->data->products[$this->primarySite()][$id]['isVisible'];
        // Not translated (French is missing for some products): text from the primary site, disabled on this site.
        $product->setEnabledForSite($this->data->products[$site][$id]['title'] !== '');
        $product->setFieldValues($fieldValues + [
            'fullTitle' => $this->text('products', $site, $id, 'fulltitle'),
            'shortDescription' => $this->text('products', $site, $id, 'description'),
            'richText' => $this->text('products', $site, $id, 'content') ?: null,
        ]);

        $existing = [];
        foreach ($product->getVariants(true) as $variant) {
            $existing[$variant->getFieldValue('lightspeedId')] = $variant;
        }

        $variants = [];
        foreach ($this->data->variants[$site][$id] as $i => $data) {
            $variant = $existing[$data['id']] ?? new Variant();
            $variant->title = strcasecmp($data['title'], 'default') === 0 || $data['title'] === '' ? $product->title : $data['title'];
            $variant->isDefault = $i === 0;
            $this->fillVariant($variant, $data);
            $variants[] = $variant;
        }

        $product->setVariants($variants);
        // Commerce only saves variants when this attribute is dirty.
        $product->setDirtyAttributes(['variants']);
    }

    private function fillVariant(Variant $variant, array $data): void
    {
        $price = (float)$data['priceIncl'];
        $oldPrice = (float)$data['oldPriceIncl'];
        $onSale = $price > self::PRICE_HACK_MAX && $oldPrice > $price;

        $variant->setSku($data['sku'] ?: "LS-{$data['id']}");
        // With the price hack, the real price is in the old price.
        $variant->setBasePrice($price <= self::PRICE_HACK_MAX || $onSale ? $oldPrice : $price);
        $variant->setBasePromotionalPrice($onSale ? $price : null);
        $variant->inventoryTracked = $data['stockTracking'] !== 'disabled';
        $variant->length = (float)$data['sizeXValue'] ?: null;
        $variant->width = (float)$data['sizeYValue'] ?: null;
        $variant->height = (float)$data['sizeZValue'] ?: null;
        $variant->weight = (float)$data['weightValue'] ?: null;
        $variant->setFieldValues([
            'lightspeedId' => (string)$data['id'],
            'ean' => $data['ean'] ?: null,
        ]);

        $rate = (string)($this->data->taxRates[ApiData::id($data['tax'])] ?? 0);
        $taxHandle = self::TAX_CATEGORIES[$rate] ?? 'marginScheme';
        $variant->setTaxCategoryId(Commerce::getInstance()->getTaxCategories()->getTaxCategoryByHandle($taxHandle)->id);
    }

    /**
     * Inventory items only exist after the first save, so stock is set afterwards.
     */
    private function updateStock(Product $product, int $id): void
    {
        $inventory = Commerce::getInstance()->getInventory();
        $stock = array_column($this->data->variants[$this->primarySite()][$id], 'stockLevel', 'id');

        foreach (Variant::find()->productId($product->id)->site($this->primarySite())->status(null)->all() as $variant) {
            $level = $stock[$variant->getFieldValue('lightspeedId')] ?? null;
            if ($level !== null) {
                $inventory->updatePurchasableInventoryLevel($variant, max(0, (int)$level));
            }
        }
    }

    /**
     * Sorts the product images and the custom data fields (data01–03) into images, a video and an interactive viewer.
     *
     * @return array{images: string[], video: ?string, viewer: ?string}
     */
    private function media(int $id): array
    {
        $product = $this->data->products[$this->primarySite()][$id];
        $images = $this->data->productImages[$id] ?? [];
        $video = $viewer = null;

        // data02 first: it is the dedicated viewer field.
        foreach (array_filter(array_map('trim', [$product['data02'], $product['data01'], $product['data03']])) as $url) {
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
     * Lightspeed has duplicate brands (e.g. "Fontana Arte" twice): 1 entry per title.
     * The first brand of a group is kept: the one with an image, else the oldest.
     *
     * @return array<string, array[]> title => brands
     */
    public function brandGroups(): array
    {
        $brands = $this->data->brands[$this->primarySite()];
        usort($brands, fn(array $a, array $b) => [!$a['image'], $a['id']] <=> [!$b['image'], $b['id']]);

        $groups = [];
        foreach ($brands as $brand) {
            $groups[mb_strtolower(trim($brand['title']))][] = $brand;
        }

        return $groups;
    }

    /**
     * @return array<int, int> Lightspeed brand ID => ID of the brand it is merged into
     */
    private function brandIds(): array
    {
        if ($this->brandIds === null) {
            $this->brandIds = [];
            foreach ($this->brandGroups() as $group) {
                foreach ($group as $brand) {
                    $this->brandIds[$brand['id']] = $group[0]['id'];
                }
            }
        }

        return $this->brandIds;
    }

    /**
     * Saves the element on the primary site first, then fills and saves it on every other site.
     *
     * @param callable(Element $element, string $site): void $fill $site = site handle = Lightspeed language
     */
    private function saveForAllSites(Element $element, callable $fill): void
    {
        foreach (array_keys(Module::SITES) as $handle) {
            $siteId = Craft::$app->getSites()->getSiteByHandle($handle)->id;
            if ($element->id && $element->siteId !== $siteId) {
                $element = $element::find()->id($element->id)->siteId($siteId)->status(null)->one();
            }

            $fill($element, $handle);
            $this->save($element);
        }
    }

    /**
     * Text in the language of the site, with the primary language, then Dutch (Lightspeed's default) as fallback.
     */
    private function text(string $list, string $site, int $id, string $field): string
    {
        foreach ([$site, $this->primarySite(), 'nl'] as $language) {
            $value = trim((string)($this->data->$list[$language][$id][$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function primarySite(): string
    {
        return array_key_first(Module::SITES);
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

    /**
     * @param int[] $lightspeedIds
     * @return int[] entry IDs, in the same order
     */
    private function entryIds(string $section, array $lightspeedIds): array
    {
        $ids = [];
        foreach ($lightspeedIds as $lightspeedId) {
            $entry = Entry::find()->section($section)->site($this->primarySite())->lightspeedId($lightspeedId)->status(null)->one();
            $entry && $ids[] = $entry->id;
        }

        return $ids;
    }

    private function newEntry(string $section, string $entryType): Entry
    {
        return new Entry([
            'sectionId' => Craft::$app->getEntries()->getSectionByHandle($section)->id,
            'typeId' => Craft::$app->getEntries()->getEntryTypeByHandle($entryType)->id,
        ]);
    }
}
