<?php

namespace modules\lightspeed\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Queue;
use craft\queue\BaseBatchedJob;
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
     * False while the job is pushed: Craft counts the items then, for the description, but earlier steps may not be done yet.
     * A pushed job is only unserialized again to run it.
     */
    private bool $running = false;

    /**
     * @param array[] $steps job configs (with `class`), in order
     */
    public static function queueAll(array $steps): void
    {
        foreach (array_values($steps) as $i => $step) {
            $job = Craft::createObject([...$step, 'priority' => self::FIRST_PRIORITY + $i]);
            Queue::push($job, $job->priority, null, $job->ttr);
        }
    }

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
        if ($this->earlierStepInQueue()) {
            throw new Exception('An earlier Lightspeed step is not done (failed or stopped). Retry that one first, then this one.');
        }

        parent::execute($queue);
    }

    protected function loadData(): Batchable
    {
        return new ArrayBatcher($this->running ? $this->loadItems() : []);
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
