<?php

namespace modules\lightspeed\jobs;

use craft\base\Batchable;

class ArrayBatcher implements Batchable
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = array_values($items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getSlice(int $offset, int $limit): iterable
    {
        return array_slice($this->items, $offset, $limit);
    }
}
