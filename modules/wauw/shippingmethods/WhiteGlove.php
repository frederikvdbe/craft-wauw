<?php

namespace modules\wauw\shippingmethods;

use craft\commerce\elements\Order;

class WhiteGlove extends ShippingMethod {
    /**
     * On top of the doorstep delivery rate: ground-floor placement fee.
     */
    public const EXTRA_FEE = 100.0;

    public function init(): void {
        parent::init();

        $this->name = 'White glove';
        $this->handle = 'whiteGlove';
        $this->description = 'Delivery and ground-floor placement.';
        $this->tooltip = 'More info';
    }

    public function getPriceForOrder(Order $order): float {
        return StandardDoorstepDelivery::getRateForOrder($order) + self::EXTRA_FEE;
    }
}
