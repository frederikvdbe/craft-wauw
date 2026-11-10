import { defineConfig, loadEnv } from "vite";

const HTTP_PORT = 3000;
const HTTPS_PORT = 3001;

export default defineConfig(({ command, mode }) => {
	const env = loadEnv(mode, process.cwd(), "");

	return {
		base: command === "serve" ? "" : "/dist/",
		build: {
			outDir: "./web/dist/",
			manifest: true,
			emptyOutDir: true,
			chunkSizeWarningLimit: 600,
			rollupOptions: {
				input: ["resources/js/index.js", "resources/scss/index.scss", "resources/scss/admin.scss"],
				output: {
					assetFileNames: (entry) => {
						if (entry.name === "admin.css") return entry.name;
						return `[name].[hash].[ext]`;
					},
					manualChunks(id) {
						if (!id.includes("node_modules")) return;
						if (id.includes("swiper")) return "swiper";
						if (id.includes("alpine")) return "alpine";
						if (id.includes("gsap")) return "gsap";

						return "vendor";
					},
				},
			},
		},
		server: {
			origin: `${env.PRIMARY_SITE_URL}:${HTTPS_PORT}`,
			port: HTTP_PORT,
			strictPort: true,
			host: "0.0.0.0",
			cors: {
				origin: [env.PRIMARY_SITE_URL],
			},
		},
	};
});
