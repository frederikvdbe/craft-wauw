<?php

namespace modules\lightspeed\jobs;

use Craft;
use GuzzleHttp\Exception\ClientException;
use modules\lightspeed\Module;
use modules\lightspeed\services\Extractor;

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

    public int $batchSize = 50;

    /**
     * Long enough for a rate limit wait (max. 1 hour).
     */
    public ?int $ttr = 7200;

    private ?Extractor $extractor = null;
    private array $partial = [];

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
        return Extractor::isRelation($this->name)
            ? $this->extractor()->relationIds($this->name)
            : $this->extractor()->languagesOf($this->name);
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
            Craft::warning("Lightspeed $this->name ($item): not available (HTTP {$e->getResponse()->getStatusCode()})", 'lightspeed');
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
        if (Extractor::isRelation($this->name)) {
            $this->extractor()->finishRelation($this->name, $this->language(), $this->partial);
        }
    }

    protected function defaultDescription(): ?string
    {
        return self::DESCRIPTION_PREFIX . " extract: $this->name" . ($this->language ? " ($this->language)" : '');
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
