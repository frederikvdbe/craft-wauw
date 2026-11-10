<?php

namespace modules\site;

use Craft;
use craft\commerce\elements\Product;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Module;
use yii\base\Event;

use modules\site\jobs\GenerateSpecSheet;
use modules\site\web\twig\SiteTwigExtension;

class Site extends Module {
    public function init() {
        Craft::setAlias('@modules', __DIR__);

        parent::init();

        $this->controllerNamespace = 'modules\\site\\controllers';

        $this->registerTwigExtensions();
        $this->registerSpecSheetGeneration();
        $this->registerSiteRoutes();
    }

    private function registerTwigExtensions(): void {
        Craft::$app->view->registerTwigExtension(new SiteTwigExtension());
    }

    /**
     * Regenerates a product's specification sheet PDF whenever it's saved (CP edits, imports, resaves).
     * Skipped for drafts, revisions and site-propagation copies: only the canonical, actually-changed
     * site save should queue a render.
     */
    private function registerSpecSheetGeneration(): void {
        Event::on(Product::class, Product::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Product $product */
            $product = $event->sender;

            if ($product->getIsDraft() || $product->getIsRevision() || $product->propagating) {
                return;
            }

            Craft::$app->getQueue()->push(new GenerateSpecSheet([
                'productId' => $product->id,
                'siteId' => $product->siteId,
            ]));
        });
    }

    private function registerSiteRoutes(): void {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['products/<slug:[^\/]+>/product-sheet'] = 'site/product-sheet';
        });
    }
}
