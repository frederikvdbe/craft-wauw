<?php

namespace modules\wauw\shippingmethods;

use craft\commerce\base\ShippingMethod as BaseShippingMethod;
use craft\commerce\elements\Order;

/**
 * Shared behaviour for WAUW's plugin-provided shipping methods: always enabled, always
 * matches, flat rate set by the concrete method. `$description` / `$tooltip` exist because
 * Commerce's own CP shipping methods have no field for that.
 */
abstract class ShippingMethod extends BaseShippingMethod {
    public ?string $description = null;
    public ?string $tooltip = null;
    public float $price = 0.0;

    public function getType(): string {
        return 'WAUW';
    }

    public function getId(): ?int {
        return null;
    }

    public function getName(): string {
        return (string)$this->name;
    }

    public function getHandle(): string {
        return (string)$this->handle;
    }

    public function getCpEditUrl(): string {
        return '';
    }

    public function getIsEnabled(): bool {
        return true;
    }

    public function matchOrder(Order $order): bool {
        return true;
    }

    public function getPriceForOrder(Order $order): float {
        return $this->price;
    }
}
