function plainTextLength(html) {
	const el = document.createElement("div");
	el.innerHTML = html;
	return (el.textContent || "").length;
}

function truncate(editor, maxlength) {
	editor.model.change((writer) => {
		const root = editor.model.document.getRoot();
		const walker = editor.model.createRangeIn(root).getWalker({ singleCharacters: true });

		let count = 0;
		let cutPosition = null;

		for (const { type, previousPosition } of walker) {
			if (type !== "text") continue;

			count++;

			if (count > maxlength) {
				cutPosition = previousPosition;
				break;
			}
		}

		if (cutPosition) writer.remove(writer.createRange(cutPosition, writer.createPositionAt(root, "end")));
	});
}

export function richText(preset = "full", maxlength = null) {
	return {
		charCount: 0,
		maxlength,

		async init() {
			const { createPlainTextEditor, createRichTextEditor } = await import("../plugins/ckeditor");

			const createEditor = preset === "plain" ? createPlainTextEditor : createRichTextEditor;

			const source = this.$refs.source;
			const editor = await createEditor(source);

			this.charCount = plainTextLength(editor.getData());

			editor.ui.focusTracker.on("change:isFocused", (_event, _name, isFocused) => {
				if (!isFocused) source.dispatchEvent(new Event("blur"));
			});

			editor.model.document.on("change:data", () => {
				const length = plainTextLength(editor.getData());

				if (this.maxlength && length > this.maxlength) {
					truncate(editor, this.maxlength);
					return;
				}

				this.charCount = length;
				editor.updateSourceElement();
				source.dispatchEvent(new Event("input", { bubbles: true }));
			});
		},
	};
}
