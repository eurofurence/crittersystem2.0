import * as Sentry from '@sentry/browser';

   function getMetaContent(name) {
       return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content');
   }

   const app_sdn = getMetaContent('app-sdn');

   if (app_sdn) {
       Sentry.init({
           dsn: app_sdn,
           tunnel: '/sentry-tunnel',
           integrations: [
               Sentry.browserTracingIntegration(),
               Sentry.replayIntegration(),
               Sentry.feedbackIntegration({
                   autoInject: true,
                   showName: true,
                   isNameRequired: false,
                   showEmail: false,
                   isEmailRequired: false,
                   showBranding: false,
                   colorScheme: "light",
                   triggerLabel: "Feedback",
                   buttonPosition: "bottom-right",
                   formPosition: "bottom-right",
                   theme: "light",
                   submitButtonLabel: "Send Feedback",
                   useEventSubmitter: true,
               }),
           ],
           tracesSampleRate: 0.1,
           replaysSessionSampleRate: 0.1,
           replaysOnErrorSampleRate: 1.0,
       });

    // Turbo replaces <body> on every navigation, which removes the feedback
    // button. `turbo:load` fires on the first load AND after each Turbo visit,
    // so we (re)mount the button here every time. We remove any previous
    // instance first to avoid duplicates.
    // let detachFeedback = null;

    // document.addEventListener('turbo:load', () => {
    //     const feedback = Sentry.getFeedback();
    //     if (!feedback) return;

    //     if (detachFeedback) {
    //         detachFeedback();
    //         detachFeedback = null;
    //     }

    //     const button = document.querySelector('#feedback-btn');
    //     if (button) {
    //         detachFeedback = feedback.attachTo(button);
    //     }
    // });



    // document.addEventListener('turbo:load', () => {
    //     console.log('[sentry-feedback] turbo:load fired');

    //     const feedback = Sentry.getFeedback();
    //     if (!feedback) {
    //         console.warn('[sentry-feedback] getFeedback() returned nothing');
    //         return;
    //     }

    //     // Old node may already be gone (Turbo swapped the body), so guard this.
    //     try {
    //         if (feedbackWidget) {
    //             feedbackWidget.removeFromDom();
    //         }
    //     } catch (e) {
    //         console.warn('[sentry-feedback] removeFromDom failed', e);
    //     }

    //     feedbackWidget = feedback.createWidget();
    //     console.log('[sentry-feedback] widget created');
    // });

    function relocateFeedback() {
        const wrapper = document.getElementById('feedback-permanent');
        const widget = document.getElementById('sentry-feedback');
        if (wrapper && widget && widget.parentElement !== wrapper) {
            wrapper.appendChild(widget);
            return true;
        }
        return false;
    }

    if (!relocateFeedback()) {
        const observer = new MutationObserver(() => {
            if (relocateFeedback()) observer.disconnect();
        });
        observer.observe(document.body, { childList: true });
    }

    document.addEventListener('turbo:load', relocateFeedback);
}