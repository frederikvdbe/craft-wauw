<?php

namespace modules\lightspeed;

use Craft;
use modules\lightspeed\services\Client;
use modules\lightspeed\services\Store;

/**
 * @property-read Client $client
 * @property-read Store $store
 */
class Module extends \yii\base\Module
{
    public function init(): void
    {
        Craft::setAlias('@modules/lightspeed', __DIR__);

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'modules\\lightspeed\\console\\controllers';
        }

        parent::init();

        $this->setComponents([
            'client' => Client::class,
            'store' => Store::class,
        ]);
    }
}
