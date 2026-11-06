<?php

namespace modules\lightspeed\services;

use Craft;
use craft\base\Element;
use yii\base\Exception;

trait SavesElements
{
    private function save(Element $element): void
    {
        if (!Craft::$app->getElements()->saveElement($element)) {
            throw new Exception(sprintf(
                'Could not save %s "%s": %s',
                $element::class,
                $element,
                implode(', ', $element->getErrorSummary(true)),
            ));
        }
    }
}
