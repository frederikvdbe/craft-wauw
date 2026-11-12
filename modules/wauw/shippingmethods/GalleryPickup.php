<?php

namespace modules\wauw\shippingmethods;

class GalleryPickup extends ShippingMethod {
    public function init(): void {
        parent::init();

        $this->name = 'Gallery pickup';
        $this->handle = 'galleryPickup';
        $this->description = 'Pickup at WAUW by appointment.';
        $this->tooltip = 'More info';
        $this->price = 0.0;
    }
}
