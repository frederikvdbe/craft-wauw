export async function positionPopover(reference, floating) {
	const { autoUpdate, computePosition, flip, offset, shift } = await import("@floating-ui/dom");

	return autoUpdate(reference, floating, async () => {
		const { x, y } = await computePosition(reference, floating, {
			placement: "bottom-start",
			strategy: "fixed",
			middleware: [offset(4), flip(), shift({ padding: 8 })],
		});

		floating.style.left = `${x}px`;
		floating.style.top = `${y}px`;
	});
}
