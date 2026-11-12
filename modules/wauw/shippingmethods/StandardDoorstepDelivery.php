<?php

namespace modules\wauw\shippingmethods;

use craft\commerce\elements\Order;

class StandardDoorstepDelivery extends ShippingMethod {
    /**
     * Stand-in for the real rate lookup (third-party API, by location + product
     * weight/dimensions) until that integration exists. Fakes a location-,
     * weight- and size-based rate, just to demo that the price isn't fixed: a
     * base rate by shipping zone, plus a per-kg surcharge over a free
     * allowance, plus a flat fee if any line item is oversized.
     */
    private const DOMESTIC_PRICE = 50.0;
    private const NEIGHBOUR_PRICE = 100.0;
    private const REST_OF_WORLD_PRICE = 150.0;
    private const NEIGHBOURING_COUNTRIES = ['NL', 'FR', 'DE', 'LU'];

    private const FREE_WEIGHT_ALLOWANCE = 10.0; // kg
    private const PRICE_PER_EXTRA_KG = 2.0;

    private const OVERSIZED_DIMENSION = 100.0;
    private const OVERSIZED_FEE = 75.0;

    public function init(): void {
        parent::init();

        $this->name = 'Standard doorstep delivery';
        $this->handle = 'standardDoorstepDelivery';
        $this->description = 'Careful delivery to your door.';
        $this->tooltip = 'More info';
    }

    public function getPriceForOrder(Order $order): float {
        return self::getRateForOrder($order);
    }

    public static function getRateForOrder(Order $order): float {
        return self::getZoneRate($order) + self::getWeightSurcharge($order) + self::getOversizedSurcharge($order);
    }

    private static function getZoneRate(Order $order): float {
        $countryCode = $order->getShippingAddress()?->countryCode;

        return match (true) {
            $countryCode === 'BE' => self::DOMESTIC_PRICE,
            in_array($countryCode, self::NEIGHBOURING_COUNTRIES, true) => self::NEIGHBOUR_PRICE,
            default => self::REST_OF_WORLD_PRICE,
        };
    }

    private static function getWeightSurcharge(Order $order): float {
        $extraWeight = max(0.0, $order->getTotalWeight() - self::FREE_WEIGHT_ALLOWANCE);

        return $extraWeight * self::PRICE_PER_EXTRA_KG;
    }

    private static function getOversizedSurcharge(Order $order): float {
        foreach ($order->getLineItems() as $lineItem) {
            $longestSide = max($lineItem->length, $lineItem->width, $lineItem->height);

            if ($longestSide > self::OVERSIZED_DIMENSION) {
                return self::OVERSIZED_FEE;
            }
        }

        return 0.0;
    }
}
