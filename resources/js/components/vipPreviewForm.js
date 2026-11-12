import toast from "@mrcs/toastkit";
import { submitForm } from "../common/helpers";
import { validateRules } from "../common/validators";

export function vipPreviewForm({ successMessage = null } = {}) {
	return {
		form: {},
		formErrors: {},
		loading: false,
		message: null,
		successMessage,

		fieldValidators: {},

		validate(name) {
			return validateRules(this, name);
		},

		async submit(event) {
			const { message } = await submitForm(this, event);

			toast(message.text, { kind: message.type, dismissible: false });
		},
	};
}
