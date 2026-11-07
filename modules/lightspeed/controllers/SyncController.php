<?php

namespace modules\lightspeed\controllers;

use craft\helpers\UrlHelper;
use craft\web\Controller;
use modules\lightspeed\jobs\ImportJob;
use modules\lightspeed\Module;
use modules\lightspeed\services\Extractor;
use modules\lightspeed\utilities\SyncUtility;
use yii\base\Exception;
use yii\web\Response;

/**
 * @property Module $module
 */
class SyncController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('utility:' . SyncUtility::id());
        return parent::beforeAction($action);
    }

    public function actionStart(): ?Response
    {
        $this->requirePostRequest();

        $extract = $this->selected('extract', Extractor::names());
        $import = $this->selected('import', ImportJob::PARTS);
        if (!$extract && !$import) {
            return $this->asFailure('Select something to extract or import.');
        }

        try {
            $count = $this->module->sync->queue($extract, $import, (bool)$this->request->getBodyParam('sample'));
        } catch (Exception $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess("$count steps queued.", redirect: UrlHelper::cpUrl('utilities/queue-manager'));
    }

    /**
     * Checkbox select: `*` = all, '' = none.
     */
    private function selected(string $param, array $all): array
    {
        $values = $this->request->getBodyParam($param);
        if ($values === '*') {
            return $all;
        }

        return array_values(array_intersect($all, is_array($values) ? $values : []));
    }
}
