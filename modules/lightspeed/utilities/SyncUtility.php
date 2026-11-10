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

    /**
     * Failed or stopped steps of the last sync: shown on Utilities in the CP menu.
     */
    public static function badgeCount(): int
    {
        return Module::getInstance()->syncLog->problemCount();
    }

    public static function contentHtml(): string
    {
        $module = Module::getInstance();
        $formatter = Craft::$app->getFormatter();

        $emptyNames = array_values(array_filter(Extractor::names(), fn(string $name) => $module->store->isEmpty($name)));

        $extractOptions = array_map(function(string $name) use ($module, $formatter, $emptyNames) {
            $date = $module->store->modifiedAt($name);
            $info = $date ? 'Downloaded ' . $formatter->asDatetime($date, 'short') : 'Not downloaded yet';
            return [
                'label' => $name,
                'value' => $name,
                'info' => in_array($name, $emptyNames, true) ? "Empty. $info" : $info,
            ];
        }, Extractor::names());

        return Craft::$app->getView()->renderTemplate('lightspeed/_utility.twig', [
            'isQueued' => $module->sync->isQueued(),
            'runs' => $module->syncLog->runs(),
            'extractOptions' => $extractOptions,
            'emptyNames' => $emptyNames,
            'importOptions' => array_map(fn(string $part) => ['label' => ucfirst($part), 'value' => $part], ImportJob::PARTS),
        ]);
    }
}
