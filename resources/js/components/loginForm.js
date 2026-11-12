import { submitForm } from "../common/helpers";
import { required, validateRules } from "../common/validators";

export function loginForm() {
	return {
		form: {},
		formErrors: {},
		loading: false,
		message: null,

		fieldValidators: {
			loginName: [required()],
			password: [required()],
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
