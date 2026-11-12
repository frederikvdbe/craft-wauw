import toast from "@mrcs/toastkit";
import { submitForm } from "../common/helpers";
import { required, validateRules } from "../common/validators";

export function invoicingForm({ successMessage = null, initialData = {} } = {}) {
	return {
		form: { ...initialData },
		formErrors: {},
		loading: false,
		message: null,
		successMessage,

		fieldValidators: {
			"invoicingAddress-addressLine1": [required()],
			locality: [required()],
			postalCode: [required()],
			countryCode: [required()],
		},

		validate(name) {
			return validateRules(this, name);
		},

		async submit(event) {
			if (!this.validate()) return;

			const { message } = await submitForm(this, event);

			toast(message.text, { kind: message.type, dismissible: false });
		},
	};
}
