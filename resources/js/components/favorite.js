import { jsonRequest } from "../common/helpers";

export function favorite(productId, isFavorite = false) {
	return {
		productId,
		isFavorite,
		loading: false,

		async toggle() {
			this.loading = true;

			try {
				const [data] = await Promise.all([
					jsonRequest("/actions/site/favorite/toggle", {
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
			} finally {
				this.loading = false;
			}
		},
	};
}
