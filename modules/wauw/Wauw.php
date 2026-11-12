<?php

namespace modules\wauw;

use Craft;
use yii\base\Module;

class Wauw extends Module {
    use ModuleTrait;

    public function init(): void {
        Craft::setAlias('@modules/wauw', __DIR__);

        parent::init();

        $this->controllerNamespace = 'modules\\wauw\\controllers';

        $this->registerEvents();
    }
}
