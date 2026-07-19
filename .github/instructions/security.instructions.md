---
applyTo: "**/*.php,**/*.twig,**/*.js,**/*.ts,config/**/*.yaml,config/**/*.yml,composer.json,composer.lock,importmap.php,compose*.yaml,.github/workflows/**/*"
---

Apply `SECURITY_AUDIT_INSTRUCTIONS.md` to this file.

Before suggesting or approving a change, identify whether it affects:

- authentication or account linking;
- roles, permissions, voters, ownership, event scope, or department scope;
- routes, controllers, APIs, commands, messages, or webhooks;
- forms, DTOs, serializers, entity setters, or mass assignment;
- Doctrine queries and object loading;
- sessions, cookies, CSRF, tokens, QR codes, or replay protection;
- Twig/DOM output encoding;
- files, URLs, processes, notifications, exports, or personal data;
- dependencies, CI, containers, secrets, or production configuration.

For sensitive changes, require server-side authorization and a negative regression test. Highlight self-privilege escalation, IDOR/BOLA, cross-department/event access, request/cookie manipulation, missing CSRF, query-scope removal, and missing test coverage.
