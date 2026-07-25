# UI components (Twig macro layer)

Reference for the reusable Twig UI layer of Critter 2.0 (Symfony 8, Twig, Tabler 1.4).
It documents every macro that exists, exactly as the code behaves - including the places where
the code does *not* behave the way its own comment says (see [3. Gotchas](#3-gotchas-that-will-bite)).

Project-wide conventions: `CLAUDE.md`.

---

## 1. Architecture

### Where components live

```
templates/components/
├── data/_macros.twig          layout & data display  (cards, tables, badges, empty states, …)
├── forms/_macros.twig         form-field wrappers over Symfony's form_* functions
├── navigation/_macros.twig    nav items, sidebar list-group
├── notification/_macros.twig  alerts, flashes, toasts
└── icon/_macros.twig          inline Tabler SVG icons
```

Import them with these aliases. They are the aliases used throughout the ~100 templates that
already consume the layer, and every example in this document assumes them:

```twig
{% import 'components/data/_macros.twig'         as d   %}
{% import 'components/forms/_macros.twig'        as f   %}
{% import 'components/navigation/_macros.twig'   as nav %}
{% import 'components/notification/_macros.twig' as n   %}
{% import 'components/icon/_macros.twig'         as i   %}
```

Twig macros are **isolated**: a macro sees neither the calling template's variables nor its imports.
That is why `nav_item` re-imports the icon macros inside `navigation/_macros.twig`, and why the icon
map lives *inside* `icon()`. Inside a macro file, a macro calls its siblings via `_self.` (e.g.
`_self.badge(...)` inside `status_badge`).

### Twig macros only

This project uses **Twig macros. Symfony UX TwigComponent and LiveComponent are not installed and
must not be introduced.** Adding them would create a second, parallel component architecture for the
same job. (Stimulus and Turbo *are* used - for behaviour and navigation, not for markup composition.)

### Which shape to use

| Shape | Use when | Examples here |
|---|---|---|
| **Macro** | The markup is fully described by parameters. | `page_header`, `badge`, `stat_card`, `icon` |
| **Start/end macro pair** | The caller must supply arbitrary body content - a loop, an `is_granted`, a `form_widget`. Twig cannot pass a block to a macro, and a pre-rendered string parameter is what killed the old `card()` macro. | `card_start`/`card_end`, `table_start`/`table_end`, `message_card_start`/`message_card_end` |
| **`{% include %}`** | The partial is self-contained and needs no caller markup. | `notifications/_bell.html.twig`, `install/_progress.html.twig` |
| **`{% embed %}`** | A block-based structure is genuinely needed (several named slots). Not currently used by this layer - reach for it only if a start/end pair would need three or more holes. | - |

A start/end pair is **one element split in two**. Options that affect the opening markup
(`bodyClass`, `tag`, `responsive`) must be repeated identically on the closing call.

### Naming conventions

- Macro names are `snake_case`.
- The first arguments are the required, positional ones; everything else goes in a single
  `options = {}` map.
- **An option that accepts trusted HTML is named `...Html`** and is rendered with `|raw`:
  `iconHtml`, `headerActions`, `footer`, `extraHtml`, `actionsHtml`, `messageHtml`, `contentHtml`.
  Pass a `{% set x %}…{% endset %}` capture block. **Never pass user input into one.**
- `attrs` maps render as literal HTML attributes - **keys are written verbatim** (developer-authored
  only), values are escaped.

---

## 2. Component reference

Each entry lists: purpose, signature, parameters, HTML/escaping, accessibility, an example, and
limitations. "`''` honoured" means the option is read with `is defined`, so passing an empty string
is meaningful (see the gotchas); "no" means it is read with `|default(...)` and an empty value falls
back to the default.

---

### data - `templates/components/data/_macros.twig`

#### `attrs(attrs = {})`

Renders an attribute map as literal HTML attributes. Used internally by `card_start`, `badge`,
`table_start`, `delete_form`; you rarely call it directly.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `attrs` | map | no | `{}` | `{id: 'x', 'data-foo': 'bar'}` → ` id="x" data-foo="bar"` |

Keys are emitted **unescaped** (they are attribute names); values are escaped. Never build the key
side from user input.

---

#### `page_header(title, options = {})`

The page's `<h1>` plus its right-hand action buttons. The most-used macro in the app (~108 call sites).
Put it first inside `{% block body %}`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string | **yes** | - | Escaped. Rendered as `<h1 class="h3">`. |
| `options.subtitle` | string | no | - | Muted line under the title. |
| `options.actions` | array of `{label, href, class}` | no | `[]` | Plain links. `class` defaults to `btn btn-primary` per item. |
| `options.actionsHtml` | **trusted HTML** | no | - | `|raw`. Rendered after the links - for a header that needs a form, dropdown or icon button. |

Accessibility: emits exactly one `<h1>` per page - do not call it twice on the same page.

```twig
{{ d.page_header('Locations', {
  subtitle: 'Physical areas where shifts take place.',
  actions: [
    {label: '← Manage',       href: path('app_manage'),              class: 'btn btn-outline-secondary'},
    {label: '+ New location', href: path('app_manage_location_new'), class: 'btn btn-primary'}
  ]
}) }}
```

Limitations: no `back:` option - the app expresses "back" as a first `← …` link in `actions` (22 pages
do this). No breadcrumbs.

---

#### `section_header(title, options = {})`

A heading *inside* a page (what ~25 templates used to hand-roll as `<h2 class="h5">`). Not the page
title; not a card title.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string | **yes** | - | Escaped. |
| `options.level` | int 2–6 | no | `2` | Semantic heading level (`<h2>`…`<h6>`). |
| `options.size` | string | no | `'h5'` | Visual size class, decoupled from `level`. |
| `options.subtitle` | string | no | - | Muted `small` line. |
| `options.class` | string (`''` honoured) | no | `'mt-3 mb-2'` | Classes on the wrapper. `''` = no spacing. |

Accessibility: pick `level` so headings nest correctly under the page `<h1>`; use `size` for looks.

```twig
{{ d.section_header('Assigned shifts', {size: 'h6', subtitle: 'Confirmed sign-ups only.'}) }}
```

Limitations: **no actions/right-hand slot** (unlike `page_header`). If you need one, use a card header.

---

#### `card_start(title = null, options = {})` / `card_end(options = {})`

The Tabler card. Replaces the deleted string-body `card()` macro. Everything between the two calls is
the card body, so loops, `is_granted`, and `form_widget` all work.

`card_start`:

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string\|null | no | `null` | Escaped. A header is rendered iff `title` is non-empty **or** `headerActions` is defined. |
| `options.class` | string (`''` honoured) | no | `'mb-3'` | Extra classes on `.card` (`'h-100'`, `'border-danger'`). Pass `''` for no bottom margin. |
| `options.tag` | `'div'` \| `'form'` | no | `'div'` | `'form'` renders `<form class="card">`. |
| `options.attrs` | map | no | `{}` | Literal attributes - `method`/`action` on a form, `id`, `data-*`, `style`. |
| `options.subtitle` | string | no | - | Muted `small` line under the title. |
| `options.titleTag` | string | no | `'h3'` | Element for the title. |
| `options.titleClass` | string (`''` honoured) | no | `'card-title mb-0'` | |
| `options.headerClass` | string (`''` honoured) | no | `'card-header'` | `d-flex justify-content-between align-items-center` is appended automatically when `headerActions` is present. |
| `options.headerActions` | **trusted HTML** | no | - | `|raw`, right-aligned in the header. |
| `options.bodyClass` | string (`''` honoured) | no | `'card-body'` | **`''` emits no body wrapper at all** - required when the card wraps a `.card-table` or a `.table-responsive` directly. |

`card_end`:

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `options.bodyClass` | string (`''` honoured) | no | `'card-body'` | **Must match `card_start`.** Only the `''` case matters. |
| `options.tag` | `'div'` \| `'form'` | no | `'div'` | **Must match `card_start`.** |
| `options.footer` | **trusted HTML** | no | - | `|raw`, wrapped in a footer div. |
| `options.footerClass` | string (`''` honoured) | no | `'card-footer'` | |

Accessibility: the title is a real heading (`<h3>` by default), not a styled `<div>`. Use `titleTag`
to fix nesting, or `titleTag: 'span'` when the card is a widget rather than a document section (the
notification bell does this).

```twig
{# plain section card #}
{{ d.card_start('Account') }}
  <p>…anything…</p>
{{ d.card_end() }}

{# card that IS the form #}
{{ d.card_start('Add a user', {tag: 'form', attrs: {method: 'post', action: path('app_x')}}) }}
  {{ f.field(form.name) }}
  <input type="hidden" name="_token" value="{{ csrf_token('add') }}">
{{ d.card_end({tag: 'form', footer: submitBtn}) }}

{# card wrapping a table: bodyClass '' on BOTH ends, table not responsive-wrapped #}
{{ d.card_start('Departments', {bodyClass: ''}) }}
  {{ d.table_start({responsive: false, class: 'card-table table-vcenter'}) }}
    …
  {{ d.table_end({responsive: false}) }}
{{ d.card_end({bodyClass: ''}) }}
```

Real call site: `templates/notifications/_bell_content.html.twig` (header actions + no body wrapper +
footer + `class: ''`).

Limitations: the pair is not nestable-by-accident - Twig will not warn you if you forget `card_end()`;
`lint:twig` cannot catch an unbalanced pair because each macro is individually valid.

---

#### `message_card_start(title, options = {})` / `message_card_end()`

The centred single-message page (confirm / done / invalid / scanned), which 11 templates had copied
near-verbatim (`erase/*`, `invite/invalid`, `unsubscribe/confirm`, `ban/appeal`, `two_factor/confirm`,
`digital_id/verify*`, `certification/scan_*`). Body content goes between the calls, under the title.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string | **yes** | - | Escaped. This *is* the page heading. |
| `options.width` | string | no | `'col-md-6 col-lg-5'` | Column classes of the centred column. |
| `options.class` | string | no | `''` | Extra classes on `.card`. |
| `options.titleTag` | string | no | `'h1'` | |
| `options.titleClass` | string (`''` honoured) | no | `'card-title'` | |
| `options.bodyClass` | string (`''` honoured) | no | `'card-body text-center'` | Pass `'card-body'` for a left-aligned body (e.g. a form). |
| `options.iconHtml` | **trusted HTML** | no | - | `|raw`, rendered **above** the title. |

`message_card_end()` takes **no arguments**.

Accessibility: emits an `<h1>` by default - do not combine with `page_header` on the same page.

```twig
{% set iconHtml %}<div class="display-4" aria-hidden="true">✅</div>{% endset %}
{{ d.message_card_start('Pass scanned', {iconHtml: iconHtml}) }}
  <p class="text-secondary">The volunteer's digital ID is valid.</p>
{{ d.message_card_end() }}
```

---

#### `action_card(title, options = {})`

A clickable tile for a dashboard/index grid: icon + title + description. Wrap a set of them in
`<div class="row row-cards">`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string | **yes** | - | Escaped. Rendered as `<h3 class="card-title">`. |
| `options.text` | string | no | - | Muted description. |
| `options.href` | string | no | - | Makes the whole card clickable via Tabler's `.stretched-link`. |
| `options.iconHtml` | **trusted HTML** | no | - | `|raw` - an `i.icon()` call or an `aria-hidden` emoji span. |
| `options.col` | string | no | `'col-sm-6 col-lg-4'` | Grid column classes. |
| `options.class` | string | no | `''` | Extra classes on `.card` (`card card-link h-100` are always present). |

Accessibility: the link text is the card title, so it is meaningful on its own. Mark decorative icons
`aria-hidden="true"` (`i.icon()` already does).

```twig
<div class="row row-cards">
  {% set ic %}{{ i.icon('calendar') }}{% endset %}
  {% if is_granted('manageshifts:view') %}
    {{ d.action_card('Shift Manager', {
      text: 'Create and staff shifts.',
      href: path('app_manage_shifts'),
      iconHtml: ic
    }) }}
  {% endif %}
</div>
```

Limitations: permission gating is the caller's job (`{% if is_granted(...) %}` around the call). No
colour/variant option - use `class`.

---

#### `stat_card(value, label, options = {})`

Big number + caption. Wrap a set in `<div class="row">`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `value` | string\|number | **yes** | - | Escaped. |
| `label` | string | **yes** | - | Escaped. |
| `options.col` | string | no | `'col-6 col-md-3'` | |
| `options.size` | string | no | `'fs-3'` | Font-size class for the value. |
| `options.class` | string | no | `''` | Extra classes on `.card`. |

```twig
<div class="row">
  {{ d.stat_card(stats.open, 'Open shifts') }}
  {{ d.stat_card(stats.filled, 'Filled') }}
</div>
```

Limitations: no icon, no trend indicator, no link.

---

#### `certification_card(cert, options = {})`

One certification as a card (header title + description) with a caller-supplied control in the footer.
Used both to pick certifications as requirements on the volunteer-type form (the control is a toggle
switch over the form checkbox) and on the volunteer-facing certifications list (the control is the
apply/self-confirm buttons). Wrap a set in `<div class="row">`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `cert` | object | **yes** | - | Anything exposing `.title` and `.description` (the `Certification` entity). Both escaped. |
| `options.col` | string | no | `'col-md-6 col-lg-3'` | |
| `options.infoUrl` | string | no | - | When truthy, renders a "More information" link on the footer's left. |
| `options.control` | markup | no | - | Trusted captured HTML shown on the footer's right (a switch, buttons). |
| `options.bodyExtra` | markup | no | - | Trusted captured HTML appended under the description (status badge, validity). |

The footer is omitted entirely when neither `control` nor `infoUrl` is given; the body is omitted when
there is no description and no `bodyExtra`. `control` and `bodyExtra` are rendered with `|raw` - pass
developer-authored markup only, never user input.

```twig
<div class="row">
  {% for row in rows %}
    {% set control %}<a href="{{ path('...apply...') }}" class="btn btn-sm btn-primary">Apply</a>{% endset %}
    {{ d.certification_card(row.cert, {infoUrl: path('app_certifications_show', {id: row.cert.uuid}), control: control}) }}
  {% endfor %}
</div>
```

---

#### `definition_list(items, options = {})`

A `<dl class="row">` of label/value pairs.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `items` | array of `{label, value}` | **yes** | - | Both escaped. |
| `options.labelCol` | string | no | `'col-5'` | |
| `options.valueCol` | string | no | `'col-7'` | |
| `options.labelClass` | string (`''` honoured) | no | `'text-muted fw-normal'` | |
| `options.valueClass` | string (`''` honoured) | no | `'mb-1'` | |
| `options.class` | string (`''` honoured) | no | `'mb-0'` | Classes on the `<dl>` (in addition to `row`). |

```twig
{{ d.definition_list([
  {label: 'Username',   value: user.name},
  {label: 'Last login', value: user.lastLoginAt ? user.lastLoginAt|app_datetime : '-'}
]) }}
```

**Limitation - values are scalars only.** They are escaped, so a value cannot be a badge, a link, or
markup. `templates/dashboard/index.html.twig` deliberately hand-rolls its `<dl>` for exactly this
reason (its "Roles" value is a list of badges). If you need markup, hand-roll the `<dl>`.

---

#### `badge(text, style = 'text-bg-secondary', options = {})`

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `text` | string | **yes** | - | Escaped. |
| `style` | string | no | `'text-bg-secondary'` | Positional. Any Bootstrap/Tabler badge colour class (`text-bg-success`, `bg-blue-lt`, …). |
| `options.class` | string | no | - | Extra utility classes (`me-1`, `d-block`). |
| `options.attrs` | map | no | `{}` | `title`, `aria-label`, `data-*`. |

```twig
{{ d.badge('Staff only', 'text-bg-warning') }}
{{ d.badge('new', 'bg-primary-lt', {class: 'ms-2', attrs: {'aria-label': 'unread'}}) }}
```

---

#### `status_badge(status, options = {})`

Maps a status word to a Tabler soft (`-lt`) badge.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `status` | string | **yes** | - | Looked up **lower-cased** in the map. |
| `options.map` | map | no | `{}` | Merged **over** the defaults - extend the vocabulary instead of forking the macro. |
| `options.label` | string | no | `status` | Display text when it differs from the key. |
| `options.class` / `options.attrs` | | no | | Passed through to `badge()`. |

Built-in map:

| Style | Statuses |
|---|---|
| `bg-green-lt` | `active`, `enabled`, `open`, `ok`, `approved` |
| `bg-yellow-lt` | `pending`, `waiting`, `draft` |
| `bg-secondary-lt` | `disabled`, `closed`, `inactive` |
| `bg-red-lt` | `error`, `blocked`, `rejected`, `failed` |
| `bg-blue-lt` | **anything not in the map** (fallback) |

```twig
{{ d.status_badge(shift.state, {
  map: {'cancelled': 'bg-red-lt', 'running': 'bg-azure-lt'},
  label: shift.state|capitalize
}) }}
```

Limitations: the vocabulary is English and the lookup is exact after `|lower`. Unknown statuses do not
error - they silently render blue.

---

#### `empty_state(title, options = {})`

The **full-height** Tabler `.empty` block: for a whole page or section with nothing in it.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string | **yes** | - | `.empty-title`. |
| `options.message` | string | no | - | `.empty-subtitle`. |
| `options.iconHtml` | **trusted HTML** | no | - | `|raw`, in `.empty-icon`. |
| `options.actionLabel` | string | no | - | **Both** `actionLabel` and `actionHref` must be given, or no button renders. |
| `options.actionHref` | string | no | - | |
| `options.actionClass` | string | no | `'btn btn-primary'` | |

```twig
{{ d.empty_state('No locations yet', {
  message: 'Create the first location to start assigning shifts.',
  actionLabel: '+ New location',
  actionHref: path('app_manage_location_new')
}) }}
```

Do **not** use it for "this small list inside a card is empty" - it is far too heavy. Use `empty_inline`.

---

#### `empty_inline(message, options = {})`

The lightweight "nothing here" line (~26 templates hand-rolled this).

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `message` | string | **yes** | - | Escaped. |
| `options.tag` | string | no | `'p'` | e.g. `'span'`, `'div'`, `'li'`. |
| `options.class` | string (**`''` NOT honoured**) | no | `'text-muted mb-0'` | Passing `''` silently restores the default. |

```twig
{% for group in user.groups %}
  {{ d.badge(group.name, 'bg-blue-lt') }}
{% else %}
  {{ d.empty_inline('No groups assigned.', {tag: 'span', class: 'text-muted'}) }}
{% endfor %}
```

---

#### `empty_row(colspan, message, options = {})`

An empty-state row inside a `<tbody>`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `colspan` | int | **yes** | - | Must equal the table's column count. |
| `message` | string | **yes** | - | Escaped. |
| `options.class` | string | no | `'text-secondary text-center py-3'` | Classes on the `<td>`. |

```twig
<tbody>
  {% for row in rows %}…{% else %}
    {{ d.empty_row(4, 'No shifts match this filter.') }}
  {% endfor %}
</tbody>
```

Accessibility: keep the message descriptive; never leave the cell blank.

---

#### `table_start(options = {})` / `table_end(options = {})`

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `options.striped` | bool | no | `true` | Adds `table-striped`. **`false` is currently ignored - see gotchas.** |
| `options.hover` | bool | no | `true` | Adds `table-hover`. **`false` is currently ignored.** |
| `options.compact` | bool | no | `false` | Adds `table-sm`. (`true` works.) |
| `options.class` | string | no | - | Extra classes: `card-table`, `table-vcenter`, `text-nowrap`, … |
| `options.responsive` | bool | no | `true` | Wraps the table in `.table-responsive`. **`false` is currently ignored.** |
| `options.attrs` | map | no | `{}` | Literal attributes on `<table>`. |

`table_end` takes only `responsive`, and **must be passed the same value as `table_start`** (otherwise
the wrapper `</div>` is unbalanced - today both sides are equally broken for `false`, so markup stays
balanced by accident).

```twig
{{ d.table_start() }}
  <thead><tr><th>Name</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
    {% for l in locations %}
      <tr>
        <td>{{ l.name }}</td>
        {{ d.row_actions([{label: 'Edit', href: path('app_manage_location_edit', {id: l.uuid})}]) }}
      </tr>
    {% endfor %}
  </tbody>
{{ d.table_end() }}
```

Limitations: no sortable headers, no built-in caption, no sticky header.

---

#### `delete_form(action, token, options = {})`

The destructive-action POST form used across ~20 CRUD index pages. It wires the `confirm` Stimulus
controller - **never** `window.confirm` (see `CLAUDE.md`).

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `action` | string (URL) | **yes** | - | Form `action`. |
| `token` | string | **yes** | - | **The caller supplies the CSRF token.** This macro never mints one. |
| `options.label` | string | no | `'Delete'` | Button text. |
| `options.message` | string | no | `'Are you sure?'` | `data-confirm-message-value`. |
| `options.class` | string | no | `'btn btn-sm btn-outline-danger'` | Button classes. |
| `options.tokenName` | string | no | `'_token'` | Name of the hidden field. |
| `options.confirmTitle` | string | no | (controller default `Please confirm`) | `data-confirm-title-value`. |
| `options.confirmLabel` | string | no | (controller default `Confirm`) | `data-confirm-confirm-label-value`. |
| `options.variant` | string | no | (controller default `danger`) | `data-confirm-variant-value`. |
| `options.attrs` | map | no | `{}` | Extra attributes on the `<form>`. |

The form is `class="d-inline"`, so it sits inline next to other row buttons.

```twig
{{ d.delete_form(
  path('app_manage_location_delete', {id: location.uuid}),
  csrf_token('delete' ~ location.id),
  {message: 'Delete location "' ~ location.name ~ '"?'}
) }}
```

Limitations: POST only; the button carries no `type="submit"` attribute (it defaults to submit); no
`method` spoofing (`DELETE`) - controllers accept POST.

---

#### `row_actions(actions = [], options = {})`

The trailing action cell of a CRUD table row.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `actions` | array of `{label, href, class}` | no | `[]` | `class` defaults to `btn btn-sm btn-outline-primary` per item. |
| `options.extraHtml` | **trusted HTML** | no | - | `|raw`, appended after the links - this is where `delete_form()` goes. |
| `options.cell` | bool | no | `true` | Wrap in `<td>`. **`false` is currently ignored - see gotchas.** |
| `options.class` | string | no | `'text-end text-nowrap'` | Classes on the `<td>`. |

**Authorization is the caller's job** - build the `actions` array conditionally.

```twig
{% set del %}
  {{ d.delete_form(path('app_manage_location_delete', {id: location.uuid}), csrf_token('delete' ~ location.id), {
    message: 'Delete location "' ~ location.name ~ '"?'
  }) }}
{% endset %}
{{ d.row_actions([
  {label: 'Edit', href: path('app_manage_location_edit', {id: location.uuid})}
], {extraHtml: del}) }}
```

(Full page: `templates/manage/location/index.html.twig`.)

Accessibility: actions stay real `<a>` links and a real `<form>`; never degrade them into clickable
`<div>`s.

---

### forms - `templates/components/forms/_macros.twig`

These wrap Symfony's `form_label` / `form_widget` / `form_errors`. They take a **form view row**
(`form.email`), not an entity. They all render their own `mb-3` (or `mb-2`) spacing and a grid column.
Two of them (`range_slider`, `search_input`) depend on `assets/js/forms.js`, which initialises on the
`turbo:load` event.

#### `field(row, options = {})`

The general-purpose text/number/email/date/textarea field (~94 call sites). Forces `form-control` onto
the widget while preserving any classes the form type set.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `row` | FormView | **yes** | - | |
| `options.label` | string | no | (form's own label) | |
| `options.help` | string | no | - | `.form-text` under the field. |
| `options.prefix` | string | no | - | Input-group prefix; escaped text. |
| `options.suffix` | string | no | - | Input-group suffix; escaped text. |
| `options.col` | string | no | `'col-12'` | Bootstrap grid class (`'col-12 col-md-6'`). |

```twig
{{ f.field(form.name, {col: 'col-12 col-md-6'}) }}
{{ f.field(form.phone, {help: 'Internal phone number.', prefix: '#'}) }}
```

Limitations: `prefix`/`suffix` are plain text, not HTML. Errors render *below* the input group.

---

#### `datetime_local(row, options = {})`

Same as `field`, but forces the widget to `type="datetime-local"`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `row` | FormView | **yes** | - | |
| `options.label` / `options.help` / `options.col` | | no | `col-12` | As `field`. |

No prefix/suffix support.

---

#### `range_slider(row, options = {})`

A `<input type="range">` with a live value badge. The badge is filled by `initRangeSliders()` in
`assets/js/forms.js`, which reads `data-range-value-target`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `row` | FormView | **yes** | - | |
| `options.col` | string | no | `'col-12'` | |
| `options.label` | string | no | (form's label) | |
| `options.help` | string | no | - | |
| `options.showValue` | bool | no | `true` | Render the value badge. **`false` is currently ignored - see gotchas.** |
| `options.valueTargetId` | string | no | `<field id>_value` | Element id of the badge. |
| `options.min` / `options.max` / `options.step` | number | no | `0` / `100` / `1` | |

```twig
{{ f.range_slider(form.weight, {min: 1, max: 10, help: 'Higher = more likely to be scheduled.'}) }}
```

Limitations: demo-only in production today. The badge is empty until the JS runs; if the field is
injected after `turbo:load` (e.g. into a Turbo Frame), nothing re-initialises it.

---

#### `search_input(name, options = {})`

A standalone (non-Symfony-Form) search box that debounces into a Turbo Frame.
`initSearchInputs()` in `assets/js/forms.js` reads `data-search-debounce` / `data-search-turbo-frame`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `name` | string | **yes** | - | The query-parameter name of the input. |
| `options.id` | string | no | `'search_' ~ name` | |
| `options.label` | string | no | `'Search'` | |
| `options.placeholder` | string | no | `'Type to search…'` | |
| `options.col` | string | no | `'col-12'` | |
| `options.debounceMs` | int | no | `250` | |
| `options.turboFrame` | string | no | - | **Without it the input does nothing.** Id of the `<turbo-frame>` to refresh. |
| `options.help` | string | no | - | |

```twig
{{ f.search_input('q', {turboFrame: 'user-results', placeholder: 'Search users…'}) }}
<turbo-frame id="user-results" src="{{ path('app_users_frame') }}">…</turbo-frame>
```

Limitations, all real:
- The JS always sets the **`q`** query parameter on the frame's URL, regardless of `name`. Name the
  field `q` unless you also change `forms.js`.
- The target frame must already have a `src` attribute (the JS falls back to a `data-search-url`
  attribute on the input, which **this macro never emits**).
- No form wrapper, no CSRF, no submit fallback for a JS-less client.

---

#### `check(row, options = {})`

Bootstrap `.form-check` checkbox.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `row` | FormView | **yes** | - | |
| `options.col` | string | no | `'col-12 col-md-6'` | Note: differs from the other form macros. |
| `options.label` | string | no | (form's label) | |
| `options.help` | string | no | - | |

Accessibility: label follows the input and is `.form-check-label`, so Symfony's `for`/`id` pairing does
the work - do not set `label: ''`.

---

#### `switch(row, options = {})`

A checkbox rendered as a Bootstrap/Tabler toggle switch (`.form-check.form-switch`). Same contract and
accessibility notes as `check()`; use it for on/off configuration flags where a switch reads better than
a checkbox.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `row` | FormView | **yes** | - | |
| `options.col` | string | no | `'col-12 col-md-6'` | |
| `options.label` | string | no | (form's label) | |
| `options.help` | string | no | - | |
| `options.attr` | map | no | - | Extra input attributes merged onto the widget (e.g. Stimulus `data-*` wiring). |

---

#### `choice(row, options = {})`

A single `<select>` (`ChoiceType`), styled `form-select`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `row` | FormView | **yes** | - | |
| `options.col` | string | no | `'col-12'` | |
| `options.label` / `options.help` | | no | | |

---

#### `choice_multiple(row, options = {})`

A `<select multiple>` (`ChoiceType` with `multiple: true`), rendered as a sized list box.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `row` | FormView | **yes** | - | |
| `options.col` | string | no | `'col-12'` | |
| `options.label` / `options.help` | | no | | |
| `options.size` | int | no | field's own `size`, else `6` | Visible rows. |

It also emits `data-multiselect="1"`. **No JavaScript reads that attribute** - it is a leftover of the
deleted `multi_select()` macro. Do not build anything on it. For a tag/chips **user** picker, use
`user_select` below.

```twig
{{ f.choice_multiple(form.privileges, {size: 10, help: 'Ctrl/Cmd-click to select several.'}) }}
```

#### `user_select(name, options = {})`

A tag-style, type-ahead **user** picker: type a username, pick from a live dropdown (avatar + name,
with a `(staff)` suffix for staff), and each pick becomes a removable chip. It submits one hidden
`<name>[]` input per picked user, so it drops straight into a normal POST form - no JS submit needed.
Behaviour is the `user_select` Stimulus controller (`assets/controllers/user_select_controller.js`);
styling is `assets/styles/user-select.css`. Reusable anywhere: supply a JSON search endpoint.

The endpoint receives `?q=<text>` and returns `{results: [{id, name, staff: bool, avatar: string|null}]}`.
Search is username-only partial matching (`UserRepository::searchByName`); the caller's endpoint decides
scope/authorization and which users to exclude (e.g. already-assigned).

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `name` | string | **yes** | - | Hidden inputs are named `<name>[]`. |
| `options.url` | string | **yes** | - | The JSON search endpoint. |
| `options.label` | string\|false | no | `'Add users'` | `false` omits the label. |
| `options.placeholder` / `options.help` / `options.col` | | no | | |
| `options.minChars` | int | no | `1` | Characters typed before searching. |
| `options.staffSuffix` | bool | no | `true` | Append `' (staff)'` to staff names. |
| `options.selected` | list | no | `[]` | `{id, name, staff, avatar}` chips to pre-render (edit forms). |

```twig
<form method="post" action="{{ path('app_shift_staffing_assign', {id: shift.uuid}) }}">
  <input type="hidden" name="_token" value="{{ csrf_token('staffing_assign' ~ shift.id) }}">
  {{ f.user_select('users', {url: path('app_shift_staffing_search', {id: shift.uuid}), label: false}) }}
  <button class="btn btn-primary">Assign</button>
</form>
```

The macro never mints CSRF tokens or decides authorization - the caller supplies the token and the
scoped search endpoint (mirrors the `d.delete_form()` convention).

---

### navigation - `templates/components/navigation/_macros.twig`

`options.icon` is a Tabler icon **slug** (see the icon macro), not an emoji.

#### `nav_item(label, routeName = null, options = {})`

A horizontal-navbar `<li class="nav-item">`. Used by `templates/base.html.twig` (its only production
consumer, by design).

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `label` | string | **yes** | - | Escaped. |
| `routeName` | string\|null | no | `null` | Route name; the `href` is `path(routeName, params)`. |
| `options.href` | string | no | - | Use instead of `routeName` for a plain/external link. With neither, the href is `#`. |
| `options.params` | map | no | `{}` | Route params. |
| `options.active` | bool | no | `false` | Force the active state (for links the router cannot resolve). |
| `options.exact` | bool | no | `true` | `true`: active iff current route == `routeName`. `false`: active if the current route *starts with* `routeName`. **`false` is currently ignored - see gotchas.** |
| `options.icon` | string (slug) | no | - | Rendered inside `.nav-link-icon`. |
| `options.badge` | string\|number | no | - | Small badge after the label. |
| `options.class` | string | no | `''` | Extra classes on the `<a class="nav-link">`. |

Accessibility: the active item gets `aria-current="page"`.

```twig
{% if is_granted('shift:view') %}
  {{ nav.nav_item('Shifts', 'app_shift_index', {icon: 'clock', exact: false}) }}
{% endif %}
```

---

#### `sidebar_item(label, routeName = null, options = {})`

A vertical `list-group-item` entry (the Tabler account-settings pattern).

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `label` | string | **yes** | - | Escaped. |
| `routeName` | string\|null | no | `null` | |
| `options.href` | string | no | - | For a plain link or in-page anchor (`'#profile'`). |
| `options.active` | bool | no | `false` | Force active - needed for anchors. |
| `options.params` | map | no | `{}` | |
| `options.exact` | bool | no | `true` | Same semantics as `nav_item`, **and the same `false`-is-ignored bug.** |
| `options.icon` | string (slug) | no | - | Rendered at `icon-1 text-muted`. |
| `options.badge` | string\|number | no | - | |

**No `class` option** (unlike `nav_item`).

```twig
<div class="list-group">
  {{ nav.sidebar_item('Profile & account', null, {href: '#profile', active: true}) }}
  {{ nav.sidebar_item('My availability', 'app_availability') }}
  {{ nav.sidebar_item('Two-factor authentication', 'app_2fa') }}
</div>
```

(Real page: `templates/settings/index.html.twig`.)

---

#### `sidebar(title, contentHtml)`

A card whose body is a flush list-group - a titled wrapper around a run of `sidebar_item()` calls.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string | **yes** | - | Escaped, rendered as `<h3 class="card-title">`. |
| `contentHtml` | **trusted HTML** | **yes** | - | `|raw`. Capture the `sidebar_item()` calls into a variable. |

```twig
{% set items %}
  {{ nav.sidebar_item('Overview', 'app_manage', {icon: 'dashboard'}) }}
  {{ nav.sidebar_item('Users',    'app_manage_users', {icon: 'users'}) }}
{% endset %}
{{ nav.sidebar('Manage', items) }}
```

Limitations: `contentHtml` is a positional **required** parameter, and it is `|raw` - capture blocks
only, never user input. Demo-only today (`settings/index` uses a bare `<div class="list-group">`).

---

### notification - `templates/components/notification/_macros.twig`

#### `alert(type, message, options = {})`

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `type` | string | **yes** | - | Bootstrap variant: `success`, `danger`, `warning`, `info`, `secondary`. Interpolated straight into `alert-{{ type }}` - pass a known variant, not a free string. |
| `message` | string | **yes** | - | Escaped. Ignored when `messageHtml` is given. |
| `options.dismissible` | bool | no | `false` | Adds `alert-dismissible fade show` and a `btn-close` (`data-bs-dismiss="alert"`). |
| `options.icon` | string | no | - | **Escaped text**, not HTML - an emoji. Rendered `aria-hidden`. |
| `options.messageHtml` | **trusted HTML** | no | - | `|raw`, used *instead of* `message` when the alert needs a link or emphasis. |

Accessibility: always emits `role="alert"`.

```twig
{% set body %}Your export is ready - <a href="{{ path('app_export') }}">download it</a>.{% endset %}
{{ n.alert('info', '', {messageHtml: body, dismissible: true}) }}
```

---

#### `flash_messages(app)`

Renders every Symfony flash as a dismissible `alert()`. Called once, in `templates/base.html.twig`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `app` | the Twig `app` global | **yes** | - | Macros are isolated, so `app` must be passed in explicitly. |

**The flash *type* becomes the Bootstrap variant**, so controllers must use
`addFlash('success' | 'danger' | 'warning' | 'info', …)`. `addFlash('error', …)` would render a
non-existent `alert-error` class and come out unstyled.

```twig
{{ n.flash_messages(app) }}
```

---

#### `toast(id, title, message, options = {})`

A Bootstrap toast element. It is *shown* by `assets/js/notifications.js`, which listens for clicks on
any `[data-toast-target]` element and calls `bootstrap.Toast.getOrCreateInstance(...).show()`.

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `id` | string | **yes** | - | DOM id, referenced by the trigger's `data-toast-target`. |
| `title` | string | **yes** | - | Escaped, bold. |
| `message` | string | **yes** | - | Escaped. |
| `options.type` | string | no | `'secondary'` | Bootstrap colour → `text-bg-{{ type }}`. |
| `options.delay` | int (ms) | no | `5000` | `data-bs-delay`. |
| `options.autohide` | bool | no | `true` | `data-bs-autohide`. **`false` is currently ignored - see gotchas.** |

Accessibility: emits `role="alert"`, `aria-live="assertive"`, `aria-atomic="true"`.

```twig
{{ n.toast('saved-toast', 'Saved', 'Your changes were stored.', {type: 'success'}) }}
<button class="btn" data-toast-target="#saved-toast">Save</button>
```

Limitations: the macro only renders the toast markup - you place it (a `.toast-container` is your job)
and you trigger it.

---

### icon - `templates/components/icon/_macros.twig`

#### `icon(name, options = {})`

An inline 24×24 Tabler SVG that inherits `currentColor` (so it follows the active theme).

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `name` | string (slug) | **yes** | - | A key of the internal map. **An unknown slug silently renders a neutral dot (`point`)** - a typo never breaks layout, and never errors either. |
| `options.class` | string | no | `''` | Extra classes; `icon` is always present. `icon-1`, `icon-2` size it. |

Available slugs (33):

`activity`, `award`, `bell`, `building`, `calendar`, `checklist`, `chevron-down`, `clock`, `code`,
`compass`, `dashboard`, `gift`, `help`, `home`, `id`, `lock`, `login`, `logout`, `mail`, `map-pin`,
`message`, `news`, `palette`, `plus`, `point`, `puzzle`, `send`, `settings`, `shield`, `tools`,
`user`, `user-search`, `users`.

Add an icon by pasting its inner `<path>` markup from <https://tabler.io/icons> into the map inside
the macro (the map lives inside the macro because Twig macros are isolated).

Accessibility: the `<svg>` always carries `aria-hidden="true"`. It is decorative by definition - any
control whose only content is an icon must carry its own `aria-label`.

```twig
{{ i.icon('home') }}
{{ i.icon('plus', {class: 'icon-2'}) }}
```

Note: Symfony UX Icons (`ux:icon`) is **not** installed; this map is the icon system.

---

## 3. Gotchas that WILL bite

### 3.1 Twig's `|default` fires on **empty** values - not just undefined

This is the single most dangerous thing in this layer. In Twig, `x|default(y)` returns `y` whenever `x`
is *empty* - and empty means `undefined`, `null`, `''`, `0`, `[]`, **and `false`**.

**Consequence A - empty strings.** Any option whose empty value is *meaningful* must be read with
`is defined ?`, never `|default`:

```twig
{# WRONG: card_start({class: ''}) silently gets 'mb-3' back #}
{{ options.class|default('mb-3') }}

{# RIGHT #}
{{ options.class is defined ? options.class : 'mb-3' }}
```

This exact bug once re-added `mb-3` to **every card that asked for no margin** - including the
notification bell, which renders on every single page - and the whole test suite stayed green,
because no test asserts on a spacing class. 20+ templates pass `class: ''` to `card_start`.
When you add an option, decide up front whether `''` is meaningful; if it is, use `is defined ?`.
The reference tables above mark each option as "`''` honoured" or not.

**Consequence B - booleans, and this is live today.** Every boolean option that defaults to `true` is
implemented as `options.foo|default(true)`, so **passing `false` is a no-op**:

| Macro | Option | Passing `false` should… | What actually happens |
|---|---|---|---|
| `table_start` / `table_end` | `responsive` | drop the `.table-responsive` wrapper | wrapper still rendered (both ends agree, so the markup is at least balanced) |
| `table_start` | `striped` | drop `table-striped` | still striped |
| `table_start` | `hover` | drop `table-hover` | still hoverable (10+ templates pass `hover: false`) |
| `row_actions` | `cell` | emit buttons without a `<td>` | `<td>` still emitted |
| `nav_item` / `sidebar_item` | `exact` | prefix-match the route for the active state | still exact-matches, so `base.html.twig`'s nav items never light up on sub-pages |
| `toast` | `autohide` | render `data-bs-autohide="false"` | renders `"true"` |
| `range_slider` | `showValue` | hide the value badge | badge still rendered |

Options that default to `false` (`compact`, `dismissible`, `active`) are fine - `true` is not empty.
**If you fix any of these, fix `table_start` and `table_end` together**, or you will unbalance the DOM.

### 3.2 Start/end pairs are one element split in two

- `card_start` / `card_end`: **`bodyClass` and `tag` must match.** `bodyClass: ''` on one end only
  produces an unclosed (or spuriously closed) `<div>`.
- `table_start` / `table_end`: pass the **same `responsive` value** to both.
- `message_card_start` / `message_card_end`: `message_card_end()` takes no options - nothing to mismatch.
- `lint:twig` cannot detect an unbalanced pair; each macro call is individually valid Twig.

### 3.3 Macros make no security decisions

- **They never mint CSRF tokens.** `delete_form(action, token, …)` takes the token; the caller writes
  `csrf_token('delete' ~ entity.id)`.
- **They never authorize.** The caller wraps the call: `{% if is_granted('location:manage') %}…{% endif %}`,
  or builds the `row_actions` array conditionally.

### 3.4 `...Html` options are `|raw`

`iconHtml`, `headerActions`, `footer`, `extraHtml`, `actionsHtml`, `messageHtml`, `contentHtml` are all
rendered unescaped. **Never** pass user input, entity fields, or request data into them - only
`{% set x %}…{% endset %}` capture blocks you wrote yourself. (Stored rich text is the one exception in
this app, and it goes through `App\Service\RichTextSanitizer` first.)

`attrs` maps are half-raw: **keys** are printed verbatim, values are escaped. Keys must be literals.

### 3.5 Everything else that trips people up

- **Macros are isolated.** They cannot see `app`, your imports, or your `{% set %}`s. That is why
  `flash_messages(app)` takes `app` as a parameter and why `nav_item` re-imports the icon macros.
- **`icon('typo')` does not fail** - it renders a dot. Check your slug against the list.
- **`definition_list` escapes values** - no badges or links in a `<dl>` built by it.
- **`search_input`** only works with a `turboFrame` **that already has a `src`**, and the JS hard-codes
  the `q` parameter name.
- **`empty_state` vs `empty_inline` vs `empty_row`** are three different weights; picking `empty_state`
  inside a card produces a huge blank block.

---

## 4. Usage guidelines

1. **Prefer an existing component.** ~65 templates import these macros; a new one-off structure is a
   maintenance liability.
2. **Extend, don't fork.** When a macro *nearly* fits, add a backward-compatible option to it. The
   failure mode to avoid is real: `shift/index` and `certification/index` each defined a *local copy* of
   `status_badge` because the shared one had no injectable status map. The fix was one `options.map`
   parameter. Forking hides the defect; extending fixes it for everyone.
3. **Backward compatibility is mandatory** when a macro has callers. New options get defaults that
   reproduce today's output. Never change a signature's positional arguments.
4. **No business logic, no authorization in a macro.** A macro renders what it is given. `is_granted`,
   `csrf_token`, and query logic live in the template or the controller.
5. **Don't pass whole entities.** Pass the two or three scalars the macro needs
   (`d.badge(location.name, …)`, not `d.badge(location)`). Entities in macros invite business logic in.
6. **No `|raw` on untrusted content**, ever. See 3.4.
7. **No `window.confirm()` / `window.alert()`.** Use `data-controller="confirm"` on the form (which is
   what `delete_form` does), or `confirmModal()` / `alertModal()` from `assets/js/modal.js` in JS. Both
   are project rules in `CLAUDE.md`, not preferences.
8. **Run `php bin/console lint:twig templates` and `php bin/phpunit`** after touching a macro - a
   signature change ripples across ~65 templates.

---

## 5. What is deliberately NOT a component

A component is **earned by real reuse**, not anticipated. Each of these was measured across all 167
templates and left alone:

| Pattern | Reality | Decision |
|---|---|---|
| **Dropdowns** | 5 in the whole app (user menu, notification bell, duty picker, row menu, dev menu) - all with different triggers and item semantics | Keep bespoke. A macro general enough for all five would take more options than markup. |
| **Tabs / pills** | 0 in production | Nothing to abstract. |
| **Breadcrumbs** | 0. The app uses 22 `← Manage`-style back links inside `page_header.actions` | Nothing to abstract. |
| **Sortable table headers** | 0 - no sorting exists anywhere | Nothing to abstract. |
| **Collapsible cards / accordions** | 0 (one FAQ accordion, page-specific) | Nothing to abstract. |
| **Progress bars** | 1 real use, already a reusable partial (`install/_progress.html.twig`) | Already solved by an `{% include %}`. |
| **Timelines** | 0 Tabler timelines ("timeline" in this app is a business concept, not the widget) | Nothing to abstract. |
| **Filter bars** | 14 `<form method="get">` filter bars in 3 shapes | The wrapper is generic; the *fields* are business-specific. Low value. |
| **Button groups** | 1 (`availability/index`, already carries `role` / `aria-label`) | Nothing to abstract. |
| **Avatars** | 6 sites, 3 different shapes | Deferred. Revisit if it grows. |

If you find yourself writing the third copy of something, *then* it is a component.

---

## 6. Removed components - do not reintroduce

| Removed | Why |
|---|---|
| `components/modal/_macros.twig` - `confirm()`, `form_modal()`, `detail()` (whole file deleted) | **Broken and dangerous.** `confirm()` rendered a POST form with **no CSRF token**; `form_modal()`'s submit button sat **outside** the `<form>` it claimed to submit (no `form=` attribute), so it could not work. They had zero production callers and they contradicted the project rule (`confirm_controller.js` / `modal.js`). The demo page was teaching a broken, unprotected pattern. **Use `data-controller="confirm"` or `confirmModal()`/`alertModal()` instead.** |
| `data.pagination()` | **Broken.** It hard-coded `?page=N`, discarding every other query parameter (filters, search), and rendered every page with no windowing. Nothing in the app paginates. If pagination is ever needed, write it against a real caller and preserve the query string. |
| `forms.multi_select()` | **Never functional.** Not bound to a Symfony form (no CSRF, no error display), and it emitted `data-multiselect` - an attribute **no JavaScript reads**. Use `choice_multiple()`. (That attribute survives in `choice_multiple`; it is inert.) |
| `data.card(title, body, options)` - the old string-body card | **Superseded by `card_start`/`card_end`.** Its body was a pre-rendered string, which no real card body survives (loops, `is_granted`, `form_widget`), so it had 1 caller - a demo page - while the app contained 126 hand-rolled cards in 73 files. It also hard-coded `mb-3`, emitted `<div class="fw-bold">` instead of a real `<h3 class="card-title">` heading, could not be a `<form>`, and could not drop `card-body`. Keeping a shim would leave two ways to build a card - exactly the ambiguity that caused the mess. |

The rule that produced this list: **delete what is broken or unwired; keep what works only by giving it
a real production caller.** A macro whose only caller is a demo page is not a reusable component - it is
an unverified claim, and when it is also broken it is a trap.

---

## 7. New-component checklist

Before adding a macro to `templates/components/`:

- [ ] The structure is reused, or clearly reusable.
- [ ] An equivalent component does not already exist (and cannot be reached by extending one).
- [ ] The component contains no business logic and makes no authorization decision.
- [ ] Parameters are documented (in the macro's own comment **and** in this file).
- [ ] Defaults are sensible - and for every option, you decided whether `''`/`false` is meaningful and
      used `is defined ?` if it is (see 3.1).
- [ ] Content escaping is safe: anything `|raw` is named `...Html` and documented as trusted.
- [ ] Accessibility reviewed: heading levels, `<button>` vs `<a>`, accessible names,
      `aria-current` on active nav, `aria-hidden` on decorative icons.
- [ ] Responsive behaviour checked (narrow container, long content, many columns).
- [ ] A demo has been added to the relevant kit page (hub: `/dev/ui/navigation-kit`).
- [ ] Documentation updated (this file).
- [ ] `php bin/console lint:twig templates` passes.
- [ ] Existing behaviour is unchanged - `php bin/phpunit` is green and the rendered markup of existing
      callers is identical.

---

## 8. The demo pages ("kits")

Every component is rendered, with its variants and edge cases, on one of six kit pages.
**`/dev/ui/navigation-kit` is the hub** and indexes the others.

| Kit | Route | Controller | Covers |
|---|---|---|---|
| **Navigation Kit (hub)** | `/dev/ui/navigation-kit` | `src/Dev/Controller/NavigationKitController.php` | Index of all kits; `nav_item`, `sidebar_item`, `sidebar` |
| Data Kit | `/dev/ui/data-kit` | `DataKitController.php` | `page_header`, `section_header`, cards, `message_card_*`, `action_card`, `stat_card`, `definition_list`, badges, empty states, tables, `delete_form`, `row_actions` |
| Form Kit | `/dev/kit` | `FormKitController.php` | `field`, `datetime_local`, `range_slider`, `search_input`, `check`, `switch`, `choice`, `choice_multiple` |
| Modal Kit | `/dev/ui/modal-kit` | `ModalKitController.php` | The **supported** dialog mechanism: `data-controller="confirm"` and `confirmModal()`/`alertModal()` |
| Notification Kit | `/dev/ui/notification-kit` | `NotificationKitController.php` | `alert`, `flash_messages`, `toast` |
| Theme Kit | `/dev/kit/themes` | `ThemeKitController.php` | The registered themes and their Tabler variables |

(The route paths are inconsistent - `/dev/kit*` vs `/dev/ui/*-kit`. That is historical; renaming routes
was out of scope and buys nothing.)

### Access rules

1. **They do not exist in production.** The kit controllers live in `src/Dev/Controller`
   (`App\Dev\` namespace), and `config/services.yaml` **excludes `../src/Dev/`** from the `App\`
   service resource, re-registering it only under `when@dev` and `when@test`. Symfony's
   `routing.controllers` loader derives routes from controller **services**, not from a directory scan -
   so in prod these controllers are not services, and therefore **have no route at all**. Hitting
   `/dev/ui/data-kit` in production is a **404, not a 403**.
2. **In dev/test they require the super-privilege.** Every kit controller carries
   `#[IsGranted('global:admin')]`.

   `global:admin` is `PrivilegeCatalog::SUPER` (`src/Security/PrivilegeCatalog.php`). A bare
   `'admin'` attribute is **not** a privilege and **no voter grants it** - `#[IsGranted('admin')]`
   would deny everyone. Use `global:admin`.

3. Demo data on these pages is obviously fake - no real volunteer or event data.
