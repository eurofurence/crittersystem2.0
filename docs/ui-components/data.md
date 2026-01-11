# UI Data Components – Coding Guideline (CS-002)

## 1) Always import the data macros

**DO**

```twig
{% import 'components/data/_macros.twig' as d %}
```

**DON’T**

-   Hand-craft component markup (cards, empty states, badges)
-   Re-invent table wrappers per page

---

## 2) Standard page layout for list pages (required)

Every list page must have, in this order:

1. `d.page_header(title, {subtitle, actions})`
2. filters/search (Turbo-friendly)
3. results area (prefer Turbo Frame)
4. empty state (when no rows)
5. pagination (if applicable)

This makes all admin pages feel identical.

---

## 3) Use Turbo Frames for list refresh (default rule)

If a list can be filtered or searched, it must be rendered inside a Turbo Frame.

**Pattern**

```twig
{{ f.search_input('q', {
  turboFrame: 'results_frame',
  url: path('route_frame')
}) }}

<turbo-frame id="results_frame" src="{{ path('route_frame') }}"></turbo-frame>
```

**Don’t** use fetch/AJAX for list updates. Turbo keeps navigation/back button sane.

---

## 4) Table rules (no exceptions)

-   All tables must use `d.table_start()` + `d.table_end()`
-   Tables must live inside cards on normal pages:

    -   `d.card('Title', include('...', {...}))`

**DO**

```twig
{{ d.card('Departments', include('departments/_table.html.twig', {rows: rows})) }}
```

**DON’T**

-   Put tables directly into the page without responsive wrapper
-   Add custom `table-responsive` wrappers manually

---

## 5) Status and badges

-   Use `d.status_badge()` for anything that represents a state (Active/Pending/etc.)
-   Use `d.badge()` only for neutral labels (tags, categories, flags)

**DO**

```twig
{{ d.status_badge(row.status) }}
```

**DON’T**

-   Hardcode Bootstrap badge classes in templates

---

## 6) Empty state is mandatory

When no results:

-   always render `d.empty_state()`
-   never show a blank table

**DO**

```twig
{{ d.empty_state('No matches', {message: 'Try a different search term.'}) }}
```

---

## 7) Actions consistency

-   Row actions: keep them on the right
-   Prefer one “View” button initially for MVP
-   Additional actions come later (dropdown/3-dot menu in a future refinement)

---

## 8) Pagination guideline

-   Use `d.pagination()` only as a placeholder until we choose the real paginator
-   Pagination goes below the table, aligned right (or inside card footer later)

No custom pagination HTML.

---

## 9) Naming conventions (templates)

-   For every list page:

    -   `index.html.twig` (page shell)
    -   `_table.html.twig` (table markup only)
    -   `_table_frame.html.twig` (wraps turbo-frame + empty state)
