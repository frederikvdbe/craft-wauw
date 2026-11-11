export function required(value) {
	return value && String(value).trim() ? null : "Dit veld is verplicht";
}

export function minLength(min) {
	return (value) => {
		if (!value) return null;

		return String(value).trim().length >= min ? null : `Vul minstens ${min} tekens in`;
	};
}

export function minItems(min) {
	return (value) => {
		if (!value) return null;

		return value.length >= min ? null : `Selecteer minstens ${min} ${min === 1 ? "bestand" : "bestanden"}`;
	};
}

export function minWordCount(min) {
	return (value) => {
		if (!value) return null;

		const el = document.createElement("div");
		el.innerHTML = value;

		const wordCount = (el.textContent || "").trim().split(/\s+/).filter(Boolean).length;

		return wordCount >= min ? null : `Vul minstens ${min} woorden in`;
	};
}

export function lettersOnly(value) {
	if (!value) return null;

	return /^[\p{L}\s'-]+$/u.test(value) ? null : "Enkel letters toegestaan";
}

export function email(value) {
	if (!value) return null;

	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? null : "Vul een geldig e-mailadres in";
}

export function phone(value) {
	if (!value) return null;

	return /^[+]?[0-9\s-]{8,}$/.test(value) ? null : "Vul een geldig telefoonnummer in";
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

	return fieldNames.every((name) => !newErrors[name]);
}
