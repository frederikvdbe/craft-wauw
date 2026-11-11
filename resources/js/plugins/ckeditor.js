import "ckeditor5/ckeditor5.css";
import { Bold, ClassicEditor, Essentials, Heading, Italic, Link, List, Paragraph } from "ckeditor5";

export function createRichTextEditor(element) {
	return ClassicEditor.create({
		attachTo: element,
		licenseKey: "GPL",
		plugins: [Essentials, Paragraph, Heading, Bold, Italic, List, Link],
		toolbar: ["heading", "|", "bold", "italic", "|", "bulletedList", "numberedList", "|", "link"],
		heading: {
			options: [
				{ model: "paragraph", title: "Paragraph", class: "ck-heading_paragraph" },
				{ model: "heading2", view: "h2", title: "Heading 2", class: "ck-heading_heading2" },
				{ model: "heading3", view: "h3", title: "Heading 3", class: "ck-heading_heading3" },
			],
		},
	});
}

export function createPlainTextEditor(element) {
	return ClassicEditor.create({
		attachTo: element,
		licenseKey: "GPL",
		plugins: [Essentials, Paragraph],
		toolbar: [],
	}).then((editor) => {
		editor.ui.view.stickyPanel.element.remove();
		return editor;
	});
}
