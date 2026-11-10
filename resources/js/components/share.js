export function share(title, url) {
	return {
		async share() {
			if (!navigator.share) return;

			try {
				await navigator.share({ title, url });
			} catch {
				// Share sheet dismissed or failed — nothing to do.
			}
		},
	};
}
