export function address(idPrefix) {
	return {
		streetName: "",
		number: "",

		get addressLine1() {
			return [this.streetName, this.number].filter(Boolean).join(" ").trim();
		},

		init() {
			if (!this.form) return;

			this.form[`${idPrefix}-addressLine1`] = this.addressLine1;
			this.$watch("addressLine1", (value) => {
				this.form[`${idPrefix}-addressLine1`] = value;
			});
		},
	};
}
