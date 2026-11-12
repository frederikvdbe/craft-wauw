<?php

use craft\helpers\App;
use modules\lightspeed\Module as Lightspeed;
use modules\docs\Module as Docs;
use modules\wauw\Wauw;

return [
    'id' => App::env('CRAFT_APP_ID') ?: 'CraftCMS',
    'components' => [
        'queue' => [
            'ttr' => 300,
        ]
    ],
    'modules' => [
        'wauw' => Wauw::class,
        'lightspeed' => Lightspeed::class,
        'docs' => Docs::class,
    ],
    'bootstrap' => [
        'wauw',
        'lightspeed',
        'docs',
    ],
];
