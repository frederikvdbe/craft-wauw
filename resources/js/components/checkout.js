import { jsonRequest, submitCartForm, submitForm } from "../common/helpers";
import toast from "../plugins/toastkit";

export function checkout(initialData = {}) {
	return {
		loading: false,
		message: null,
		formErrors: {},
		form: { ...initialData },

		validate() {},

		init() {
			const formEl = this.$el.querySelector("form");

			for (const [key, value] of new FormData(formEl)) {
				console.log(key, value);
			}
		},

		async submit(event) {
			const form = event.target;
			const redirectInput = form.querySelector('input[name="redirect"]');

			this.setAction(form, "commerce/cart/update-cart");

			// commerce/cart/update-cart also honors the posted `redirect` field on success,
			// which would navigate away before the payment step ever runs.
			if (redirectInput) redirectInput.disabled = true;
			const cartResult = await submitCartForm(this, event);
			if (redirectInput) redirectInput.disabled = false;

			if (cartResult.message.type === "error") {
				toast(cartResult.message.text, { kind: cartResult.message.type, dismissible: false });
				return;
			}

			this.setAction(form, "commerce/payments/pay");
			const { message, redirect } = await submitForm(this, event);

			if (message.type === "error") {
				toast(message.text, { kind: message.type, dismissible: false });
				return;
			}

			if (redirect) {
				window.location.assign(redirect);
			}
		},

		setAction(form, action) {
			form.querySelector('input[name="action"]').value = action;
		},

		async updateShippingMethod(handle) {
			await jsonRequest("/actions/commerce/cart/update-cart", {
				method: "POST",
				headers: {
					"X-CSRF-Token": window.site.csrfTokenValue,
					"Content-Type": "application/x-www-form-urlencoded",
				},
				body: new URLSearchParams({ shippingMethodHandle: handle }),
			});

			window.dispatchEvent(new CustomEvent("wauw:cart-change"));
		},

		async updatePaymentMethod(handle) {
			await jsonRequest("/actions/wauw/cart/set-payment-method", {
				method: "POST",
				headers: {
					"X-CSRF-Token": window.site.csrfTokenValue,
					"Content-Type": "application/x-www-form-urlencoded",
				},
				body: new URLSearchParams({ paymentMethodHandle: handle }),
			});

			window.dispatchEvent(new CustomEvent("wauw:cart-change"));
		},
	};
}
