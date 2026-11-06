<?php

namespace modules\site;

use Craft;
use yii\base\Module;

use modules\site\web\twig\SiteTwigExtension;

class Site extends Module {
    public function init() {
        Craft::setAlias('@modules', __DIR__);

        parent::init();

        $this->controllerNamespace = 'modules\\site\\controllers';

        $this->registerTwigExtensions();
    }

    private function registerTwigExtensions(): void {
        Craft::$app->view->registerTwigExtension(new SiteTwigExtension());
    }
}
