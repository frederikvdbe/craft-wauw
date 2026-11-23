export function radioGroup(fieldId, initialValue = "") {
	return {
		value: initialValue,

		init() {
			this.form[fieldId] = this.value;
			this.$watch("value", (value) => {
				this.form[fieldId] = value;
			});
		},
	};
}
