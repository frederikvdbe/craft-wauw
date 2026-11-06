import Alpine from "alpinejs";

import * as components from "../components";

window.Alpine = Alpine;

Alpine.data("site", components.site);

Alpine.start();
