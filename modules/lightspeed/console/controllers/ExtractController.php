<?php

namespace modules\lightspeed\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use GuzzleHttp\Exception\ClientException;
use modules\lightspeed\Module;
use modules\lightspeed\services\Extractor;
use yii\console\ExitCode;

/**
 * Downloads all Lightspeed data from the API (read-only) to JSON files in storage/lightspeed/api.
 * For a run in the background with progress in the control panel: `lightspeed/sync`.
 *
 * @property Module $module
 */
class ExtractController extends Controller
{
    private const SAMPLE_SIZE = 25;
    private const SAMPLE_DIR = '@storage/lightspeed/api-sample';
    private const SAVE_EVERY = 50;

    /**
     * @var bool|int only the first items of every list (`--sample` = 25, `--sample=500`), in storage/lightspeed/api-sample
     */
    public bool|int|null $sample = null;

    private Extractor $extractor;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'sample'];
    }

    public function beforeAction($action): bool
    {
        if ($this->sample !== null) {
            $this->module->store->dir = self::SAMPLE_DIR;
        } elseif ($action->id === 'index' && !$this->confirm('Full export of the live shop (read-only). This takes a few hours. Continue? (Use --sample for a small test set.)')) {
            return false;
        }

        $this->module->client->onWait = fn(int $seconds) => $this->stdout("Rate limit: waiting {$seconds}s\n", Console::FG_YELLOW);
        $this->extractor = $this->module->extractor([
            'max' => match ($this->sample) {
                null => null,
                true => self::SAMPLE_SIZE,
                default => (int)$this->sample,
            },
        ]);

        return parent::beforeAction($action);
    }

    /**
     * Shows the number of records per list.
     */
    public function actionCounts(): int
    {
        $rows = [];
        foreach ([...Extractor::SHARED, ...Extractor::TRANSLATED] as $name => $path) {
            try {
                $rows[] = [$name, $this->module->client->count($this->extractor->primaryLanguage(), $path)];
            } catch (ClientException $e) {
                $rows[] = [$name, 'n/a (' . $e->getResponse()->getStatusCode() . ')'];
            }
        }

        $this->table(['List', 'Count'], $rows);
        return ExitCode::OK;
    }

    /**
     * Downloads everything, or only the given file name (e.g. `products-images`).
     */
    public function actionIndex(?string $name = null): int
    {
        $all = Extractor::names();
        if ($name !== null && !in_array($name, $all, true)) {
            $this->stderr("Unknown: $name. Use one of: " . implode(', ', $all) . "\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        foreach ($name ? [$name] : $all as $name) {
            foreach ($this->extractor->languagesOf($name) as $language) {
                try {
                    $count = Extractor::isRelation($name)
                        ? $this->extractRelation($name, $language)
                        : $this->extractor->extractList($name, $language);
                } catch (ClientException $e) {
                    $this->stderr("[$language] $name: not available (HTTP {$e->getResponse()->getStatusCode()})\n", Console::FG_RED);
                    continue;
                }

                $this->stdout("[$language] $name: $count\n");
            }
        }

        return ExitCode::OK;
    }

    /**
     * Saves progress every few items: a stopped download resumes where it stopped.
     */
    private function extractRelation(string $name, string $language): int
    {
        $data = $this->extractor->readPartial($name, $language);
        if ($data) {
            $this->stdout("[$language] $name: resuming after " . count($data) . " items\n", Console::FG_YELLOW);
        }

        foreach ($this->extractor->relationIds($name) as $id) {
            if (isset($data[$id])) {
                continue;
            }

            $data[$id] = $this->extractor->fetchRelation($name, $language, $id);
            if (count($data) % self::SAVE_EVERY === 0) {
                $this->extractor->writePartial($name, $language, $data);
            }
        }

        $this->extractor->finishRelation($name, $language, $data);
        return count($data);
    }
}
