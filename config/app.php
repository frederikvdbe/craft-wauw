<?php

use craft\helpers\App;
use modules\lightspeed\Module as Lightspeed;
use modules\shop\Module as Shop;
use modules\site\Site;

return [
    'id' => App::env('CRAFT_APP_ID') ?: 'CraftCMS',
    'components' => [
        'queue' => [
            'ttr' => 300, // Increase for long running queue jobs
        ]
    ],
    'modules' => [
        'site' => Site::class,
        'lightspeed' => Lightspeed::class,
        'shop' => Shop::class,
    ],
    'bootstrap' => [
        'site',
        'lightspeed',
        'shop',
    ],
];
