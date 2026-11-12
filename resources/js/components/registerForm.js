import { submitForm } from "../common/helpers";
import { email, lettersOnly, required, validateRules } from "../common/validators";

export function registerForm({ successMessage = null } = {}) {
	return {
		form: {},
		formErrors: {},
		loading: false,
		message: null,
		successMessage,

		fieldValidators: {
			firstName: [required(), lettersOnly()],
			lastName: [required(), lettersOnly()],
			email: [required(), email()],
		},

		validate(name) {
			return validateRules(this, name);
		},

		async submit(event) {
			const { message, redirect } = await submitForm(this, event);
			this.message = message;

			if (redirect) window.location.assign(redirect);
		},
	};
}
