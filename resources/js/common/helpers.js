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

const MINIMUM_LOADING_MS = 1000;

export async function withMinimumDelay(promise, delay = MINIMUM_LOADING_MS) {
	const [result] = await Promise.all([promise, new Promise((resolve) => setTimeout(resolve, delay))]);

	return result;
}

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

export async function submitForm(component, event, { scroll = true, url: overrideUrl, method = "POST" } = {}) {
	component.loading = true;
	component.message = null;
	component.validate?.();

	const form = event.target;
	const url = overrideUrl ?? (form.getAttribute("action") || window.location.href);

	try {
		const response = await withMinimumDelay(
			fetch(url, {
				method,
				...(method !== "GET" && { body: new FormData(form) }),
				headers: { Accept: "application/json", "X-Craft-Site": window.site.handle },
			}),
		);

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
		if (scroll) window.scrollTo({ top: 0, behavior: "smooth" });
	}
}

export async function submitCartForm(component, event, options) {
	const { data, message, redirect } = await submitForm(component, event, options);
	const notices = data?.cart?.notices ?? [];

	console.log("[helpers] submitCartForm: response", data);

	const cartMessage = notices.length
		? { type: "error", text: notices.map((notice) => notice.message).join(" ") }
		: message;

	if (cartMessage.type !== "error") {
		console.log("[helpers] submitCartForm: dispatching wauw:cart-change");
		window.dispatchEvent(new CustomEvent("wauw:cart-change"));
	} else {
		console.warn("[helpers] submitCartForm: not dispatching wauw:cart-change, cartMessage is an error", cartMessage);
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
