import Alpine from "alpinejs";
import { buildQueryString, templateRequest, withMinimumDelay } from "../common/helpers";
import toast from "../plugins/toastkit";

export function shippingCalculator() {
	return {
		loading: false,
		formErrors: {},
		resultsHtml: "",
		form: {
			countryCode: Alpine.$persist("BE").as("wauw:shipping-calculator:country"),
			postalCode: Alpine.$persist("").as("wauw:shipping-calculator:postal-code"),
		},

		init() {
			this.calculate();
		},

		validate(name) {
			return validateRules(this, name);
		},

		async calculate() {
			this.loading = true;

			try {
				const query = buildQueryString({
					productId: this.$root.querySelector('[name="productId"]').value,
					country: this.form.countryCode,
					postalCode: this.form.postalCode,
				});

				const { content } = await withMinimumDelay(templateRequest(`/api/shippingCosts${query}`));

				this.resultsHtml = content;
			} catch {
				toast("Er ging iets mis. Probeer het later opnieuw.", { kind: "error", dismissible: false });
			} finally {
				this.loading = false;
			}
		},
	};
}
