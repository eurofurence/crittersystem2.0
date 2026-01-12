# UI Modal Components – Coding Guideline (CS-003)

## 1) Always use the modal macros

**DO**

```twig
{% import 'components/modal/_macros.twig' as m %}
```

**DON’T**

-   Write modal HTML manually
-   Copy/paste Bootstrap modal markup into templates
-   Inline modal logic inside pages

All modals must be created through macros.

---

## 2) Which modal to use (decision table)

| Use case                   | Macro            | Notes                   |
| -------------------------- | ---------------- | ----------------------- |
| Confirm / delete / approve | `m.confirm()`    | Supports danger styling |
| Create / edit forms        | `m.form_modal()` | Embed Symfony form HTML |
| Read-only details          | `m.detail()`     | No actions except close |

If it doesn’t fit one of these → **CS-003 is incomplete**.

---

## 3) Triggering modals (strict rule)

-   Modals are opened **only** via Bootstrap data attributes

**DO**

```html
<button data-bs-toggle="modal" data-bs-target="#modalId"></button>
```

**DON’T**

-   Use JavaScript to open/close modals
-   Bind click handlers manually
-   Use `onclick=`

Bootstrap handles lifecycle; we don’t override it.

---

## 4) Modal IDs (naming rule)

-   IDs must be **globally unique**
-   Use semantic names

**Good**

```twig
confirmDeleteDepartment
editShiftModal
viewUserDetails
```

**Bad**

```twig
modal1
confirm
popup
```

---

## 5) Actions and buttons

-   Confirmation modals:

    -   Always show **Cancel** + **Confirm**
    -   Danger actions use `btn-danger`

-   Form modals:

    -   Submit button only in footer
    -   No submit buttons inside body content

-   Detail modals:

    -   Close button only

No custom button layouts.

---

## 6) Forms inside modals

-   Use normal Symfony forms
-   Embed via `formHtml` parameter
-   No modal-specific form logic

**DO**

```twig
{{ m.form_modal('editModal', 'Edit', formHtml) }}
```

**DON’T**

-   Build forms inside the macro
-   Add JS validation specific to modals

Forms behave exactly the same as page forms.

---

## 7) Turbo & navigation rules

-   CS-003 modals are **static HTML**
-   No Turbo Streams
-   No frame navigation inside modals (yet)

If modal content must be loaded dynamically → **future CS ticket**, not CS-003.

---

## 8) Accessibility baseline (mandatory)

-   Always include:

    -   `.modal-title`
    -   `.btn-close`

-   Never remove focusable elements
-   Never auto-open modals on page load

Bootstrap defaults are sufficient — don’t break them.

---

## 9) Where modals live

-   Macros: `templates/components/modal/_macros.twig`
-   Usage:

    -   Pages: embed macro call near bottom of template
    -   Not inside loops unless IDs are unique

No modal markup in partials unless reused.

---

## 10) The “boring modal” rule

> If a developer asks “how should this modal work?”,
> CS-003 has failed.

Modals must:

-   look the same
-   behave the same
-   close the same way
-   submit the same way
