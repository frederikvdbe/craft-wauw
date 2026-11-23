export function checkboxGroup(fieldId, initialValues = []) {
	return {
		values: [...initialValues],

		init() {
			this.form[fieldId] = this.values;
			this.$watch("values", (values) => {
				this.form[fieldId] = values;
			});
		},

		toggle(value) {
			this.values = this.values.includes(value)
				? this.values.filter((existing) => existing !== value)
				: [...this.values, value];
		},
	};
}
