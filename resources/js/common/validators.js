import toast from "../plugins/toastkit";

export function required(message) {
	return (value) => (value && String(value).trim() ? null : (message ?? "Dit veld is verplicht"));
}

export function minLength(min, message) {
	return (value) => {
		if (!value) return null;

		return String(value).trim().length >= min ? null : (message ?? `Vul minstens ${min} tekens in`);
	};
}

export function minItems(min, message) {
	return (value) => {
		if (!value) return null;

		return value.length >= min
			? null
			: (message ?? `Selecteer minstens ${min} ${min === 1 ? "bestand" : "bestanden"}`);
	};
}

export function minWordCount(min, message) {
	return (value) => {
		if (!value) return null;

		const el = document.createElement("div");
		el.innerHTML = value;

		const wordCount = (el.textContent || "").trim().split(/\s+/).filter(Boolean).length;

		return wordCount >= min ? null : (message ?? `Vul minstens ${min} woorden in`);
	};
}

export function lettersOnly(message) {
	return (value) => {
		if (!value) return null;

		return /^[\p{L}\s'-]+$/u.test(value) ? null : (message ?? "Enkel letters toegestaan");
	};
}

export function email(message) {
	return (value) => {
		if (!value) return null;

		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? null : (message ?? "Vul een geldig e-mailadres in");
	};
}

export function phone(message) {
	return (value) => {
		if (!value) return null;

		return /^[+]?[0-9\s-]{8,}$/.test(value) ? null : (message ?? "Vul een geldig telefoonnummer in");
	};
}

export function matches(getOtherValue, message) {
	return (value) => {
		if (!value) return null;

		return value === getOtherValue() ? null : (message ?? "De velden komen niet overeen");
	};
}

function buildErrorList(messages) {
	const list = document.createElement("ul");

	for (const message of messages) {
		const item = document.createElement("li");
		item.textContent = message;
		list.appendChild(item);
	}

	return list;
}

export function validateRules(component, fieldName) {
	const fieldNames = fieldName
		? [fieldName].filter((name) => component.fieldValidators[name])
		: Object.keys(component.fieldValidators);

	const newErrors = {};

	for (const name of fieldNames) {
		const value = component.form[name];
		const rules = component.fieldValidators[name];

		let message = null;
		for (const rule of rules) {
			message = rule(value);
			if (message) break;
		}

		if (!value && !message) continue;

		newErrors[name] = message;
	}

	const remainingErrors = { ...component.formErrors };

	for (const name of fieldNames) {
		delete remainingErrors[name];
	}

	component.formErrors = { ...remainingErrors, ...newErrors };

	const messages = Object.values(newErrors).filter(Boolean);

	if (messages.length) {
		toast("Controleer de ingevulde gegevens", {
			kind: "error",
			dismissible: false,
			content: buildErrorList(messages),
		});
	}

	return fieldNames.every((name) => !newErrors[name]);
}
