# UI Form Components – Coding Guideline (CS-001)

## 1. Always use the Form Macros

**DO**

```twig
{% import 'components/forms/_macros.twig' as f %}
```

**DON’T**

-   Hand-craft `<input>` / `<select>` markup
-   Mix Bootstrap classes directly into templates
-   Use `form_row()` except for temporary debug

All form UI must go through macros to keep:

-   consistent spacing
-   consistent error handling
-   consistent accessibility

---

## 2. Which macro to use (decision table)

| Use case                      | Macro to use          | Reason                            |
| ----------------------------- | --------------------- | --------------------------------- |
| Text, email, number, password | `f.field()`           | Default input with `form-control` |
| Date & time input             | `f.datetime_local()`  | HTML5-native, no JS dependency    |
| Priority / percentage / range | `f.range_slider()`    | Live value feedback               |
| Single select (`ChoiceType`)  | `f.choice()`          | Correct `form-select` styling     |
| Multi select (`ChoiceType`)   | `f.choice_multiple()` | Accessible, Symfony-native        |
| Search / filter input         | `f.search_input()`    | Debounced + Turbo-friendly        |

**Rule of thumb:**
If it’s a `<select>`, never use `f.field()`.

---

## 3. Layout rules (Bootstrap grid)

-   Always pass `col` explicitly for non-trivial forms

**DO**

```twig
{{ f.field(form.title, { col: 'col-12 col-md-6' }) }}
```

**DON’T**

```twig
{{ f.field(form.title) }} {# unclear layout #}
```

This prevents layout drift on mobile vs desktop.

---

## 4. Labels, help, errors

-   Labels come from Symfony FormType by default
-   Override labels only when **UI wording ≠ domain wording**
-   Help text is always passed via `help:` option

**DO**

```twig
{{ f.field(form.title, {
  help: 'Visible to volunteers',
}) }}
```

**DON’T**

-   Add `<small>` manually
-   Inject explanatory text outside the macro

Errors are always rendered **inside the macro**, never manually.

---

## 5. Search + filtering (Turbo rule)

For live filtering:

-   Always use **Turbo Frames**
-   Always use `f.search_input()`
-   Never fetch via `fetch()` / AJAX directly

**Correct pattern**

```twig
{{ f.search_input('q', {
  turboFrame: 'result_frame',
  url: path('some_frame_route')
}) }}

<turbo-frame id="result_frame" src="{{ path('some_frame_route') }}"></turbo-frame>
```

Why:

-   automatic history handling
-   back/forward works
-   no JS state desync

---

## 6. JavaScript rules (very important)

-   JS must be **Turbo-safe**
-   Use `turbo:load`, never `DOMContentLoaded`
-   No per-element listeners unless unavoidable
-   Prefer event delegation

**Allowed JS location**

```
assets/js/forms.js
```

**Not allowed**

-   Inline `<script>`
-   Page-specific JS files for forms
-   jQuery

---

## 7. Accessibility baseline

-   Always render `<label>`
-   Never hide native `<select>`
-   No custom keyboard traps
-   Macros must work with JS disabled

If a component requires JS to function → **not allowed - requires approval**.

---

## 8. When to extend CS-001 vs create CS-002

Extend **CS-001** when:

-   improving existing macro behavior
-   adding options to an existing macro

Create **CS-002** when:

-   introducing display-only components (badges, tables, cards)
-   adding modal/dialog behavior
-   adding non-form UI primitives

---

## 9. Naming conventions

Macro options:

-   `col` → Bootstrap grid
-   `help` → explanatory text
-   `label` → override label
-   `size`, `min`, `max`, `step` → semantic only

JS data attributes:

-   `data-search-*`
-   `data-range-*`

No custom prefixes.

---

## 10. Final rule (the “boring UI” rule)

> If a contributor needs to _think_ about how to render a form field,
> the component library is incomplete.

Add a macro instead of inventing a new pattern.
