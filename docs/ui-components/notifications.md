# UI Notifications – Coding Guideline (CS-004)

## 1) Always use the notification macros

**DO**

```twig
{% import 'components/notification/_macros.twig' as n %}
```

**DON’T**

-   Hand-write `<div class="alert …">` in templates
-   Copy/paste notification markup
-   Create per-page notification systems

All notifications must come from macros.

---

## 2) Flash messages are global (mandatory rule)

-   Flash messages must be rendered **only in `base.html.twig`**
-   Pages must **never** render flashes themselves

**DO**

```php
$this->addFlash('success', 'Saved.');
```

**DON’T**

-   Render `app.flashes` in a page template
-   Create multiple flash render locations

---

## 3) Which UI to use (decision table)

| Use case                          | Use               | Notes              |
| --------------------------------- | ----------------- | ------------------ |
| Result of an action (save/delete) | **Flash message** | one-time feedback  |
| Static warning/info on a page     | `n.alert()`       | shown every load   |
| Non-blocking info (optional)      | `n.toast()`       | only for UX polish |

If a developer asks “alert vs toast?” → default to **flash**.

---

## 4) Allowed flash types (strict)

Use only these flash keys:

-   `success`
-   `info`
-   `warning`
-   `danger`

No custom types without updating the component library.

---

## 5) Toast behavior rules (MVP-safe)

-   Toasts are **opt-in**
-   Toasts are shown only via:

    -   `data-toast-target="#id"` button/link

-   No auto-toasts on page load (yet)

No custom JS in pages.

---

## 6) JavaScript rules (important)

-   JS lives only in:

    -   `assets/js/notifications.js`

-   JS must be Turbo-safe (`turbo:load`)
-   Bootstrap is exposed globally once in `assets/app.js`:

    -   `window.bootstrap = bootstrap;`

**Not allowed**

-   Inline scripts
-   Page-specific toast handlers
-   jQuery

---

## 7) Dismissibility rules

-   Flash messages are always `dismissible: true`
-   Inline alerts are dismissible only when explicitly requested

---

## 8) Accessibility baseline

-   Alerts must include `role="alert"`
-   Toast container must remain visible for screen readers
-   Don’t hide important errors in toasts (use alerts/flash)

---

## 9) File locations (no exceptions)

-   Macros: `templates/components/notification/_macros.twig`
-   Dev demo: `/dev/ui/notification-kit`
-   JS: `assets/js/notifications.js`

---

## 10) The “boring feedback” rule

> If a contributor invents a new notification style,
> CS-004 has failed.

All feedback must look and behave the same.
