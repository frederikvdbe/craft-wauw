<?php

namespace modules\wauw\jobs;

use craft\commerce\elements\Product;
use craft\queue\BaseJob;
use modules\wauw\services\SpecSheetGenerator;

/**
 * Renders the specification sheet PDF for 1 product in 1 site and stores it as an asset.
 */
class GenerateSpecSheet extends BaseJob {
    public int $productId;
    public int $siteId;

    public function execute($queue): void {
        $product = Product::find()->id($this->productId)->siteId($this->siteId)->status(null)->one();
        if (!$product) return;

        (new SpecSheetGenerator())->generate($product);
    }

    protected function defaultDescription(): ?string
    {
        return 'Generating specification sheet';
    }
}
