<?php

use craft\helpers\App;

return [
    'devServerInternal' => 'http://localhost:3000',
    'devServerPublic'   => App::env('PRIMARY_SITE_URL') . ":3001",
    'serverPublic' => App::env('PRIMARY_SITE_URL') . '/dist/',
    'useDevServer' => App::env("CRAFT_DEV_MODE"),
    'errorEntry' => '',
    'cacheKeySuffix' => '',
	'checkDevServer' => false,
    'manifestPath' => '@webroot/dist/.vite/manifest.json',
];

