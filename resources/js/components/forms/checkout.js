import {
	buildQueryString,
	jsonRequest,
	submitCartForm,
	submitForm,
	templateRequest,
	withMinimumDelay,
} from "../../common/helpers";

import { required, validateRules } from "../../common/validators";
import toast from "../../plugins/toastkit";

export function checkout(initialData = {}) {
	return {
		loading: false,
		shippingOptionsLoading: false,
		message: null,
		formErrors: {},
		form: { ...initialData },

		fieldValidators: {
			"shippingAddress-firstName": [required()],
			"shippingAddress-lastName": [required()],
			"shippingAddress-email": [required()],
			"shippingAddress-customerType": [required()],
			"shippingAddress-addressLine1": [required()],
			"shippingAddress-locality": [required()],
			"shippingAddress-postalCode": [required()],
			"shippingAddress-countryCode": [required()],
		},

		validate(name) {
			return validateRules(this, name);
		},

		init() {
			this.$nextTick(() => this.refreshShippingOptions());
		},

		async submit(event) {
			if (!this.validate()) return;

			const form = event.target;
			const redirectInput = form.querySelector('input[name="redirect"]');

			this.setAction(form, "commerce/cart/update-cart");

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

			if (redirect) window.location.assign(redirect);
		},

		setAction(form, action) {
			form.querySelector('input[name="action"]').value = action;
		},

		shippingMethodAbortController: null,

		async updateShippingMethod(handle) {
			this.shippingMethodAbortController?.abort();
			this.shippingMethodAbortController = new AbortController();

			console.log("[checkout] updateShippingMethod: requesting", handle);

			// Commerce persists customer-facing notices (e.g. "shipping method no longer
			// available") in the DB and keeps re-adding them on every load until something
			// explicitly clears them - otherwise a stale notice from a past failed attempt
			// gets echoed back forever, even once the method applies successfully.
			const body = new URLSearchParams({ shippingMethodHandle: handle, clearNotices: "1" });
			const country = this.form["shippingAddress-countryCode"];
			const postalCode = this.form["shippingAddress-postalCode"];
			console.log("[checkout] updateShippingMethod: address in form state", { country, postalCode });

			// Use estimatedShippingAddress, not shippingAddress: Commerce runs full Address
			// validation (addressLine1, locality, etc.) on `shippingAddress`, so a partial
			// country+postalCode there fails validation and the whole update-cart save is
			// rolled back (nothing persists, not even shippingMethodHandle). estimatedShippingAddress
			// is exempt from that validation and is exactly what Order::getAvailableShippingMethodOptions()
			// falls back to for zone matching when there's no full shippingAddress yet.
			if (country) body.set("estimatedShippingAddress[countryCode]", country);
			if (postalCode) body.set("estimatedShippingAddress[postalCode]", postalCode);

			let data;
			try {
				data = await jsonRequest("/actions/commerce/cart/update-cart", {
					method: "POST",
					signal: this.shippingMethodAbortController.signal,
					headers: {
						"X-CSRF-Token": window.site.csrfTokenValue,
						"Content-Type": "application/x-www-form-urlencoded",
					},
					body,
				});
			} catch (error) {
				if (error.name === "AbortError") {
					console.log("[checkout] updateShippingMethod: aborted (superseded by a newer request)");
					return;
				}
				throw error;
			}

			console.log("[checkout] updateShippingMethod: response", data);

			// A hard validation failure (e.g. the cart itself didn't validate) means update-cart
			// never saved ANYTHING, including the shippingMethodHandle - not just a notice.
			if (data?.errors) {
				console.error("[checkout] updateShippingMethod: update-cart failed, nothing was saved", data);
				toast(data.message ?? "Er ging iets mis bij het instellen van de verzendmethode.", {
					kind: "error",
					dismissible: false,
				});
			}

			// Commerce silently reverts shippingMethodHandle (and adds a notice) when the
			// chosen method is no longer available once order adjusters have run, e.g. after
			// a discount changes the order total. Surface that instead of pretending it worked.
			const notices = data?.cart?.notices ?? [];
			if (notices.length) {
				console.warn("[checkout] updateShippingMethod: cart notices", notices);
				toast(notices.map((notice) => notice.message).join(" "), { kind: "error", dismissible: false });
			}

			if (data?.cart?.shippingMethodHandle && data.cart.shippingMethodHandle !== handle) {
				console.warn(
					`[checkout] updateShippingMethod: requested "${handle}" but cart kept "${data.cart.shippingMethodHandle}"`,
				);
			}

			console.log("[checkout] updateShippingMethod: dispatching wauw:cart-change");
			window.dispatchEvent(new CustomEvent("wauw:cart-change"));
		},

		async refreshShippingOptions() {
			const wrapper = document.getElementById("shipping-options-wrapper");
			if (!wrapper) return;

			this.shippingOptionsLoading = true;

			try {
				const query = buildQueryString({
					country: this.form["shippingAddress-countryCode"],
					postalCode: this.form["shippingAddress-postalCode"],
				});

				const { content } = await withMinimumDelay(templateRequest(`/api/shippingOptions${query}`));
				wrapper.outerHTML = content;
			} catch {
				toast("Er ging iets mis bij het ophalen van de verzendkosten.", { kind: "error", dismissible: false });
			} finally {
				this.shippingOptionsLoading = false;
			}
		},

		paymentMethodAbortController: null,

		async updatePaymentMethod(handle) {
			this.paymentMethodAbortController?.abort();
			this.paymentMethodAbortController = new AbortController();

			console.log("[checkout] updatePaymentMethod: requesting", handle);

			let data;
			try {
				data = await jsonRequest("/actions/wauw/cart/set-payment-method", {
					method: "POST",
					signal: this.paymentMethodAbortController.signal,
					headers: {
						"X-CSRF-Token": window.site.csrfTokenValue,
						"Content-Type": "application/x-www-form-urlencoded",
					},
					body: new URLSearchParams({ paymentMethodHandle: handle }),
				});
			} catch (error) {
				if (error.name === "AbortError") {
					console.log("[checkout] updatePaymentMethod: aborted (superseded by a newer request)");
					return;
				}
				throw error;
			}

			console.log("[checkout] updatePaymentMethod: response", data);
			console.log("[checkout] updatePaymentMethod: dispatching wauw:cart-change");
			window.dispatchEvent(new CustomEvent("wauw:cart-change"));
		},
	};
}
