import { jsonRequest, templateRequest } from "../common/helpers";

export function media(initialIds = [], videoModalId = "", libraryModalId = "", ownerId = null) {
	return {
		ids: [...initialIds],
		selectedIds: [],
		uploading: false,
		galleryHtml: "",

		videoModalId,
		videoUrl: "",
		addingVideo: false,
		videoError: "",

		libraryModalId,
		ownerId,
		formErrors: {},

		async init() {
			this.$dispatch("streettheater:media-change", { ids: this.ids });

			this.$watch("ids", (ids) => {
				this.$dispatch("streettheater:media-change", { ids });
			});

			if (this.ids.length) await this.renderGallery();
		},

		async upload(event) {
			const files = event.target.files;
			if (!files?.length) return;

			const formData = new FormData();
			for (const file of files) formData.append("files[]", file);
			if (this.ownerId) formData.append("ownerId", this.ownerId);
			formData.append(window.site.csrfTokenName, window.site.csrfTokenValue);

			this.uploading = true;

			try {
				const data = await jsonRequest("?action=site/media/upload-image", { method: "POST", body: formData });

				this.ids = [...this.ids, ...(data.ids ?? [])];

				await this.renderGallery();
			} finally {
				this.uploading = false;
				event.target.value = "";
			}
		},

		openVideoModal() {
			this.videoError = "";

			window.dispatchEvent(new CustomEvent("streettheater:modal-open", { detail: { id: this.videoModalId } }));
		},

		async addVideo() {
			const url = this.videoUrl.trim();
			if (!url) return;

			const formData = new FormData();
			formData.append("url", url);
			if (this.ownerId) formData.append("ownerId", this.ownerId);
			formData.append(window.site.csrfTokenName, window.site.csrfTokenValue);

			this.addingVideo = true;
			this.videoError = "";

			try {
				const data = await jsonRequest("?action=site/media/add-video", { method: "POST", body: formData });

				if (!data.ids?.length) {
					this.videoError = data.error ?? "Ongeldige YouTube-URL.";
					return;
				}

				this.ids = [...this.ids, ...data.ids];
				await this.renderGallery();

				this.videoUrl = "";
				window.dispatchEvent(
					new CustomEvent("streettheater:modal-close", { detail: { id: this.videoModalId } }),
				);
			} finally {
				this.addingVideo = false;
			}
		},

		async renderGallery() {
			const ownerParam = this.ownerId ? `&ownerId=${this.ownerId}` : "";
			const { content } = await templateRequest(
				`?action=site/media/assets&ids=${this.ids.join(",")}&checkedIds=${this.selectedIds.join(",")}${ownerParam}`,
			);

			this.galleryHtml = content;
		},

		openLibraryModal() {
			window.dispatchEvent(
				new CustomEvent("streettheater:media-library-open", {
					detail: { id: this.libraryModalId, excludeIds: this.ids, ownerId: this.ownerId },
				}),
			);
			window.dispatchEvent(new CustomEvent("streettheater:modal-open", { detail: { id: this.libraryModalId } }));
		},

		addSelectedFromLibrary(ids) {
			const newIds = ids.filter((id) => !this.ids.includes(id));
			if (!newIds.length) return;

			this.ids = [...this.ids, ...newIds];

			this.renderGallery();
		},

		handleGalleryClick(event) {
			const button = event.target.closest('[data-action="remove"]');
			if (!button) return;

			const assetId = Number(button.dataset.assetId);

			this.ids = this.ids.filter((id) => id !== assetId);
			this.selectedIds = this.selectedIds.filter((id) => id !== assetId);

			this.renderGallery();
		},

		handleGalleryChange(event) {
			const checkbox = event.target.closest('[data-action="select"]');
			if (!checkbox) return;

			const assetId = Number(checkbox.dataset.assetId);

			if (checkbox.checked) {
				if (!this.selectedIds.includes(assetId)) this.selectedIds = [...this.selectedIds, assetId];
			} else {
				this.selectedIds = this.selectedIds.filter((id) => id !== assetId);
			}
		},

		removeSelected() {
			if (!this.selectedIds.length) return;

			this.ids = this.ids.filter((id) => !this.selectedIds.includes(id));
			this.selectedIds = [];

			this.renderGallery();
		},
	};
}
