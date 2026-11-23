<?php

namespace modules\wauw\controllers;

use Craft;
use craft\commerce\Plugin as CommercePlugin;
use craft\web\Controller;
use craft\web\Response;
use modules\wauw\services\ShippingPreview;

class CartController extends Controller {
    private const array PAYMENT_OPTIONS = [
        'bancontact' => 0,
        'ideal' => 0,
        'creditcard' => 108,
        'wire' => 0,
    ];

    private const string PAYMENT_METHOD_SESSION_KEY = 'wauw.cart.paymentMethodHandle';

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
            'shippingMethodHandle' => $cart->shippingMethodHandle,
            'shippingOptions' => ShippingPreview::optionsFor($cart, null, null),
            'paymentMethodHandle' => $this->getPaymentMethodHandle(),
            'paymentOptions' => $this->getPaymentOptions(),
        ]);
    }

    public function actionSetPaymentMethod(): Response {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('paymentMethodHandle');
        Craft::$app->getSession()->set(self::PAYMENT_METHOD_SESSION_KEY, $handle);

        return $this->asJson(['paymentMethodHandle' => $handle]);
    }

    private function getPaymentMethodHandle(): string {
        return Craft::$app->getSession()->get(self::PAYMENT_METHOD_SESSION_KEY, 'creditcard');
    }

    private function getPaymentOptions(): array {
        $currency = CommercePlugin::getInstance()->getStores()->getCurrentStore()->getCurrency()->getCode();

        $options = [];
        foreach (self::PAYMENT_OPTIONS as $handle => $price) {
            $options[$handle] = [
                'price' => $price,
                'priceAsCurrency' => $price > 0 ? Craft::$app->getFormatter()->asCurrency($price, $currency) : Craft::t('site', 'Free'),
            ];
        }

        return $options;
    }
}
