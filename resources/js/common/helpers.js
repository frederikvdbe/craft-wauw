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

// Craft/Commerce error bags (`errors`, `paymentFormErrors`, …) are an object keyed by
// attribute name, where each value is either an array of messages or, when the attribute
// itself is keyed by id (e.g. a line item id), a nested object of the same shape.
function collectMessages(value, messages = []) {
	if (typeof value === "string") {
		if (value) messages.push(value);
	} else if (value && typeof value === "object") {
		for (const item of Object.values(value)) {
			collectMessages(item, messages);
		}
	}

	return messages;
}

function errorText(...errorBags) {
	const messages = errorBags.flatMap((bag) => collectMessages(bag));

	return [...new Set(messages)].join(" ");
}

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

		if (data.csrfTokenValue) {
			window.site.csrfTokenValue = data.csrfTokenValue;
			form.querySelector(`[name="${window.site.csrfTokenName}"]`)?.setAttribute("value", data.csrfTokenValue);
		}

		const errorList = errorText(data.errors, data.paymentFormErrors);
		const successMessage = response.ok ? component.successMessage : null;

		const message = {
			type: response.ok ? "success" : "error",
			text: errorList || successMessage || data.message || "Er ging iets mis. Probeer het later opnieuw.",
		};

		const redirect = response.ok && data.redirect ? new URL(data.redirect, `${window.location.origin}/`) : null;

		return { data, message, redirect };
	} catch {
		const message = { type: "error", text: "Er ging iets mis. Probeer het later opnieuw." };
		return { data: null, message, redirect: null };
	} finally {
		component.loading = false;
		component.formErrors = {};
		window.scrollTo({ top: 0, behavior: "smooth" });
	}
}

export async function submitCartForm(component, event) {
	const { data, message, redirect } = await submitForm(component, event);
	const notices = data?.cart?.notices ?? [];

	const cartMessage = notices.length ? { type: "error", text: notices.map((notice) => notice.message).join(" ") } : message;

	if (cartMessage.type !== "error") {
		window.dispatchEvent(new CustomEvent("wauw:cart-change"));
	}

	return { data, message: cartMessage, redirect };
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
