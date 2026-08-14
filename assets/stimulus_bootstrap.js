import { startStimulusApp } from '@symfony/stimulus-bundle';

/*
 * Exported so an entry point can register controllers that must not be auto-registered for every
 * page. `startStimulusApp()` picks up everything in `assets/controllers/`, which is what we want for
 * the application at large; the board's kit controllers live outside that directory and attach
 * themselves here instead. There is one Stimulus application per page, so entry points must import
 * this rather than starting a second one.
 */
export const app = startStimulusApp();
