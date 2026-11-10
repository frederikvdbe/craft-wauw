import { gsap } from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

gsap.registerPlugin(ScrollTrigger);
ScrollTrigger.normalizeScroll(true);

const IMAGE_SCALE_START = 1.15;
const IMAGE_SCALE_END = 1.3;
const SCRUB = 0.3;

if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
	const heroes = document.querySelectorAll(".b-product-hero");

	heroes.forEach((hero) => {
		const image = hero.querySelector(".c-image");
		const title = hero.querySelector(".c-title");
		const img = image?.querySelector("img");

		if (!image || !title || !img) return;

		gsap.set(img, { scale: IMAGE_SCALE_START });

		gsap.timeline({
			scrollTrigger: {
				trigger: hero,
				start: "top top",
				end: () => `+=${image.offsetHeight}`,
				scrub: true,
				pin: true,
				pinSpacing: false,
				invalidateOnRefresh: true,
			},
		})
			.to(image, { clipPath: () => `inset(0px 0px ${image.offsetHeight}px 0px)`, ease: "none" }, 0)
			.to(title, { y: () => -image.offsetHeight, ease: "none" }, 0);

		gsap.timeline({
			scrollTrigger: {
				trigger: hero,
				start: "top top",
				end: () => `+=${image.offsetHeight}`,
				scrub: SCRUB,
				invalidateOnRefresh: true,
			},
		}).to(
			img,
			{
				scale: IMAGE_SCALE_END,
				y: () => image.offsetHeight * ((IMAGE_SCALE_END - 1) / 2) * 0.8,
				ease: "none",
			},
			0,
		);
	});
}
