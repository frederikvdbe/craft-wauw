import { submitCartForm } from "../common/helpers";
import toast from "../plugins/toastkit";

export function shippingCalculator(initialData = {}) {
	return {
		loading: false,
		message: null,
		formErrors: {},
		form: { ...initialData },

		validate() {},

		async submit(event) {
			const { message } = await submitCartForm(this, event);

			if (message.type === "error") {
				toast(message.text, { kind: message.type, dismissible: false });
			}
		},
	};
}
