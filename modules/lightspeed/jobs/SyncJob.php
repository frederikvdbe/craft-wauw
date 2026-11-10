<?php

namespace modules\lightspeed\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Queue;
use craft\queue\BaseBatchedJob;
use modules\lightspeed\Module;
use modules\lightspeed\services\SyncLog;
use Throwable;
use yii\base\Exception;

/**
 * A step of the Lightspeed sync. All steps are queued at once, each with a higher priority number than the one before.
 * The queue runs the lowest number first, and new batches of a step keep its priority, so the steps run in order.
 */
abstract class SyncJob extends BaseBatchedJob
{
    public const DESCRIPTION_PREFIX = 'Lightspeed';

    /**
     * Before Craft's default (1024): the sync goes before other jobs.
     */
    private const FIRST_PRIORITY = 100;

    /**
     * @var string|null the run in the sync log (see SyncLog)
     */
    public ?string $runId = null;

    /**
     * @var int|null index of this step in the run
     */
    public ?int $step = null;

    /**
     * False while the job is pushed: Craft counts the items then, for the description, but earlier steps may not be done yet.
     * A pushed job is only unserialized again to run it.
     */
    private bool $running = false;

    /**
     * Craft loads the items for the log line before `execute()`, outside its error handling:
     * an error there kills the queue runner and leaves the job reserved. So keep it for `execute()`.
     */
    private ?Throwable $loadError = null;

    /**
     * Warnings of the current batch, for the sync log.
     *
     * @var string[]
     */
    private array $warnings = [];

    /**
     * @param SyncJob[] $jobs in order
     */
    public static function queueAll(array $jobs, string $runId): void
    {
        foreach (array_values($jobs) as $i => $job) {
            $job->runId = $runId;
            $job->step = $i;
            $job->priority = self::FIRST_PRIORITY + $i;
            Queue::push($job, $job->priority, null, $job->ttr);
        }
    }

    /**
     * E.g. `import: orders (sample)`.
     */
    abstract public function label(): string;

    /**
     * @return array the items to process; only called when the job runs
     */
    abstract protected function loadItems(): array;

    public function __wakeup(): void
    {
        parent::__wakeup();
        $this->running = true;
    }

    public function execute($queue): void
    {
        try {
            if ($this->earlierStepInQueue()) {
                throw new Exception('An earlier Lightspeed step is not done (failed or stopped). Retry that one first, then this one.');
            }

            $this->data();
            if ($this->loadError) {
                throw $this->loadError;
            }

            $this->log(fn(SyncLog $log) => $log->running($this->runId, $this->step, $this->totalItems()));
            parent::execute($queue);
        } catch (Throwable $e) {
            $this->log(fn(SyncLog $log) => $log->failed($this->runId, $this->step, $e->getMessage(), $this->warnings));
            throw $e;
        }

        $finished = $this->itemOffset >= $this->totalItems();
        $this->log(fn(SyncLog $log) => $log->progress($this->runId, $this->step, $this->itemOffset, $finished, $this->warnings));
    }

    protected function defaultDescription(): ?string
    {
        return self::DESCRIPTION_PREFIX . ' ' . $this->label();
    }

    /**
     * Logs a warning, and keeps it for the sync log.
     */
    protected function warn(string $message): void
    {
        Craft::warning(self::DESCRIPTION_PREFIX . ' ' . $this->label() . ": $message", 'lightspeed');
        $this->warnings[] = $message;
    }

    protected function loadData(): Batchable
    {
        if (!$this->running) {
            return new ArrayBatcher([]);
        }

        try {
            return new ArrayBatcher($this->loadItems());
        } catch (Throwable $e) {
            $this->loadError = $e;
            return new ArrayBatcher([]);
        }
    }

    /**
     * Jobs queued without a run (before the log existed) are not logged.
     *
     * @param callable(SyncLog): void $write
     */
    private function log(callable $write): void
    {
        if ($this->runId !== null && $this->step !== null) {
            $write(Module::getInstance()->syncLog);
        }
    }

    /**
     * A failed step, or a step whose process was stopped (it stays reserved until its TTR ends):
     * the queue would go on with the next steps.
     */
    private function earlierStepInQueue(): bool
    {
        return (new Query())
            ->from(Table::QUEUE)
            ->where(['<', 'priority', $this->priority])
            ->andWhere(['like', 'description', self::DESCRIPTION_PREFIX . '%', false])
            ->exists();
    }
}
