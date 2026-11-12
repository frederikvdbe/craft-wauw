<?php

namespace modules\wauw\web\twig;

use Craft;
use craft\commerce\elements\Product;
use craft\helpers\UrlHelper;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

use modules\wauw\services\SpecSheetGenerator;

class SiteTwigExtension extends AbstractExtension {
    public function getFunctions() {
        return [
            new TwigFunction('getTransformsMap', [$this, 'getTransformsMap']),
            new TwigFunction('isActive', [$this, 'isActive']),
            new TwigFunction('specSheetUrl', [$this, 'specSheetUrl']),
        ];
    }

    public function getFilters(): array {
        return [
            new TwigFilter('inches', fn(?float $cm): ?float => $cm === null ? null : round($cm / 2.54, 1)),
            new TwigFilter('pounds', fn(?float $kg): ?float => $kg === null ? null : round($kg * 2.20462, 1)),
        ];
    }

    /**
     * @param Product $product
     * @return string|null
     */
    public function specSheetUrl(Product $product): ?string {
        if (!SpecSheetGenerator::assetFor($product)) {
            return null;
        }

        return UrlHelper::siteUrl("products/$product->slug/product-sheet", siteId: $product->siteId);
    }

    /**
     * @param array $widths
     * @param array $formats
     * @return array{format: mixed, width: mixed[]}
     */

    public function getTransformsMap(array $widths, array $formats = ['avif', 'webp']): array {
        $transforms = [];

        foreach ($formats as $format) {
            foreach ($widths as $width) {
                $transforms[] = ['width' => $width, 'format' => $format];
            }
        }
        
        return $transforms;
    }

    /**
     * @param string $url
     * @return string|null
     */
    public function isActive(string $url): mixed {
        $requestUrl = Craft::$app->request->absoluteUrl;
        $requestUrlSegments = Craft::$app->request->segments;
        $urlSegments = explode("/", ltrim(parse_url($url, PHP_URL_PATH), "/"));

        if ($requestUrl === $url) return "active";

        if (\count($urlSegments) < \count($requestUrlSegments) && 
            \array_slice($requestUrlSegments, 0, \count($urlSegments)) === $urlSegments) {
            return "active";
        }
        
        return null;
    }
}
