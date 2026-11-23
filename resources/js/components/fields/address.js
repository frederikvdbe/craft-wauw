export function address(idPrefix, addressLine1 = "") {
	const [streetName, number] = splitAddressLine1(addressLine1);

	return {
		streetName,
		number,

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

function splitAddressLine1(value) {
	const match = value.trim().match(/^(.*\S)\s+(\S+)$/);

	return match ? [match[1], match[2]] : [value.trim(), ""];
}
