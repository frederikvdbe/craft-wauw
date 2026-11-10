import { gsap } from "gsap";
import { ScrollToPlugin } from "gsap/ScrollToPlugin";

gsap.registerPlugin(ScrollToPlugin);

// ScrollTrigger.normalizeScroll() (animations/productHero.js) replaces native scrolling with its own
// proxy, which breaks plain `#hash` links and CSS scroll-behavior. Animate those through GSAP instead,
// since its scrollTo plugin goes through the same proxy and stays in sync with the normalizer.
document.addEventListener("click", (event) => {
	const link = event.target.closest('a[href^="#"]');
	if (!link) return;

	const target = document.querySelector(link.getAttribute("href"));
	if (!target) return;

	event.preventDefault();

	gsap.to(window, {
		duration: 0.6,
		ease: "power2.inOut",
		scrollTo: target,
	});
});
