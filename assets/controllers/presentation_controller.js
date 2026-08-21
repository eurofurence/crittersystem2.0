import { Controller } from '@hotwired/stimulus';

/*
 * Full-screen presentation mode for the statistics dashboard.
 *
 * Presenting state is tracked here rather than inferred from document.fullscreenElement, because
 * the Fullscreen API is refused outright in some embedded and permission-restricted browsers; when
 * the request fails the page still switches to large type so the mode is never simply dead.
 *
 * Leaving fullscreen with Escape fires no click, so the change event is what turns the mode back
 * off. The listener is released on disconnect: Turbo swaps the body without tearing the document
 * down, and a listener left on `document` would outlive the page that added it.
 */
export default class extends Controller {
    static targets = ['label'];
    static values = {
        enterLabel: String,
        exitLabel: String,
    };

    connect() {
        this.presenting = false;
        this.onFullscreenChange = () => {
            if (this.presenting && !document.fullscreenElement) {
                this.apply(false);
            }
        };
        document.addEventListener('fullscreenchange', this.onFullscreenChange);
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        this.element.classList.remove('is-presenting');
        document.body.classList.remove('stats-presenting');
    }

    toggle() {
        if (this.presenting) {
            this.apply(false);
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
            return;
        }

        this.apply(true);
        if (this.element.requestFullscreen) {
            this.element.requestFullscreen().catch(() => {});
        }
    }

    apply(on) {
        this.presenting = on;
        this.element.classList.toggle('is-presenting', on);
        document.body.classList.toggle('stats-presenting', on);

        if (this.hasLabelTarget) {
            this.labelTarget.textContent = on ? this.exitLabelValue : this.enterLabelValue;
        }
    }
}
