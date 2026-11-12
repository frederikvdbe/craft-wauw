<?php

namespace modules\wauw\services;

use Craft;
use craft\commerce\elements\Product;
use craft\elements\Asset;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\web\View;
use Dompdf\Dompdf;
use Dompdf\Options;
use yii\base\Exception;

class SpecSheetGenerator {
    public const VOLUME_HANDLE = 'specificationSheets';

    private const MAX_IMAGES = 4;

    /**
     * Stable key to find a product's existing specification sheet asset by: the asset's own filename is
     * the product title + generation timestamp, so it changes on every regeneration and can't be matched
     * on. Stored in the `productKey` field on the specificationSheets volume instead.
     */
    public static function matchKeyFor(Product $product): string {
        $site = Craft::$app->getSites()->getSiteById($product->siteId);
        return "$product->id-$site->handle";
    }

    /**
     * The product's current specification sheet asset, or null if it hasn't been generated yet.
     */
    public static function assetFor(Product $product): ?Asset {
        return Asset::find()->volume(self::VOLUME_HANDLE)->productKey(self::matchKeyFor($product))->one();
    }

    public function generate(Product $product): Asset {
        $view = Craft::$app->getView();
        $templateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            $html = $view->renderTemplate('_pdfs/productSpecificationSheet', $this->templateVariables($product));
        } finally {
            $view->setTemplateMode($templateMode);
        }

        return $this->saveAsset($product, $this->renderPdf($html));
    }

    private function templateVariables(Product $product): array {
        $brandImage = $product->brandField->one()?->brandImageField->one();

        return [
            'product' => $product,
            'variant' => $product->getDefaultVariant(),
            'images' => array_map(
                $this->imageSrc(...),
                array_slice($product->productImagesField->all(), 0, self::MAX_IMAGES),
            ),
            'brandImageSrc' => $brandImage ? $this->imageSrc($brandImage) : null,
        ];
    }

    private function imageSrc(Asset $asset): string {
        $fs = $asset->getVolume()->getFs();

        if ($fs instanceof Local) return 'file://' . $fs->getRootPath() . '/' . $asset->getPath();

        return (string)$asset->getUrl();
    }

    private function renderPdf(string $html): string
    {
        $pathService = Craft::$app->getPath();
        $tempDir = $pathService->getTempPath() . '/site_dompdf';
        $fontCache = $pathService->getCachePath() . '/site_dompdf';
        $logFile = $pathService->getLogPath() . '/site_dompdf.htm';

        FileHelper::createDirectory($tempDir);
        FileHelper::createDirectory($fontCache);

        $options = new Options();
        $options->setTempDir($tempDir);
        $options->setFontCache($fontCache);
        $options->setLogOutputFile($logFile);
        $options->setIsRemoteEnabled(true);
        $options->setDefaultPaperSize('a4');
        $options->setChroot([Craft::getAlias('@webroot')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $dompdf->output();
    }

    private function saveAsset(Product $product, string $pdf): Asset
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle(self::VOLUME_HANDLE);
        if (!$volume) {
            throw new Exception('The "' . self::VOLUME_HANDLE . '" volume does not exist yet. Apply project config first (`ddev craft project-config/apply`).');
        }

        $filename = $this->displayFilename($product);
        $tempPath = Craft::$app->getPath()->getTempPath() . '/' . uniqid('spec-sheet-', true) . '.pdf';
        file_put_contents($tempPath, $pdf);

        $asset = self::assetFor($product);

        if ($asset) {
            Craft::$app->getAssets()->replaceAssetFile($asset, $tempPath, $filename);
        } else {
            $asset = new Asset([
                'tempFilePath' => $tempPath,
                'filename' => $filename,
                'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
                'volumeId' => $volume->id,
                'avoidFilenameConflicts' => true,
            ]);
            $asset->setScenario(Asset::SCENARIO_CREATE);
            $asset->setFieldValue('productKey', self::matchKeyFor($product));

            if (!Craft::$app->getElements()->saveElement($asset)) {
                FileHelper::unlink($tempPath);
                throw new Exception('Could not save specification sheet asset: ' . implode(', ', $asset->getErrorSummary(true)));
            }
        }

        FileHelper::unlink($tempPath);

        return $asset;
    }

    /**
     * The asset's actual filename: product title + generation timestamp.
     */
    private function displayFilename(Product $product): string {
        $title = $product->fullTitleField ?: $product->title;
        $timestamp = (new \DateTime())->format('Y-m-d-His');

        return FileHelper::sanitizeFilename("$title-$timestamp") . '.pdf';
    }
}
