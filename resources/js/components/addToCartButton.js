import { submitCartForm } from "../common/helpers";
import toast from "../plugins/toastkit";

export function addToCartButton(variantId) {
	return {
		variantId,
		loading: false,
		message: null,

		async submit(event) {
			const { message } = await submitCartForm(this, event);

			if (message.type === "error") {
				toast(message.text, { kind: message.type, dismissible: false });
			}
		},
	};
}
