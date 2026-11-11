<?php

namespace modules\lightspeed\jobs;

use GuzzleHttp\Exception\ClientException;
use modules\lightspeed\Module;
use modules\lightspeed\services\ApiData;
use modules\lightspeed\services\Extractor;
use modules\lightspeed\services\Sample;

/**
 * Downloads 1 file of the API export. A list: 1 item per language. A relation: 1 item per ID of its list.
 */
class ExtractJob extends SyncJob
{
    /**
     * @var string file name, see Extractor::names()
     */
    public string $name;

    /**
     * @var string|null relations only: the language (default: the shop's default language)
     */
    public ?string $language = null;

    /**
     * @var bool per-item lists: only the items of the sample. They stay in the partial file,
     * so a full extract later goes on from there.
     */
    public bool $sample = false;

    public int $batchSize = 50;

    /**
     * Long enough for a rate limit wait (max. 1 hour).
     */
    public ?int $ttr = 7200;

    private ?Extractor $extractor = null;
    private array $partial = [];
    private bool $onlySample = false;

    public function execute($queue): void
    {
        Module::getInstance()->client->onWait = fn(int $seconds) => $this->setProgress(
            $queue,
            $this->itemOffset / max($this->totalItems(), 1),
            "Rate limit: waiting {$seconds}s",
        );

        parent::execute($queue);
    }

    protected function loadItems(): array
    {
        if (!Extractor::isRelation($this->name)) {
            return $this->extractor()->languagesOf($this->name);
        }

        $ids = $this->extractor()->relationIds($this->name);
        $list = Extractor::RELATIONS[$this->name]['list'];
        if (!$this->sample || !in_array($list, Sample::LIMITED_LISTS, true)) {
            return $ids;
        }

        $sample = new Sample(ApiData::load(Module::getInstance()->store, sample: true));
        $this->onlySample = true;

        return array_values(array_intersect($ids, $sample->itemIds($list)));
    }

    protected function beforeBatch(): void
    {
        if (Extractor::isRelation($this->name)) {
            $this->partial = $this->extractor()->readPartial($this->name, $this->language());
        }
    }

    protected function processItem(mixed $item): void
    {
        try {
            if (!Extractor::isRelation($this->name)) {
                $this->extractor()->extractList($this->name, $item);
            } elseif (!isset($this->partial[$item])) {
                $this->partial[$item] = $this->extractor()->fetchRelation($this->name, $this->language(), $item);
            }
        } catch (ClientException $e) {
            $this->warn("$item: not available (HTTP {$e->getResponse()->getStatusCode()})");
        }
    }

    protected function afterBatch(): void
    {
        if (Extractor::isRelation($this->name)) {
            $this->extractor()->writePartial($this->name, $this->language(), $this->partial);
        }
    }

    protected function after(): void
    {
        if (Extractor::isRelation($this->name) && !$this->onlySample) {
            $this->extractor()->finishRelation($this->name, $this->language(), $this->partial);
        }
    }

    public function label(): string
    {
        return "extract: $this->name" . ($this->language ? " ($this->language)" : '') . ($this->sample ? ' (sample)' : '');
    }

    private function language(): string
    {
        return $this->language ?? $this->extractor()->primaryLanguage();
    }

    private function extractor(): Extractor
    {
        return $this->extractor ??= Module::getInstance()->extractor();
    }
}
