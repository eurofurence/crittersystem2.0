// import Toast from "bootstrap/js/dist/toast";

function initToasts(root = document) {
    // Event delegation so it works with Turbo
    root.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-toast-target]');
        if (!btn) return;

        const selector = btn.getAttribute('data-toast-target');
        if (!selector) return;

        const el = document.querySelector(selector);
        if (!el) return;

        // const toast = Toast.getOrCreateInstance(el); // TODO: REMOVE THIS LINE
        const toast = window.bootstrap.Toast.getOrCreateInstance(el);
        toast.show();
    },{
        passive: true
    });
}

document.addEventListener('turbo:load', () => {
    initToasts(document);
});
