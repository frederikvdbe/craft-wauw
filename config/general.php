<?php

use craft\config\GeneralConfig;
use craft\helpers\App;

$viteManifest = dirname(__DIR__) . '/web/dist/.vite/manifest.json';

return GeneralConfig::create()
    // EMERGENCY KIT
    // ->disabledPlugins("*")
    // ->safeMode(true)
    // ->allowAdminChanges(false)

    // New build ID per deploy (Vite build), not per request
    ->buildId(is_file($viteManifest) ? (string) filemtime($viteManifest) : null)

    // LOCALIZATION & DEFAULTS
    ->defaultWeekStartDay(1)
    ->defaultCountryCode('BE')
    ->defaultCpLocale("nl_BE")
    ->defaultCpLanguage("en")
    
    // SEO
    ->disallowRobots(App::env('CRAFT_DISALLOW_ROBOTS') ?? false)
    
    // SECURITY
    ->allowAdminChanges(App::env('CRAFT_ALLOW_ADMIN_CHANGES') ?? false)
    ->preventUserEnumeration(true)
    ->sendPoweredByHeader(false)
    ->enableCsrfProtection(true)
    ->asyncCsrfInputs(true)
    ->limitAutoSlugsToAscii(true)
    ->enableGql(false)
    ->maxInvalidLogins(10)
    
    // ROUTING
    ->omitScriptNameInUrls()
    ->cpTrigger(App::env('CRAFT_CP_TRIGGER') ?? 'launchpad')
    ->postCpLoginRedirect('entries')

    // TEMPLATE RELATED SETTINGS
    ->errorTemplatePrefix('_pages/errors/')
    ->partialTemplatesPath('_elements') 
    ->enableTemplateCaching(!(App::env('CRAFT_DEV_MODE') ?? false))
    ->preloadSingles()
    
    // USER & AUTHENTICATION
    ->deferPublicRegistrationPassword(true)
    ->showFirstAndLastNameFields()
    ->extraLastNamePrefixes(['van', 'de', 'von', 'du', 'della', 'van der', 'van den'])
    ->extraNameSuffixes(['Jr.', 'Sr.', 'Ph.D.', 'M.D'])
    ->purgeStaleUserSessionDuration('P90D')
    ->purgeUnsavedDraftsDuration('P30D')
    
    // DATABASE 
    ->backupOnUpdate(true)
    ->maxRevisions(5)
    ->maxBackups(5)

    // ASSETS
    ->preserveExifData(false)
    ->revAssetUrls(true)
    ->convertFilenamesToAscii(true)
    ->sanitizeCpImageUploads(true)
    ->sanitizeSvgUploads(true)
    ->filenameWordSeparator('-')
    ->imageEditorRatios([])
    ->extraAllowedFileExtensions(['svg', 'webp', 'gif', 'avif', 'epub', 'dwg', 'dxf', 'csv'])
    ->extraFileKinds([
        'vector' => [
            'label' => 'Vector Graphics',
            'extensions' => ['svg', 'ai', 'eps'],
        ],
        'gif' => [
            'label' => 'GIF',
            'extensions' => ['gif'],
        ],
        'ebook' => [
            'label' => 'eBooks',
            'extensions' => ['epub', 'mobi'],
        ],
        'cad' => [
            'label' => 'CAD',
            'extensions' => ['dwg', 'dxf'],
        ],
        'csv' => [
            'label' => 'Comma separated values',
            'extensions' => ['csv']
        ]
    ])
    
    // ALIASSES
    ->aliases([
        '@web' => App::env('PRIMARY_SITE_URL'),
        '@webroot' => dirname(__DIR__) . '/web',
    ])

    // CONTROL PANEL
    ->cpHeadTags([
        ['link', ['rel' => 'stylesheet', 'href' => '/dist/admin.css']],
    ])

    // REVERSE PROXY CONFIGURATION
    // ->secureProtocolHeaders([
    //     'X-Forwared-Proto' => ['https'],
    //     'X-Forwarded-Ssl' => ['on']
    // ])
;
