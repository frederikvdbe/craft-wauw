import { positionPopover } from "../common/popover";

export function datepicker(initialValue = "", min = "", max = "") {
	return {
		value: initialValue,
		min,
		max,
		open: false,
		stopPositioning: null,

		get displayValue() {
			if (!this.value) return "";

			const date = new Date(`${this.value}T00:00:00`);

			return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
				day: "numeric",
				month: "long",
				year: "numeric",
			}).format(date);
		},

		async init() {
			await import("cally");
		},

		destroy() {
			this.stopPositioning?.();
		},

		onChange(event) {
			this.value = event.target.value;
			this.closePicker();
			this.$refs.trigger.focus();
		},

		async openPicker() {
			if (this.open) return;
			this.open = true;

			this.stopPositioning = await positionPopover(this.$refs.trigger, this.$refs.panel);
		},

		closePicker() {
			this.open = false;
			this.stopPositioning?.();
			this.stopPositioning = null;
		},
	};
}
