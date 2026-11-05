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
    /**
     * Craft sites, primary first. The handle in upper case is the column prefix in the CSV export (`NL_Title_Short`).
     */
    public const SITES = [
        'en' => ['name' => 'English', 'language' => 'en', 'baseUrl' => '$PRIMARY_SITE_URL'],
        'nl' => ['name' => 'Nederlands', 'language' => 'nl-BE', 'baseUrl' => '${PRIMARY_SITE_URL}/nl'],
        'fr' => ['name' => 'Français', 'language' => 'fr-BE', 'baseUrl' => '${PRIMARY_SITE_URL}/fr'],
    ];

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
