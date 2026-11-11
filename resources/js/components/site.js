export function site() {
	return {
		openModal(id) {
			window.dispatchEvent(new CustomEvent("wauw:modal-open", { detail: { id } }));
		},

		closeModal(id) {
			window.dispatchEvent(new CustomEvent("wauw:modal-close", { detail: { id } }));
		},
	};
}
