import toast from "@mrcs/toastkit";
import { submitForm } from "../common/helpers";
import { email, lettersOnly, phone, required, validateRules } from "../common/validators";

export function myDetailsForm({ successMessage = null, initialData = {} } = {}) {
	return {
		form: { ...initialData },
		formErrors: {},
		loading: false,
		message: null,
		successMessage,

		fieldValidators: {
			customerTypeField: [required()],
			firstName: [required(), lettersOnly()],
			lastName: [required(), lettersOnly()],
			email: [required(), email()],
			phoneField: [phone()],
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
