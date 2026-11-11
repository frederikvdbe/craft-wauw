import { submitCartForm } from "../common/helpers";

export function removeFromCartButton(variantId) {
	return {
		variantId,
		loading: false,
		message: null,

		get lineItemId() {
			return this.$store.cart.lineItemIdFor(this.variantId);
		},

		async submit(event) {
			await submitCartForm(this, event);
		},
	};
}
