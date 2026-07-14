import './stimulus_bootstrap.js';
import "@hotwired/turbo";

// Bootstrap 5 JS provides the behaviour for every `data-bs-*` component
// (modals, dropdowns, toasts, navbar collapse). We expose it as
// `window.bootstrap` because our own scripts (notifications.js) instantiate
// components manually. Tabler supplies the visual layer via CSS only.
import * as bootstrap from "bootstrap";
window.bootstrap = bootstrap;

// Tabler 1.4 design system (Bootstrap 5 theme) — replaces stock Bootstrap CSS.
import "@tabler/core/dist/css/tabler.min.css";

import './styles/app.css';
import './styles/planner.css';
import "./js/forms.js"
import "./js/notifications.js"

// An expired session must take the user off the page, not leave their data on screen.
import { watchTurboForSessionExpiry } from './js/session.js';
watchTurboForSessionExpiry();
