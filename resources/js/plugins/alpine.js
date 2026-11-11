import Alpine from "alpinejs";

import * as components from "../components";

window.Alpine = Alpine;

Alpine.data("site", components.site);
Alpine.data("search", components.search);
Alpine.data("modal", components.modal);
Alpine.data("favorite", components.favorite);
Alpine.data("share", components.share);
Alpine.data("addToCartButton", components.addToCartButton);
Alpine.data("removeFromCartButton", components.removeFromCartButton);
Alpine.data("autocomplete", components.autocomplete);
Alpine.data("checkboxGroup", components.checkboxGroup);
Alpine.data("datepicker", components.datepicker);
Alpine.data("daterangepicker", components.daterangepicker);
Alpine.data("richText", components.richText);
Alpine.data("tagList", components.tagList);
Alpine.data("media", components.media);

Alpine.store("cart", components.cart());

Alpine.start();
