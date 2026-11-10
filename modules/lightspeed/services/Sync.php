<?php

namespace modules\lightspeed\services;

use Craft;
use modules\lightspeed\jobs\ExtractJob;
use modules\lightspeed\jobs\ImportJob;
use modules\lightspeed\jobs\SyncJob;
use modules\lightspeed\Module;
use yii\base\Component;
use yii\base\Exception;

/**
 * Queues the extract and import as jobs that run one after the other (see SyncJob).
 */
class Sync extends Component
{
    /**
     * @param string[] $extract file names, see Extractor::names()
     * @param string[] $import parts, see ImportJob::PARTS; always imported in dependency order
     * @param bool $sample per-item lists + import: only the sample
     * @return int number of steps
     * @throws Exception when a sync is already in the queue
     */
    public function queue(array $extract, array $import, bool $sample): int
    {
        if ($this->isQueued()) {
            throw new Exception('A Lightspeed sync is already in the queue (Utilities → Queue Manager: wait, retry or release it).');
        }

        $jobs = array_map(
            fn(array $config) => Craft::createObject($config),
            [...$this->extractSteps($extract, $sample), ...$this->importSteps($import, $sample)],
        );
        $runId = Module::getInstance()->syncLog->start(array_map(fn(SyncJob $job) => $job->label(), $jobs), $sample);
        SyncJob::queueAll($jobs, $runId);

        return count($jobs);
    }

    public function isQueued(): bool
    {
        foreach (Craft::$app->getQueue()->getJobInfo() as $job) {
            if (str_starts_with((string)$job['description'], SyncJob::DESCRIPTION_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 1 step per file; relations in more languages: 1 step per language.
     */
    private function extractSteps(array $names, bool $sample): array
    {
        $extractor = Module::getInstance()->extractor();
        $steps = [];

        foreach (array_intersect(Extractor::names(), $names) as $name) {
            if (Extractor::isRelation($name)) {
                foreach ($extractor->languagesOf($name) as $language) {
                    $steps[] = ['class' => ExtractJob::class, 'name' => $name, 'language' => $language, 'sample' => $sample];
                }
            } else {
                $steps[] = ['class' => ExtractJob::class, 'name' => $name];
            }
        }

        return $steps;
    }

    private function importSteps(array $parts, bool $sample): array
    {
        return array_map(
            fn(string $part) => ['class' => ImportJob::class, 'part' => $part, 'sample' => $sample],
            array_values(array_intersect(ImportJob::PARTS, $parts)),
        );
    }
}
