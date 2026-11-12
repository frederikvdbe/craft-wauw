import { submitForm } from "../common/helpers";
import { matches, required, validateRules } from "../common/validators";

export function changePasswordForm({ successMessage = null } = {}) {
	const component = {
		form: {},
		formErrors: {},
		loading: false,
		message: null,
		successMessage,

		fieldValidators: {
			"password-currentPassword": [required()],
			newPassword: [required()],
			confirmNewPassword: [
				required(),
				matches(() => component.form.newPassword, "Wachtwoorden komen niet overeen"),
			],
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

	return component;
}
