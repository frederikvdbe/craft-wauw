<?php

namespace modules\lightspeed\console\controllers;

use craft\console\Controller;
use modules\lightspeed\services\Reset;
use yii\console\ExitCode;

/**
 * Deletes all imported Lightspeed data (orders, customers, products, brands, categories, images), for a fresh import.
 */
class ResetController extends Controller
{
    public function actionIndex(): int
    {
        $reset = new Reset();

        foreach ($reset->counts() as $label => $count) {
            $this->stdout("$label: $count\n");
        }
        if (!$this->confirm('Delete all of this for good? Admins, fields and settings stay.')) {
            return ExitCode::OK;
        }

        $deleted = $reset->run(function(string $label, int $done, int $total) {
            if ($done % 100 === 0 || $done === $total) {
                $this->stdout("$label: $done / $total\n");
            }
        });

        $this->stdout('Deleted: ' . implode(', ', array_map(fn($label, $count) => "$count $label", array_keys($deleted), $deleted)) . "\n");
        return ExitCode::OK;
    }
}
