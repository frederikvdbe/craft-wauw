import { submitCartForm, submitForm } from "../common/helpers";

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

			this.setAction(form, "commerce/cart/update-cart");
			await submitCartForm(this, event);

			if (this.message?.type === "error") {
				return;
			}

			this.setAction(form, "commerce/payment/pay");
			await submitForm(this, event);
		},

		setAction(form, action) {
			form.querySelector('input[name="action"]').value = action;
		},
	};
}
