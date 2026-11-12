import Alpine from "alpinejs";

import * as components from "../components";

window.Alpine = Alpine;

Alpine.data("site", components.site);
Alpine.data("address", components.address);
Alpine.data("loginForm", components.loginForm);
Alpine.data("registerForm", components.registerForm);
Alpine.data("forgotPasswordForm", components.forgotPasswordForm);
Alpine.data("setPasswordForm", components.setPasswordForm);
Alpine.data("changeEmailForm", components.changeEmailForm);
Alpine.data("changePasswordForm", components.changePasswordForm);
Alpine.data("myDetailsForm", components.myDetailsForm);
Alpine.data("invoicingForm", components.invoicingForm);
Alpine.data("vipPreviewForm", components.vipPreviewForm);
Alpine.data("search", components.search);
Alpine.data("modal", components.modal);
Alpine.data("favorite", components.favorite);
Alpine.data("share", components.share);
Alpine.data("addToCartButton", components.addToCartButton);
Alpine.data("removeFromCartButton", components.removeFromCartButton);
Alpine.data("autocomplete", components.autocomplete);
Alpine.data("checkboxGroup", components.checkboxGroup);
Alpine.data("checkout", components.checkout);
Alpine.data("radioGroup", components.radioGroup);
Alpine.data("datepicker", components.datepicker);
Alpine.data("daterangepicker", components.daterangepicker);
Alpine.data("richText", components.richText);
Alpine.data("tagList", components.tagList);
Alpine.data("media", components.media);
Alpine.data("shippingCalculator", components.shippingCalculator);

Alpine.store("cart", components.cart());

Alpine.start();
