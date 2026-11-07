<?php

namespace modules\lightspeed\jobs;

use Craft;
use modules\lightspeed\Module;
use modules\lightspeed\services\ApiData;
use modules\lightspeed\services\CustomerImporter;
use modules\lightspeed\services\OrderImporter;
use modules\lightspeed\services\ProductImporter;
use modules\lightspeed\services\Sample;

/**
 * Imports 1 part of the API export: 1 item per category, brand, product, customer or order.
 */
class ImportJob extends SyncJob
{
    /**
     * In dependency order.
     */
    public const PARTS = ['categories', 'brands', 'products', 'customers', 'orders'];

    public string $part;

    /**
     * @var bool only the sample (see Sample)
     */
    public bool $sample = false;

    /**
     * Large batches: every batch loads the whole API export again.
     */
    public int $batchSize = 500;

    /**
     * Batches stop in time before this; product images can be slow.
     */
    public ?int $ttr = 1800;

    private ?ApiData $apiData = null;
    private ProductImporter|CustomerImporter|OrderImporter|null $importer = null;

    protected function loadItems(): array
    {
        $importer = $this->importer();

        return match ($this->part) {
            'categories' => $importer->categoryIds(),
            'brands' => array_keys($importer->brandGroups()),
            'products' => $importer->productIds(),
            'customers', 'orders' => $importer->ids(),
        };
    }

    protected function processItem(mixed $item): void
    {
        $importer = $this->importer();

        match ($this->part) {
            'categories' => $importer->importCategory($item),
            'brands' => $importer->importBrandGroup($importer->brandGroups()[$item]),
            'products' => $importer->importProduct($item),
            'customers' => $importer->importCustomer($item),
            'orders' => $importer->importOrder($item),
        };
    }

    protected function afterBatch(): void
    {
        $importer = $this->importer();
        $warnings = $importer instanceof ProductImporter
            ? array_map(fn(string $url) => "Image not downloaded: $url", $importer->failedImages)
            : $importer->warnings;

        foreach ($warnings as $warning) {
            Craft::warning("Lightspeed $this->part: $warning", 'lightspeed');
        }
    }

    protected function defaultDescription(): ?string
    {
        return self::DESCRIPTION_PREFIX . " import: $this->part" . ($this->sample ? ' (sample)' : '');
    }

    private function importer(): ProductImporter|CustomerImporter|OrderImporter
    {
        return $this->importer ??= match ($this->part) {
            'categories', 'brands', 'products' => new ProductImporter($this->apiData()),
            'customers' => new CustomerImporter($this->apiData()),
            'orders' => new OrderImporter($this->apiData()),
        };
    }

    private function apiData(): ApiData
    {
        if ($this->apiData === null) {
            $this->apiData = ApiData::load(Module::getInstance()->store);
            if ($this->sample) {
                $this->apiData = (new Sample($this->apiData))->apply();
            }
        }

        return $this->apiData;
    }
}
