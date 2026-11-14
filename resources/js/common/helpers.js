export const templateRequest = async (url) => {
	const response = await fetch(url, {
		headers: { "X-Craft-Site": window.site.handle },
	});

	const text = await response.text();

	const parser = new DOMParser();
	const html = parser.parseFromString(text, "text/html");
	const content = html.body.innerHTML;
	const data = html.body.firstElementChild?.dataset;

	return { html, content, data: { ...data } };
};

export const jsonRequest = async (url, options = {}) => {
	const response = await fetch(url, {
		...options,
		headers: {
			"X-Craft-Site": window.site.handle,
			Accept: "application/json",
			...options.headers,
		},
	});

	return response.json();
};

export async function submitForm(component, event) {
	component.loading = true;
	component.message = null;
	component.validate?.();

	const form = event.target;
	const url = form.getAttribute("action") || window.location.href;

	try {
		const [response] = await Promise.all([
			fetch(url, {
				method: "POST",
				body: new FormData(form),
				headers: { Accept: "application/json", "X-Craft-Site": window.site.handle },
			}),
			new Promise((resolve) => setTimeout(resolve, 1000)),
		]);

		const data = await response.json();

		if (response.ok && data.redirect) {
			window.location.assign(new URL(data.redirect, `${window.location.origin}/`));
			return data;
		}

		if (data.csrfTokenValue) {
			window.site.csrfTokenValue = data.csrfTokenValue;
			form.querySelector(`[name="${window.site.csrfTokenName}"]`)?.setAttribute("value", data.csrfTokenValue);
		}

		const errorList = data.errors ? Object.values(data.errors).flat().join(" ") : null;
		const successMessage = response.ok ? component.successMessage : null;

		component.message = {
			type: response.ok ? "success" : "error",
			text: errorList || successMessage || data.message || "Er ging iets mis. Probeer het later opnieuw.",
		};

		return data;
	} catch {
		component.message = { type: "error", text: "Er ging iets mis. Probeer het later opnieuw." };
	} finally {
		component.loading = false;
		component.formErrors = {};
		window.scrollTo({ top: 0, behavior: "smooth" });
	}
}

export async function submitCartForm(component, event) {
	const data = await submitForm(component, event);
	const notices = data?.cart?.notices ?? [];

	if (notices.length) {
		component.message = { type: "error", text: notices.map((notice) => notice.message).join(" ") };
	}

	if (component.message?.type !== "error") {
		window.dispatchEvent(new CustomEvent("wauw:cart-change"));
	}
}

export function buildQueryString(params) {
	const entries = Object.entries(params)
		.filter(([, value]) => value)
		.map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
		.join("&");

	return entries ? `?${entries}` : "";
}

export function parseArrayParam(value) {
	if (!value) return [];
	return value.split(",").filter(Boolean);
}
