import { positionPopover } from "../common/popover";

export function tooltip() {
	return {
		open: false,
		stopPositioning: null,
		closeTimeout: null,

		destroy() {
			clearTimeout(this.closeTimeout);
			this.close();
		},

		async show() {
			clearTimeout(this.closeTimeout);

			if (this.open) return;
			this.open = true;

			this.stopPositioning = await positionPopover(this.$refs.trigger, this.$refs.content, { placement: "top-start", offset: 10 });
		},

		scheduleClose() {
			clearTimeout(this.closeTimeout);
			this.closeTimeout = setTimeout(() => this.close(), 100);
		},

		close() {
			this.open = false;
			this.stopPositioning?.();
			this.stopPositioning = null;
		},
	};
}
