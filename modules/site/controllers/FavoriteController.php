<?php

namespace modules\site\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\web\Controller;
use craft\web\Response;
use yii\web\BadRequestHttpException;

/**
 * Toggles a product in the current user's favorite products field.
 */
class FavoriteController extends Controller {
    public function actionToggle(): Response {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireLogin();

        $productId = (int) $this->request->getRequiredBodyParam('productId');
        $product = Product::find()->id($productId)->status(null)->one();

        if (!$product) {
            throw new BadRequestHttpException();
        }

        $user = Craft::$app->getUser()->getIdentity();
        $favoriteIds = $user->getFieldValue('favoriteProductsField')->ids();
        $isFavorite = !in_array($productId, $favoriteIds, true);

        $favoriteIds = $isFavorite
            ? [...$favoriteIds, $productId]
            : array_values(array_diff($favoriteIds, [$productId]));

        $user->setFieldValue('favoriteProductsField', $favoriteIds);

        if (!Craft::$app->getElements()->saveElement($user)) {
            return $this->asFailure();
        }

        return $this->asJson(['isFavorite' => $isFavorite]);
    }
}
