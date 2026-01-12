import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import "@hotwired/turbo";
// import "bootstrap";
import * as bootstrap from "bootstrap";
window.bootstrap = bootstrap;
import "bootstrap/dist/css/bootstrap.min.css";

import './styles/app.css';
import "./js/forms.js"
import "./js/notifications.js"

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
