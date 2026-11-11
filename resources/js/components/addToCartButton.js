import { submitCartForm } from "../common/helpers";

export function addToCartButton(variantId) {
	return {
		variantId,
		loading: false,
		message: null,

		async submit(event) {
			await submitCartForm(this, event);
		},
	};
}
