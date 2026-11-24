<?php

namespace modules\wauw;

use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\View;
use yii\base\Event;
use yii\base\Module;

class Wauw extends Module {
    use ModuleTrait;

    public function init(): void {
        Craft::setAlias('@modules/wauw', __DIR__);

        parent::init();

        $this->controllerNamespace = 'modules\\wauw\\controllers';

        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
            $event->roots['wauw'] = __DIR__ . '/templates';
        });

        $this->registerEvents();
    }
}
