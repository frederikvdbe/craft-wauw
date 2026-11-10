export function modal() {
	return {
		id: null,

		init() {
			this.id = this.$el.dataset.modalId;

			this.handleOpen = (event) => {
				if (event.detail?.id === this.id) this.openModal();
			};

			this.handleClose = (event) => {
				if (!event.detail?.id || event.detail.id === this.id) this.closeModal();
			};

			window.addEventListener("wauw:modal-open", this.handleOpen);
			window.addEventListener("wauw:modal-close", this.handleClose);
		},

		destroy() {
			window.removeEventListener("wauw:modal-open", this.handleOpen);
			window.removeEventListener("wauw:modal-close", this.handleClose);
		},

		openModal() {
			this.$root.showModal();
		},

		closeModal() {
			this.$root.close();
		},
	};
}
