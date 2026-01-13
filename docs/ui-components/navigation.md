# UI Navigation – Coding Guideline (CS-005)

## 1) Always use the navigation macros

**DO**

```twig
{% import 'components/navigation/_macros.twig' as nav %}
```

**DON’T**

- Write navbar or sidebar HTML manually
- Duplicate navigation markup across templates
- Hardcode `active` classes

All navigation must go through macros.

---

## 2) Navigation layout is owned by `base.html.twig`

- Navbar lives **only** in `base.html.twig`
- Sidebar slot is defined in `base.html.twig`
- Pages may:

  - populate the sidebar block, or
  - leave it empty

**DON’T**

- Define navbars inside pages
- Override navbar per page (except very special cases later)

---

## 3) Active state rules (strict)

- Active state is determined **only by route name**
- Never compare URLs or paths manually
- Use:

  - `exact: true` for single routes
  - `exact: false` for route groups

**DO**

```twig
{{ nav.nav_item('Users', 'app_user_index', { exact: false }) }}
```

**DON’T**

```twig
{% if app.request.uri matches '/users' %}
```

---

## 4) Sidebar usage rules

- Sidebar is optional
- When used:

  - Always use `nav.sidebar()` + `nav.sidebar_item()`
  - Never mix card/list-group markup manually

**DO**

```twig
{% block sidebar %}
  {{ nav.sidebar('Management', items) }}
{% endblock %}
```

---

## 5) Icons & badges

- Icons are optional, decorative only
- Badges are allowed only for counts or states
- Icons must not carry meaning alone

No SVG/icon frameworks required for MVP.

---

## 6) Responsive behavior (mandatory)

- Navbar must collapse on mobile
- Sidebar stacks above content on mobile
- No fixed sidebars or custom breakpoints

Bootstrap defaults only.

---

## 7) Dev kit vs production navigation

- Dev-kit routes may appear in navigation during development
- Production navigation must:

  - hide `/dev/*` routes
  - be configurable later (future CS ticket)

Do not special-case dev routes in macros.

---

## 8) No JavaScript for navigation (strict)

- No JS for active state
- No JS for collapsing behavior beyond Bootstrap
- No click handlers for routing

Navigation is 100% server-rendered.

---

## 9) File locations (no exceptions)

- Macros: `templates/components/navigation/_macros.twig`
- Layout: `templates/base.html.twig`
- Demo: `/dev/ui/navigation-kit`

---

## 10) The “single source of truth” rule

> If a navigation change requires editing more than one file,
> CS-005 has failed.

All nav changes happen in **one place**.
