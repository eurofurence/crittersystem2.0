/*
 * Entry point for the live operations board, and the reason the board can use a different design
 * system from the rest of the application without either affecting the other.
 *
 * It deliberately does NOT load what `app.js` loads: no Tabler CSS, no Tabler icon webfont, no
 * Bootstrap, and none of the four application stylesheets. Tabler and Tailwind both reset every
 * element, so loading them together would leave the board's appearance decided by whichever
 * cascade happened to win.
 *
 * What remains is exactly what the board needs: Turbo and Stimulus to drive it, the session-expiry
 * watcher so an expired session takes the screen off the board rather than leaving stale data up,
 * the heartbeat that re-mints the Mercure subscriber cookie for a page open all day, and Sentry -
 * a wall display has nobody watching its console, so an error that reaches nobody is the worst
 * failure mode it has.
 *
 * The board's stylesheet is not imported here. It is linked directly in the layout, because
 * `assets/styles/board.css` is Tailwind's input file and the Tailwind bundle serves the compiled
 * result at that same logical path.
 */
import { app } from './stimulus_bootstrap.js';
import '@hotwired/turbo';

import './js/sentry.init.js';
import './js/heartbeat.js';

import { watchTurboForSessionExpiry } from './js/session.js';

/*
 * The kit's own controllers live outside `assets/controllers/`, which the Stimulus bundle
 * auto-registers for every entry point. Their names are generic enough that a future `dialog` or
 * `combobox` in the main application would silently pick up the shadcn one and render it with
 * Tailwind classes that do not exist outside this page. Registering them on the board's entry point
 * keeps them here.
 */
import DialogController from './board-controllers/dialog_controller.js';
import AlertDialogController from './board-controllers/alert_dialog_controller.js';
import ComboboxController from './board-controllers/combobox_controller.js';

app.register('dialog', DialogController);
app.register('alert-dialog', AlertDialogController);
app.register('combobox', ComboboxController);

watchTurboForSessionExpiry();
