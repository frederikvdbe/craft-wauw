<?php

namespace modules\site\controllers;

use craft\commerce\Plugin as CommercePlugin;
use craft\web\Controller;
use craft\web\Response;

/**
 * Returns the current cart's totals and line items as a small JSON payload.
 * Commerce's own commerce/cart/get-cart serializes the entire Order model;
 * the front-end cart store only ever needs these few fields.
 */
class CartController extends Controller {
    protected array|bool|int $allowAnonymous = true;

    public function actionData(): Response {
        $this->requireAcceptsJson();

        $cart = CommercePlugin::getInstance()->getCarts()->getCart();

        return $this->asJson([
            'totalQty' => $cart->getTotalQty(),
            'totalPrice' => $cart->getTotalPrice(),
            'totalPriceAsCurrency' => $cart->totalPriceAsCurrency,
            'lineItems' => array_map(fn($lineItem) => [
                'id' => $lineItem->id,
                'purchasableId' => $lineItem->purchasableId,
                'qty' => $lineItem->qty,
            ], $cart->getLineItems()),
        ]);
    }
}
