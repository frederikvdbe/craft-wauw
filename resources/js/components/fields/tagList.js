export function tagList(fieldId, initialValues = []) {
	return {
		values: [...initialValues],
		inputValue: "",

		init() {
			this.form[fieldId] = this.values;
			this.$watch("values", (values) => {
				this.form[fieldId] = values;
			});
		},

		add() {
			const value = this.inputValue.trim();
			if (!value || this.values.includes(value)) return;

			this.values = [...this.values, value];
			this.inputValue = "";
		},

		remove(index) {
			this.values = this.values.filter((_, i) => i !== index);
		},
	};
}
