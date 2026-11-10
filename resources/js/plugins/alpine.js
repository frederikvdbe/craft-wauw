import Alpine from "alpinejs";

import * as components from "../components";

window.Alpine = Alpine;

Alpine.data("site", components.site);
Alpine.data("search", components.search);
Alpine.data("modal", components.modal);
Alpine.data("favorite", components.favorite);
Alpine.data("share", components.share);

Alpine.start();
