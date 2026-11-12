import { jsonRequest, templateRequest } from "../common/helpers";

export function cart() {
	return {
		cartHtml: "",
		cart: {
			totalQty: 0,
			totalPrice: 0,
			totalPriceAsCurrency: "",
			lineItems: [],
			shippingMethodHandle: "",
			shippingOptions: {},
			paymentMethodHandle: "",
			paymentOptions: {},
		},

		init() {
			this.refresh();

			window.addEventListener("wauw:cart-change", () => this.refresh());
		},

		async refresh() {
			const [{ content }, cart] = await Promise.all([
				templateRequest(new URL("api/cart", window.site.baseUrl).toString()),
				jsonRequest("/actions/wauw/cart/data"),
			]);

			this.cartHtml = content;
			this.cart = cart;
		},

		shippingOptionPrice(handle) {
			return this.cart.shippingOptions[handle]?.priceAsCurrency ?? "€ _";
		},

		get shippingPrice() {
			return this.shippingOptionPrice(this.cart.shippingMethodHandle);
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
