<?php

namespace modules\wauw\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as CommercePlugin;
use craft\web\Controller;
use craft\web\Response;

/**
 * Returns the current cart's totals, line items and shipping option prices as a small
 * JSON payload. Commerce's own commerce/cart/get-cart serializes the entire Order model;
 * the front-end cart store only ever needs these few fields.
 */
class CartController extends Controller {
    private const array PAYMENT_OPTIONS = [
        'bancontact' => 0,
        'ideal' => 0,
        'creditcard' => 108,
        'wire' => 0,
    ];

    private const string PAYMENT_METHOD_SESSION_KEY = 'wauw.cart.paymentMethodHandle';

    private const array SHIPPING_METHOD_COPY = [
        'whiteGlove' => ['description' => 'Delivery and ground-floor placement.', 'tooltip' => 'More info'],
        'standardDoorstepDelivery' => ['description' => 'Careful delivery to your door.', 'tooltip' => 'More info'],
        'galleryPickup' => ['description' => 'Pickup at WAUW by appointment.', 'tooltip' => 'More info'],
    ];

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
            'shippingOptions' => $this->getShippingOptions($cart),
            'paymentMethodHandle' => $this->getPaymentMethodHandle(),
            'paymentOptions' => $this->getPaymentOptions(),
        ]);
    }

    /**
     * Remembers the chosen payment method for the duration of the session, so the cart
     * overview can show its processing fee before the order is actually placed.
     */
    public function actionSetPaymentMethod(): Response {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('paymentMethodHandle');
        Craft::$app->getSession()->set(self::PAYMENT_METHOD_SESSION_KEY, $handle);

        return $this->asJson(['paymentMethodHandle' => $handle]);
    }

    /**
     * The shipping calculator lets a visitor price shipping to a different location than
     * the cart's own shipping address, so this is computed on a clone of the cart that's
     * never saved.
     */
    private function getShippingOptions(Order $cart): array {
        $request = Craft::$app->getRequest();
        $country = $request->getQueryParam('shippingCountry');
        $postalCode = $request->getQueryParam('shippingPostalCode');

        $order = clone $cart;
        if ($country || $postalCode) {
            $order->setShippingAddress(array_filter([
                'countryCode' => $country,
                'postalCode' => $postalCode,
            ]));
        }

        $options = [];
        foreach ($order->getAvailableShippingMethodOptions() as $option) {
            $copy = self::SHIPPING_METHOD_COPY[$option->getHandle()] ?? ['description' => '', 'tooltip' => ''];

            $options[$option->getHandle()] = [
                'name' => $option->getName(),
                'description' => $copy['description'],
                'tooltip' => $copy['tooltip'],
                'price' => $option->price,
                'priceAsCurrency' => $option->price > 0 ? $option->priceAsCurrency : Craft::t('site', 'Free'),
            ];
        }

        return $options;
    }

    /**
     * Defaults to the same payment method the checkout form pre-selects.
     */
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
