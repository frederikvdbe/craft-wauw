import { positionPopover } from "../common/popover";

function splitRange(value) {
	const [start = "", end = ""] = (value || "").split("/");
	return { start, end };
}

function formatDate(value) {
	const date = new Date(`${value}T00:00:00`);

	return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
		day: "numeric",
		month: "long",
		year: "numeric",
	}).format(date);
}

export function daterangepicker(initialValue = "", min = "", max = "") {
	const { start, end } = splitRange(initialValue);

	return {
		start,
		end,
		min,
		max,
		open: false,
		stopPositioning: null,

		get value() {
			return this.start && this.end ? `${this.start}/${this.end}` : "";
		},

		get displayValue() {
			if (!this.start) return "";
			if (!this.end) return formatDate(this.start);

			return `${formatDate(this.start)} – ${formatDate(this.end)}`;
		},

		async init() {
			await import("cally");
		},

		destroy() {
			this.stopPositioning?.();
		},

		onChange(event) {
			const { start, end } = splitRange(event.target.value);

			this.start = start;
			this.end = end;

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
