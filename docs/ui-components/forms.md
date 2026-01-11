# Form Components (CS-001)

Twig macros live in:
- `templates/components/forms/_macros.twig`

JS helpers live in:
- `assets/js/forms.js` (Turbo-safe)

## Usage

```twig
{% import 'components/forms/_macros.twig' as f %}

{{ f.field(form.title) }}
{{ f.datetime_local(form.startsAt) }}
{{ f.range_slider(form.priority, {min: 0, max: 10, step: 1}) }}
{{ f.choice_multiple(form.departments, {size: 6}) }}
````

## Debounced search with Turbo Frames

Use:

* `f.search_input(..., {turboFrame: 'frame_id'})`
* `<turbo-frame id="frame_id" src="..."></turbo-frame>`

```
