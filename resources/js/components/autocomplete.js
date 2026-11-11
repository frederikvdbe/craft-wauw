export function autocomplete(fieldId, options = [], initialValue = "", initialLabel = "") {
	return {
		options,
		query: initialLabel,
		value: initialValue,
		open: false,
		activeIndex: -1,
		stopPositioning: null,

		init() {
			if (!this.value) this.value = this.findByLabel(this.query)?.value ?? "";

			this.form[fieldId] = this.value;
			this.$watch("value", (newValue) => {
				this.form[fieldId] = newValue;
			});
		},

		get filteredOptions() {
			const search = this.query.trim().toLowerCase();
			if (!search) return this.options;

			return this.options.filter((option) => option.label.toLowerCase().includes(search));
		},

		destroy() {
			this.stopPositioning?.();
		},

		onFocus() {
			this.activeIndex = -1;
			this.openList();
		},

		onInput() {
			this.activeIndex = -1;
			this.openList();

			const match = this.findByLabel(this.query);
			this.value = match ? match.value : "";
		},

		onBlur() {
			setTimeout(() => {
				this.closeList();
				this.syncQueryWithValue();
			}, 100);
		},

		syncQueryWithValue() {
			const match = this.options.find((option) => option.value === this.value);
			this.query = match ? match.label : "";
		},

		findByLabel(label) {
			const needle = label.trim().toLowerCase();
			return this.options.find((option) => option.label.toLowerCase() === needle);
		},

		select(option) {
			this.value = option.value;
			this.query = option.label;
			this.closeList();
			this.$refs.input.focus();
		},

		moveActive(step) {
			if (!this.open) {
				this.openList();
				return;
			}

			const count = this.filteredOptions.length;
			if (!count) return;

			this.activeIndex = (this.activeIndex + step + count) % count;
		},

		selectActive() {
			const option = this.filteredOptions[this.activeIndex];
			if (option) this.select(option);
		},

		async openList() {
			if (this.open) return;
			this.open = true;

			const { autoUpdate, computePosition, flip, offset, shift } = await import("@floating-ui/dom");

			const reference = this.$refs.input;
			const floating = this.$refs.list;

			floating.style.width = `${reference.offsetWidth}px`;

			this.stopPositioning = autoUpdate(reference, floating, async () => {
				const { x, y } = await computePosition(reference, floating, {
					placement: "bottom-start",
					strategy: "fixed",
					middleware: [offset(4), flip(), shift({ padding: 8 })],
				});

				floating.style.left = `${x}px`;
				floating.style.top = `${y}px`;
			});
		},

		closeList() {
			this.open = false;
			this.stopPositioning?.();
			this.stopPositioning = null;
		},
	};
}
