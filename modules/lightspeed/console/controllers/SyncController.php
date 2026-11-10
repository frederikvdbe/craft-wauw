<?php

namespace modules\lightspeed\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use modules\lightspeed\jobs\ImportJob;
use modules\lightspeed\Module;
use modules\lightspeed\services\Extractor;
use yii\base\Exception;
use yii\console\ExitCode;

/**
 * Runs the extract and import as queue jobs, one step after the other.
 * Same as Utilities → Lightspeed sync. Progress: Utilities → Queue Manager. Needs a queue runner (DDEV: daemon `craft-queue`).
 *
 * @property Module $module
 */
class SyncController extends Controller
{
    /**
     * @var bool only the sample (see Sample): per-item lists (images, order lines, …) and import. Other lists are always full.
     */
    public bool $sample = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'sample'];
    }

    /**
     * Full extract, then import.
     */
    public function actionIndex(): int
    {
        if (!$this->confirmExtract() || !$this->confirmImport()) {
            return ExitCode::OK;
        }

        return $this->queue(Extractor::names(), ImportJob::PARTS);
    }

    /**
     * Extract only: everything, or some files (e.g. `products-images,orders-products`).
     */
    public function actionExtract(array $names = []): int
    {
        if (!$this->valid($names, Extractor::names())) {
            return ExitCode::USAGE;
        }
        if (!$names && !$this->confirmExtract()) {
            return ExitCode::OK;
        }

        return $this->queue($names ?: Extractor::names(), []);
    }

    /**
     * Import only: everything, or some parts (e.g. `products,orders`), always in dependency order.
     */
    public function actionImport(array $parts = []): int
    {
        if (!$this->valid($parts, ImportJob::PARTS)) {
            return ExitCode::USAGE;
        }
        if (!$this->confirmImport()) {
            return ExitCode::OK;
        }

        return $this->queue([], $parts ?: ImportJob::PARTS);
    }

    private function queue(array $extract, array $import): int
    {
        try {
            $count = $this->module->sync->queue($extract, $import, $this->sample);
        } catch (Exception $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("$count steps queued. Progress: Utilities → Queue Manager.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function valid(array $values, array $allowed): bool
    {
        $unknown = array_diff($values, $allowed);
        if ($unknown) {
            $this->stderr('Unknown: ' . implode(', ', $unknown) . '. Use: ' . implode(', ', $allowed) . "\n", Console::FG_RED);
        }

        return !$unknown;
    }

    private function confirmExtract(): bool
    {
        return $this->confirm('Full export of the live shop (read-only). This takes a few hours. Continue?');
    }

    private function confirmImport(): bool
    {
        return $this->sample || $this->confirm('Full import: everything in the API export. Continue? (Use --sample for a small test set.)');
    }
}
