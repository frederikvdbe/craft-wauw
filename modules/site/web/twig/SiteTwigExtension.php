<?php

namespace modules\site\web\twig;

use Craft;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SiteTwigExtension extends AbstractExtension {
    public function getFunctions() {
        return [
            new TwigFunction('getTransformsMap', [$this, 'getTransformsMap']),
            new TwigFunction('isActive', [$this, 'isActive']),
        ];
    }

    /**
     * Gives back an array of Craft transforms
     * 
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
     * Return wether a certain url should be marked active
     * 
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
