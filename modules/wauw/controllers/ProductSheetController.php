<?php

namespace modules\wauw\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\web\Controller;
use craft\web\Response;
use modules\wauw\services\SpecSheetGenerator;
use yii\web\NotFoundHttpException;

class ProductSheetController extends Controller {
    protected array|bool|int $allowAnonymous = true;

    public function actionIndex(string $slug): Response {
        $product = Product::find()->slug($slug)->status(null)->one();
        $asset = $product ? SpecSheetGenerator::assetFor($product) : null;

        if (!$asset) throw new NotFoundHttpException();

        return Craft::$app->getResponse()->sendContentAsFile($asset->getContents(), $asset->filename, [
            'mimeType' => 'application/pdf',
        ]);
    }
}
