export function favorite(productId, isFavorite = false) {
	return {
		productId,
		isFavorite,

		async toggle() {
			const response = await fetch("/actions/site/favorite/toggle", {
				method: "POST",
				headers: {
					"X-CSRF-Token": window.site.csrfTokenValue,
					"Content-Type": "application/x-www-form-urlencoded",
					Accept: "application/json",
				},
				body: new URLSearchParams({ productId: this.productId }),
			});

			if (!response.ok) return;

			const data = await response.json();

			this.isFavorite = data.isFavorite;
		},
	};
}
