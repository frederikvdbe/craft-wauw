import { submitCartForm } from "../common/helpers";
import toast from "../plugins/toastkit";

export function addToCartButton(variantId) {
	return {
		variantId,
		loading: false,
		message: null,

		async submit(event) {
			const { message } = await submitCartForm(this, event, { scroll: false });

			if (message.type === "error") {
				toast(message.text, { kind: message.type, dismissible: false });
			} else {
				window.dispatchEvent(new CustomEvent("wauw:modal-open", { detail: { id: "cart-modal" } }));
			}
		},
	};
}
