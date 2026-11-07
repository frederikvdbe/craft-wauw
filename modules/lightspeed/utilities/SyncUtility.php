<?php

namespace modules\lightspeed\utilities;

use Craft;
use craft\base\Utility;
use modules\lightspeed\jobs\ImportJob;
use modules\lightspeed\Module;
use modules\lightspeed\services\Extractor;

/**
 * Utilities → Lightspeed sync: queue the extract and import.
 */
class SyncUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Lightspeed sync';
    }

    public static function id(): string
    {
        return 'lightspeed-sync';
    }

    public static function icon(): ?string
    {
        return 'arrows-rotate';
    }

    public static function contentHtml(): string
    {
        $module = Module::getInstance();
        $formatter = Craft::$app->getFormatter();

        $extractOptions = array_map(function(string $name) use ($module, $formatter) {
            $date = $module->store->modifiedAt($name);
            return [
                'label' => $name,
                'value' => $name,
                'info' => $date ? 'Downloaded ' . $formatter->asDatetime($date, 'short') : 'Not downloaded yet',
            ];
        }, Extractor::names());

        return Craft::$app->getView()->renderTemplate('lightspeed/_utility.twig', [
            'isQueued' => $module->sync->isQueued(),
            'extractOptions' => $extractOptions,
            'importOptions' => array_map(fn(string $part) => ['label' => ucfirst($part), 'value' => $part], ImportJob::PARTS),
        ]);
    }
}
