<?php

namespace modules\lightspeed\services;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use yii\base\Component;
use yii\base\Exception;

/**
 * Imports the local Lightspeed JSON into Craft. Finds existing elements by `lightspeedId`, so it is safe to run again.
 */
class Importer extends Component
{
    /**
     * Lightspeed tax ID => Commerce tax category handle.
     */
    private const TAX_CATEGORIES = [
        1207657 => 'marginScheme',
        868640 => 'standard',
    ];

    public function __construct(
        private readonly Store $store,
        private readonly string $language,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function importCategories(): int
    {
        $categories = $this->store->read($this->language, 'categories');
        usort($categories, fn(array $a, array $b) => [$a['depth'], $a['sortOrder']] <=> [$b['depth'], $b['sortOrder']]);

        foreach ($categories as $data) {
            $entry = $this->findEntry('categories', $data['id']) ?? $this->newEntry('categories', 'category');
            $entry->title = trim($data['title']);
            $entry->slug = $data['url'];
            $entry->enabled = $data['isVisible'];
            $entry->setFieldValues([
                'fullTitle' => $data['fulltitle'],
                'richText' => $data['content'] ?: null,
                'lightspeedId' => (string)$data['id'],
            ]);

            $parentId = $data['parent']['resource']['id'] ?? null;
            $entry->setParentId($parentId ? $this->findEntry('categories', $parentId)?->id : null);

            $this->save($entry);
        }

        return count($categories);
    }

    public function importBrands(): int
    {
        $brands = $this->store->read($this->language, 'brands');

        foreach ($brands as $data) {
            $entry = $this->findEntry('brands', $data['id']) ?? $this->newEntry('brands', 'brand');
            $entry->title = trim($data['title']);
            $entry->slug = $data['url'];
            $entry->enabled = $data['isVisible'];
            $entry->setFieldValues([
                'richText' => $data['content'] ?: null,
                'lightspeedId' => (string)$data['id'],
            ]);

            $this->save($entry);
        }

        return count($brands);
    }

    public function importProducts(): int
    {
        $products = $this->store->read($this->language, 'products');
        $variants = $this->groupByRelation($this->store->read($this->language, 'variants'), 'product');
        $categoryLinks = $this->groupByRelation($this->store->read($this->language, 'categories-products'), 'product');
        $images = $this->store->read($this->language, 'products-images');

        foreach ($products as $data) {
            $product = Product::find()->type('products')->lightspeedId($data['id'])->status(null)->one()
                ?? new Product(['typeId' => Commerce::getInstance()->getProductTypes()->getProductTypeByHandle('products')->id]);

            $product->title = trim($data['title']);
            $product->slug = $data['url'];
            $product->enabled = $data['isVisible'];
            $product->setFieldValues([
                'fullTitle' => $data['fulltitle'],
                'shortDescription' => $data['description'],
                'richText' => $data['content'] ?: null,
                'lightspeedId' => (string)$data['id'],
                'brand' => $this->brandIds($data),
                'productCategories' => $this->categoryIds($categoryLinks[$data['id']] ?? []),
                'productImages' => $this->imageIds($images[$data['id']] ?? []),
            ]);

            $variantData = $variants[$data['id']][0] ?? throw new Exception("Product {$data['id']} has no variant");
            $variant = $product->getVariants(true)[0] ?? new Variant();
            $this->fillVariant($variant, $variantData);
            $product->setVariants([$variant]);
            // Commerce only saves variants when this attribute is dirty.
            $product->setDirtyAttributes(['variants']);

            $this->save($product);

            // Reload: the inventory item only exists after the save.
            $savedVariant = Variant::find()->productId($product->id)->status(null)->one();
            Commerce::getInstance()->getInventory()->updatePurchasableInventoryLevel($savedVariant, (int)$variantData['stockLevel']);
        }

        return count($products);
    }

    /**
     * Lightspeed shows a sale as oldPrice (before) + price (now). Commerce uses price + promotionalPrice.
     */
    private function fillVariant(Variant $variant, array $data): void
    {
        $price = (float)$data['priceIncl'];
        $oldPrice = (float)$data['oldPriceIncl'];
        $onSale = $oldPrice > $price;

        $variant->setSku($data['sku'] ?: $data['articleCode'] ?: "LS-{$data['id']}");
        $variant->setBasePrice($onSale ? $oldPrice : $price);
        $variant->setBasePromotionalPrice($onSale ? $price : null);
        $variant->inventoryTracked = $data['stockTracking'] === 'enabled';
        $variant->isDefault = true;

        $taxHandle = self::TAX_CATEGORIES[$data['tax']['resource']['id'] ?? null] ?? 'marginScheme';
        $variant->setTaxCategoryId(Commerce::getInstance()->getTaxCategories()->getTaxCategoryByHandle($taxHandle)->id);
    }

    private function brandIds(array $product): array
    {
        $brandId = $product['brand']['resource']['id'] ?? null;
        $brand = $brandId ? $this->findEntry('brands', $brandId) : null;

        return $brand ? [$brand->id] : [];
    }

    private function categoryIds(array $links): array
    {
        usort($links, fn(array $a, array $b) => $a['sortOrder'] <=> $b['sortOrder']);

        return array_values(array_filter(array_map(
            fn(array $link) => $this->findEntry('categories', $link['category']['resource']['id'])?->id,
            $links,
        )));
    }

    private function imageIds(array $images): array
    {
        usort($images, fn(array $a, array $b) => $a['sortOrder'] <=> $b['sortOrder']);

        return array_map(fn(array $image) => $this->findOrDownloadImage($image)->id, $images);
    }

    private function findOrDownloadImage(array $image): Asset
    {
        $asset = Asset::find()->volume('productImages')->lightspeedId($image['id'])->one();
        if ($asset) {
            return $asset;
        }

        $filename = basename(parse_url($image['src'], PHP_URL_PATH));
        $tempPath = Craft::$app->getPath()->getTempPath() . '/' . uniqid('ls-', true) . "-$filename";
        Craft::createGuzzleClient()->get($image['src'], ['sink' => $tempPath]);

        $volume = Craft::$app->getVolumes()->getVolumeByHandle('productImages');
        $asset = new Asset([
            'tempFilePath' => $tempPath,
            'filename' => $filename,
            'title' => $image['title'],
            'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
            'volumeId' => $volume->id,
            'avoidFilenameConflicts' => true,
        ]);
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $asset->setFieldValue('lightspeedId', (string)$image['id']);

        $this->save($asset);
        FileHelper::unlink($tempPath);

        return $asset;
    }

    private function findEntry(string $section, int|string $lightspeedId): ?Entry
    {
        return Entry::find()->section($section)->lightspeedId($lightspeedId)->status(null)->one();
    }

    private function newEntry(string $section, string $entryType): Entry
    {
        return new Entry([
            'sectionId' => Craft::$app->getEntries()->getSectionByHandle($section)->id,
            'typeId' => Craft::$app->getEntries()->getEntryTypeByHandle($entryType)->id,
        ]);
    }

    /**
     * Groups list items by a related resource ID, e.g. variants by product.
     */
    private function groupByRelation(array $items, string $relation): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item[$relation]['resource']['id']][] = $item;
        }
        return $grouped;
    }

    private function save(Element $element): void
    {
        if (!Craft::$app->getElements()->saveElement($element)) {
            throw new Exception(sprintf(
                'Could not save %s "%s": %s',
                $element::class,
                $element->title,
                implode(', ', $element->getErrorSummary(true)),
            ));
        }
    }
}
