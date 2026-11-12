import { submitCartForm } from "../common/helpers";
import toast from "../plugins/toastkit";

export function removeFromCartButton(variantId) {
	return {
		variantId,
		loading: false,
		message: null,

		get lineItemId() {
			return this.$store.cart.lineItemIdFor(this.variantId);
		},

		async submit(event) {
			const { message } = await submitCartForm(this, event);

			if (message.type === "error") {
				toast(message.text, { kind: message.type, dismissible: false });
			}
		},
	};
}
