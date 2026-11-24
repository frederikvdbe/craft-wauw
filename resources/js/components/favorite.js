import { jsonRequest } from "../common/helpers";
import toast from "../plugins/toastkit";

export function favorite(productId, isFavorite = false, addedMessage = "Added to favorites", removedMessage = "Removed from favorites") {
	return {
		productId,
		isFavorite,
		loading: false,

		async toggle() {
			this.loading = true;

			try {
				const [data] = await Promise.all([
					jsonRequest("/actions/wauw/favorite/toggle", {
						method: "POST",
						headers: {
							"X-CSRF-Token": window.site.csrfTokenValue,
							"Content-Type": "application/x-www-form-urlencoded",
						},
						body: new URLSearchParams({ productId: this.productId }),
					}),
					new Promise((resolve) => setTimeout(resolve, 1000)),
				]);

				this.isFavorite = data.isFavorite;
				toast(this.isFavorite ? addedMessage : removedMessage, { kind: "success", dismissible: false });
			} finally {
				this.loading = false;
			}
		},
	};
}
