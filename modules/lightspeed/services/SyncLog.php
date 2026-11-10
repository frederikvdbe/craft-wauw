<?php

namespace modules\lightspeed\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use modules\lightspeed\Module;
use yii\base\Component;
use yii\base\Exception;

/**
 * History of the last syncs: per step its status, items, warnings and error.
 * Written by the queue runner, read by the utility.
 */
class SyncLog extends Component
{
    public const WAITING = 'waiting';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /**
     * Not in the queue anymore, but not done: the process was stopped, or the job was released.
     */
    public const STOPPED = 'stopped';

    public string $path = '@storage/lightspeed/sync-log.json';

    private const MAX_RUNS = 10;
    private const MAX_WARNINGS = 50;
    private const MAX_ERROR_LENGTH = 1000;

    /**
     * @param string[] $labels 1 per step, in order
     * @return string run ID
     */
    public function start(array $labels, bool $sample): string
    {
        $id = StringHelper::UUID();

        $this->change(function(array $runs) use ($id, $labels, $sample) {
            array_unshift($runs, [
                'id' => $id,
                'startedAt' => time(),
                'sample' => $sample,
                'steps' => array_map(fn(string $label) => [
                    'label' => $label,
                    'status' => self::WAITING,
                    'startedAt' => null,
                    'finishedAt' => null,
                    'items' => null,
                    'done' => 0,
                    'warnings' => [],
                    'warningCount' => 0,
                    'error' => null,
                ], $labels),
            ]);

            return array_slice($runs, 0, self::MAX_RUNS);
        });

        return $id;
    }

    public function running(string $runId, int $step, int $items): void
    {
        $this->changeStep($runId, $step, fn(array $s) => [
            ...$s,
            'status' => self::RUNNING,
            'startedAt' => $s['startedAt'] ?? time(),
            'items' => $items,
            'error' => null,
        ]);
    }

    /**
     * @param string[] $warnings new warnings of this batch
     */
    public function progress(string $runId, int $step, int $done, bool $finished, array $warnings): void
    {
        $this->changeStep($runId, $step, fn(array $s) => [
            ...$s,
            'status' => $finished ? self::DONE : $s['status'],
            'finishedAt' => $finished ? time() : null,
            'done' => $done,
            'warnings' => array_slice([...$s['warnings'], ...$warnings], 0, self::MAX_WARNINGS),
            'warningCount' => $s['warningCount'] + count($warnings),
        ]);
    }

    public function failed(string $runId, int $step, string $error, array $warnings): void
    {
        $this->changeStep($runId, $step, fn(array $s) => [
            ...$s,
            'status' => self::FAILED,
            'finishedAt' => time(),
            'error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH),
            'warnings' => array_slice([...$s['warnings'], ...$warnings], 0, self::MAX_WARNINGS),
            'warningCount' => $s['warningCount'] + count($warnings),
        ]);
    }

    /**
     * Newest first. Only the newest run can still be in the queue: open steps of the others are stopped.
     */
    public function runs(): array
    {
        $runs = $this->read();
        $queued = Module::getInstance()->sync->isQueued();

        foreach ($runs as $i => &$run) {
            $active = $queued && $i === 0;
            foreach ($run['steps'] as &$step) {
                if (!$active && in_array($step['status'], [self::WAITING, self::RUNNING], true)) {
                    $step['status'] = self::STOPPED;
                }
            }
            unset($step);
            $run['status'] = $this->runStatus($run['steps']);
        }

        return $runs;
    }

    /**
     * Failed or stopped steps of the newest run.
     */
    public function problemCount(): int
    {
        $run = $this->runs()[0] ?? null;
        if (!$run) {
            return 0;
        }

        return count(array_filter($run['steps'], fn(array $step) => in_array($step['status'], [self::FAILED, self::STOPPED], true)));
    }

    private function runStatus(array $steps): string
    {
        $statuses = array_column($steps, 'status');
        foreach ([self::FAILED, self::STOPPED, self::RUNNING, self::WAITING] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return self::DONE;
    }

    private function changeStep(string $runId, int $step, callable $change): void
    {
        $this->change(function(array $runs) use ($runId, $step, $change) {
            foreach ($runs as &$run) {
                if ($run['id'] === $runId && isset($run['steps'][$step])) {
                    $run['steps'][$step] = $change($run['steps'][$step]);
                }
            }

            return $runs;
        });
    }

    /**
     * @param callable(array): array $change
     */
    private function change(callable $change): void
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::class, 10)) {
            throw new Exception('Could not lock the Lightspeed sync log.');
        }

        try {
            $path = Craft::getAlias($this->path);
            FileHelper::writeToFile($path, Json::encode($change($this->read()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } finally {
            $mutex->release(self::class);
        }
    }

    private function read(): array
    {
        $path = Craft::getAlias($this->path);
        return file_exists($path) ? Json::decode(file_get_contents($path)) ?? [] : [];
    }
}
