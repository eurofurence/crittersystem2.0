# AI-Assisted Development Policy

## Critter System 2.0 / Eurofurence VMS

This project is **AI-friendly**.

Developers may use AI tools such as ChatGPT, Claude, GitHub Copilot, local language models, code-review agents, and similar systems to support development, documentation, testing, analysis, and security review.

However, this project is **not agent-driven**.

AI may assist a developer, but it may not replace the developer's understanding, judgment, accountability, or review. A human contributor remains fully responsible for every line, configuration change, migration, dependency, test, and architectural decision submitted to the project.

---

## 1. Core principle

> AI may help create a change, but a human must understand, verify, test, and take responsibility for it.

Submitting code that the contributor does not understand is not acceptable.

Using an AI agent does not reduce the contributor's responsibility for:

- correctness;
- security;
- privacy;
- licensing;
- maintainability;
- test coverage;
- documentation;
- compatibility;
- operational impact;
- compliance with project rules.

The person opening the pull request is the responsible author of the contribution, even when AI generated part or all of the initial implementation.

---

## 2. Acceptable uses of AI

AI tools may be used to assist with:

- explaining unfamiliar code;
- suggesting implementation approaches;
- generating initial drafts;
- creating unit, integration, functional, and security tests;
- reviewing code for defects and vulnerabilities;
- identifying missing authorization checks;
- reviewing Symfony routes, voters, forms, services, and Doctrine queries;
- generating repetitive or boilerplate code;
- improving documentation;
- preparing migration plans;
- identifying edge cases;
- proposing refactoring options;
- reviewing pull-request diffs;
- checking coding standards;
- creating test data that contains no real personal information;
- translating project documentation;
- helping investigate test failures;
- creating development scripts;
- suggesting performance improvements.

AI output must always be treated as a proposal, not as an authoritative answer.

---

## 3. Mandatory human responsibilities

Before committing AI-assisted work, the contributor must:

1. Read and understand the complete change.
2. Review the full diff, not only the agent's summary.
3. Confirm that the implementation matches the issue and project requirements.
4. Check that no unrelated code was changed.
5. Verify that authorization is enforced server-side.
6. Verify event, department, owner, and object-level access rules.
7. Review all user-controlled input.
8. Check for privilege escalation, IDOR/BOLA, mass assignment, CSRF, XSS, injection, and information disclosure.
9. Confirm that no credentials, tokens, personal information, or production data were exposed to the AI tool.
10. Run the relevant tests, linters, static analysis, and security checks.
11. Add or update negative tests for security-sensitive behavior.
12. Review generated database migrations manually.
13. Review dependency and lock-file changes manually.
14. Confirm that generated code is compatible with the project's license and dependency policies.
15. Update documentation when behavior, configuration, or operational procedures change.
16. Be able to explain the implementation during review.

A contribution may be rejected when the author cannot explain how it works or why it is safe.

---

## 4. Required AI disclosure in commits

Any commit containing material AI-generated or AI-modified code, tests, documentation, configuration, or migration files must identify the AI assistant as a co-author.

Add a `Co-authored-by` trailer to the commit message:

```text
Co-authored-by: AI Assistant (<tool-name>) <ai-assistant@users.noreply.github.com>
```

Example:

```text
fix(security): enforce department scope on shift management

Reject shift changes when the manager does not have permission for the
shift's department and event. Add negative functional tests.

Co-authored-by: AI Assistant (Claude) <ai-assistant@users.noreply.github.com>
```

Other examples:

```text
Co-authored-by: AI Assistant (ChatGPT) <ai-assistant@users.noreply.github.com>
```

```text
Co-authored-by: AI Assistant (GitHub Copilot) <ai-assistant@users.noreply.github.com>
```

When more than one AI tool materially contributed, include one trailer for each tool.

The co-author trailer:

- provides transparency;
- does not transfer responsibility away from the human contributor;
- does not certify that the AI tool owns copyright;
- does not replace the contributor's normal sign-off, DCO, CLA, or licensing obligations.

Minor spelling corrections or passive editor autocomplete that did not materially influence the submitted change do not need separate attribution. When uncertain, disclose the AI usage.

---

## 5. Required pull-request disclosure

Pull requests containing AI-assisted work must include an **AI Assistance** section.

Use this template:

```markdown
## AI Assistance

- **Tools used:** Claude / ChatGPT / GitHub Copilot / other
- **Purpose:** Implementation draft, test generation, review, documentation, etc.
- **Human review performed:** Yes
- **Full diff manually reviewed:** Yes
- **Security-sensitive areas reviewed:** Authentication, authorization, object scope, input handling, CSRF, data exposure
- **Tests run:** List the exact commands
- **Known limitations or uncertain areas:** None, or describe them
```

Do not write only “AI was used.” State how it was used and what the human contributor verified.

---

## 6. Security requirements

For this project, AI-assisted changes must receive the same or greater security scrutiny as manually written changes.

Contributors must pay particular attention to:

- authentication bypass;
- missing route protection;
- missing voters or object-level authorization;
- cross-user access;
- cross-department access;
- cross-event access;
- self-assignment of roles or permissions;
- self-assignment of departments, positions, certifications, rewards, or staff status;
- manipulation of worked hours, attendance, no-show state, or shift assignments;
- insecure direct object references;
- unsafe form or serializer mapping;
- cookie and session manipulation;
- CSRF;
- account-linking takeover;
- QR-code replay or token reuse;
- webhook forgery or replay;
- SQL, DQL, command, template, and expression injection;
- XSS in Twig, Turbo, JavaScript, notifications, or emails;
- unsafe uploads or downloads;
- personal-data exposure;
- leaked secrets;
- insecure dependencies;
- unsafe production configuration.

