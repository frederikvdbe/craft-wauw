<?php

namespace modules\wauw\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as CommercePlugin;

class ShippingPreview {
    private const array METHOD_COPY = [
        'whiteGlove' => [
            'description' => 'Delivery and ground-floor placement.',
            'tooltip' => "Your piece is delivered to your home and installed on the ground floor, at a time arranged together with our team.\n\nIt is carried in, unpacked and placed with care, so there is nothing left for you to do.\n\nOur team will assist you during installation and provide the necessary advice to use your piece safely.",
        ],
        'standardDoorstepDelivery' => [
            'description' => 'Careful delivery to your door.',
            'tooltip' => "Your piece is delivered to the entrance of your home, at a time arranged together with our delivery partner.\n\nCarrying it further inside or unpacking it is left to you, so make sure some extra hands are available if needed.\n\nOur team remains available beforehand to provide the necessary advice to receive your piece safely.",
        ],
        'galleryPickup' => [
            'description' => 'Pickup at WAUW by appointment.',
            'tooltip' => "Collect your piece personally at WAUW, at a time arranged together with our team.\n\nYour purchase will be carefully stored free of charge for up to 30 days, giving you enough time to organise your pickup.\n\nOur team will assist you during handover and provide the necessary advice to take your piece with you safely.",
        ],
    ];

    public static function optionsFor(Order $cart, ?string $country, ?string $postalCode): array {
        $order = clone $cart;

        if ($country || $postalCode) {
            $order->setShippingAddress(array_filter([
                'countryCode' => $country,
                'postalCode' => $postalCode,
            ]));
        }

        return self::buildOptions($order);
    }

    public static function optionsForProduct(Variant $variant, ?string $country, ?string $postalCode): array {
        $order = Craft::createObject([
            'class' => Order::class,
            'attributes' => [
                'orderSiteId' => Craft::$app->getSites()->getCurrentSite()->id,
                'storeId' => CommercePlugin::getInstance()->getStores()->getCurrentStore()->id,
            ],
        ]);

        $order->addLineItem(CommercePlugin::getInstance()->getLineItems()->create($order, ['purchasable' => $variant]));

        if ($country || $postalCode) {
            $order->setShippingAddress(array_filter([
                'countryCode' => $country,
                'postalCode' => $postalCode,
            ]));
        }

        return self::buildOptions($order);
    }

    private static function buildOptions(Order $order): array {
        $options = [];
        foreach ($order->getAvailableShippingMethodOptions() as $option) {
            $copy = self::METHOD_COPY[$option->getHandle()] ?? ['description' => '', 'tooltip' => ''];

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
}
