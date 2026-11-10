<?php

namespace modules\lightspeed;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\Utilities;
use craft\web\View;
use modules\lightspeed\services\Client;
use modules\lightspeed\services\Extractor;
use modules\lightspeed\services\Store;
use modules\lightspeed\services\Sync;
use modules\lightspeed\services\SyncLog;
use modules\lightspeed\utilities\SyncUtility;
use yii\base\Event;

/**
 * @property-read Client $client
 * @property-read Store $store
 * @property-read Sync $sync
 * @property-read SyncLog $syncLog
 */
class Module extends \yii\base\Module
{
    /**
     * Craft sites, primary first. The handle = the Lightspeed language code.
     */
    public const SITES = [
        'en' => ['name' => 'English', 'language' => 'en', 'baseUrl' => '$PRIMARY_SITE_URL'],
        'nl' => ['name' => 'Nederlands', 'language' => 'nl-BE', 'baseUrl' => '${PRIMARY_SITE_URL}/nl'],
        'fr' => ['name' => 'Français', 'language' => 'fr-BE', 'baseUrl' => '${PRIMARY_SITE_URL}/fr'],
    ];

    public function init(): void
    {
        Craft::setAlias('@modules/lightspeed', __DIR__);

        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'modules\\lightspeed\\console\\controllers'
            : 'modules\\lightspeed\\controllers';

        parent::init();

        $this->setComponents([
            'client' => Client::class,
            'store' => Store::class,
            'sync' => Sync::class,
            'syncLog' => SyncLog::class,
        ]);

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = SyncUtility::class;
        });
        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
            $event->roots['lightspeed'] = __DIR__ . '/templates';
        });
    }

    public function extractor(array $config = []): Extractor
    {
        return new Extractor($this->client, $this->store, $config);
    }
}
