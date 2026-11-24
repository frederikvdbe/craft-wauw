import { jsonRequest, templateRequest } from "../../common/helpers";

export function cart() {
	return {
		cartHtml: "",
		cart: { shippingOptions: {}, paymentOptions: {}, lineItems: [] },
		refreshToken: 0,

		init() {
			console.log("[cart store] init: fetching cart and listening for wauw:cart-change");

			this.refresh();

			window.addEventListener("wauw:cart-change", () => {
				console.log("[cart store] received wauw:cart-change → refreshing");
				this.refresh();
			});
		},

		async refresh() {
			const token = ++this.refreshToken;

			console.log("[cart store] refresh: fetching api/cart + /actions/wauw/cart/data");

			const [{ content }, cart] = await Promise.all([
				templateRequest(new URL("api/cart", window.site.baseUrl).toString()),
				jsonRequest("/actions/wauw/cart/data"),
			]);

			if (token !== this.refreshToken) {
				console.log("[cart store] refresh: discarded stale response (a newer refresh started)");
				return;
			}

			console.log("[cart store] refresh: done", cart);

			this.cartHtml = content;
			this.cart = cart;
		},

		shippingOptionPrice(handle) {
			return this.cart.shippingOptions[handle]?.priceAsCurrency ?? "Free";
		},

		get shippingPrice() {
			return this.shippingOptionPrice(this.cart.shippingMethodHandle);
		},

		get selectedShippingOption() {
			return this.cart.shippingOptions[this.cart.shippingMethodHandle] ?? null;
		},

		get paymentPrice() {
			return this.cart.paymentOptions[this.cart.paymentMethodHandle]?.priceAsCurrency ?? "€ _";
		},

		get totalQuantity() {
			return this.cart.totalQty;
		},

		get totalPrice() {
			return this.cart.totalPriceAsCurrency;
		},

		lineItemIdFor(purchasableId) {
			return this.cart.lineItems.find((lineItem) => lineItem.purchasableId === purchasableId)?.id ?? null;
		},

		isInCart(purchasableId) {
			return this.lineItemIdFor(purchasableId) !== null;
		},
	};
}