Security must be enforced on the server. A hidden button, disabled input, missing menu item, filtered dropdown, or JavaScript role check is not authorization.

Security-sensitive changes must include negative tests proving that unauthorized users are denied.

The project security review instructions in `SECURITY_AUDIT_INSTRUCTIONS.md` apply to AI-assisted work.

---

## 7. Privacy and confidential information

Never provide an external AI service with:

- passwords;
- API keys;
- access tokens;
- session cookies;
- private keys;
- certificates containing private keys;
- production environment files;
- database dumps;
- private logs;
- personal user information;
- volunteer or staff records;
- health or accessibility information;
- private Telegram data;
- confidential event information;
- internal infrastructure details that are not already public;
- vulnerability details that have not yet been responsibly disclosed.

Use sanitized examples and synthetic test data.

Before uploading code, logs, screenshots, or configuration to an AI service, verify that the material is permitted to leave the project environment.

When organizational policy requires local processing, use an approved local model or do not use AI for that material.

---

## 8. Licensing and source provenance

AI output may reproduce patterns or text from unknown sources.

The contributor must ensure that:

- generated code is compatible with the project's license;
- no proprietary code was copied into the project;
- no dependency was added without review;
- generated notices and headers are accurate;
- third-party code is attributed when required;
- code from Stack Overflow, blogs, repositories, or documentation follows its original license;
- AI-generated output is not assumed to be original merely because the tool produced it.

When the provenance of a substantial generated section is unclear, rewrite it or do not submit it.

---

## 9. Tests and verification

AI-generated tests are not sufficient by themselves.

The contributor must confirm that tests:

- fail before the fix when appropriate;
- pass after the fix;
- test the intended behavior rather than the implementation detail;
- include unauthorized and invalid cases;
- do not weaken existing assertions;
- do not skip important paths;
- do not depend on external AI services;
- use synthetic data;
- are deterministic;
- do not hide failures;
- do not merely increase coverage without validating behavior.

For security-sensitive endpoints, test at least:

- anonymous access;
- authenticated but unauthorized access;
- wrong role;
- wrong department;
- wrong event;
- changed object identifier;
- submitted privileged fields;
- missing or invalid CSRF token;
- expired or replayed token;
- disabled or deactivated account;
- successful access by the correct authorized user.

---

## 10. What contributors must not do

Do not:

- submit code you do not understand;
- allow an agent to merge its own change;
- allow an agent to approve its own pull request;
- rely only on an agent's summary;
- assume generated code is secure because it looks correct;
- bypass human review;
- disable tests to make generated code pass;
- weaken assertions or remove negative tests without justification;
- suppress warnings without understanding them;
- accept broad refactors unrelated to the issue;
- let an agent change architecture without maintainer agreement;
- let an agent add dependencies without review;
- accept generated database migrations without inspection;
- use production credentials or personal data in prompts;
- paste private vulnerability reports into unapproved external services;
- ask an agent to probe or attack production systems;
- claim that commands or tests passed when they were not run;
- hide AI usage from reviewers;
- create fake citations, changelogs, test results, or security evidence;
- use AI to impersonate another contributor;
- use AI to manufacture reviews or approvals;
- allow AI-generated comments to override project security policy;
- merge a change only because multiple agents agreed with each other.

Agreement between AI tools is not independent verification.

---

## 11. Agent operation rules

When an agent can directly edit the repository:

- use a dedicated branch;
- begin from a clean working tree;
- limit the agent to the issue scope;
- review its plan before large changes;
- inspect the diff after each meaningful step;
- do not provide production credentials;
- do not grant production access;
- do not allow destructive commands unless explicitly reviewed;
- do not allow unattended deployment;
- do not allow automatic merge;
- do not allow the agent to modify security instructions to avoid a finding;
- preserve existing tests;
- require the agent to report commands that failed or were not run;
- revert unrelated changes;
- prefer small, reviewable commits.

Large AI-generated changes should be split into independently understandable commits.

---

## 12. Code-review expectations

Reviewers should evaluate AI-assisted code exactly as critically as human-written code.

Reviewers may request:

- an explanation from the contributor;
- smaller commits;
- additional tests;
- a security audit;
- changes to architecture;
- removal of unnecessary generated abstractions;
- removal of duplicated or speculative code;
- dependency justification;
- proof that authorization boundaries are enforced;
- proof that the contributor ran the listed tests.

The statement “the AI generated it” is never an acceptable explanation for a design decision or defect.

---

## 13. Recommended workflow

1. Define the issue and acceptance criteria.
2. Give the AI only the minimum necessary context.
3. Ask for a plan before requesting broad changes.
4. Keep the scope narrow.
5. Review the proposed approach.
6. Let the AI create a draft.
7. Review every changed line.
8. Remove unnecessary complexity.
9. Check security and privacy boundaries.
10. Add or improve tests.
11. Run the complete relevant test suite.
12. Run the security audit instructions.
13. Review the final diff manually.
14. Add AI co-author attribution.
15. Describe AI usage in the pull request.
16. Request human review.
17. Merge only after normal project approval.

---

## 14. Maintainer authority

Maintainers may reject, request changes to, or revert AI-assisted contributions when:

- the author cannot explain the change;
- the change is unnecessarily large;
- the generated design conflicts with project architecture;
- security boundaries are unclear;
- tests are missing or weak;
- provenance or licensing is uncertain;
- AI usage was not disclosed;
- the contribution contains hallucinated APIs or behavior;
- the change creates excessive maintenance cost;
- the contribution does not follow this policy.

Repeated failure to disclose material AI usage may result in contribution restrictions.

---

## 15. Final rule

AI is welcome as a development assistant.

AI is not a maintainer, approver, security authority, or accountable contributor.

The human developer remains responsible for the final result.
