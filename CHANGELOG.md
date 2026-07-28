# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) and sometimes
[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).

Critter 2.0 is pre-production. **`0.0.1-alpha` it is not yet run for a live event.**

## [Unreleased]

### Added

- feat(planner): lay out parallel shifts side by side, capped at four lanes per day (`5d378d1`)
- feat(planner): add a Paint/Select drag mode; Paint creates over existing shifts (`5d378d1`)
- feat(planner): outline the shifts a publish attempt rejected (`5d378d1`)
- feat(department): paginate and search the member tables per section (`72bee1f`)
- feat(users): replace eager user dropdowns with a type-ahead picker (`344622a`)
- feat(admin): add storage and backup connectivity diagnostics (`233fad4`, `56f8c7b`)
- feat(consent): let volunteers control, edit and withdraw contact visibility (`9fd2cd3`)
- feat(telegram): add one-tap account linking via deep link (`b46d07c`)
- feat(onboarding): make the Telegram step link accounts instead of only skipping (`f885230`)
- feat(shifts): add a Start duty tile to the Shift Manager landing (`b1cd634`)
- feat(shifts): record per-shift attendance (`checked_in_at` / `checked_out_at`) (`375db17`)
- feat(errors): add branded HTTP error pages inside the site layout (`9e4c708`)
- feat(i18n): make the whole browser UI translatable
- feat(i18n): add full German catalogs in informal du-form (`694b6c5`)
- feat(i18n): add a partial Klingon (`tlh`) locale; the route back to English stays untranslated
- feat(users): let admins queue an onboarding re-run, per user or for all (`534f268`)
- feat(bot-api): add the `/api/bot/*` surface for the Telegram bot (`375db17`)
- feat(bot-api): expose per-category Telegram notification consent (`375db17`)
- feat(notifications): add a Manager alert category, separate from the global call (`375db17`)

### Changed

- feat(planner)!: require a Shift Task before a shift can be published (`5d378d1`)
- feat(api)!: return UUID strings instead of integer ids from `/api/v0-beta` (`c46d2a4`)
- test: add Stimulus and browser test layers, and run the suite in CI (`1114b6f`)
- chore: rotate dev and test logs daily (`2d57325`)
- docs(api): document `/api/bot` and the visibility and identifier rules (`c46d2a4`)
- docs(deploy): document the `BOT_API_TOKEN` secret (`375db17`)

### Fixed

- fix(planner): refresh the draft counter and publish button after an edit (`5d378d1`)
- fix(planner): order department shifts deterministically so lanes stay stable (`5d378d1`)
- fix(department): stop the member tables capturing their own row links (`4242d65`)
- fix(matrix): staff any user from the cell editor via type-ahead search (`bf7d480`)
- fix(ui): stop `user_select` building a malformed double-`?` search URL (`bf7d480`)
- fix(sso): emit an https `redirect_uri` behind a TLS-terminating proxy (`821efe3`)

### Security

- fix(contact): resolve contact channels through consent, not `ROLE_STAFF` (`6bf37b2`)
- fix(pii): require a fresh 2FA step-up before unmasking personal data (`6bf37b2`)
- fix(users): match email exactly to close enumeration in management search (`6bf37b2`)
- fix(bot-api): revoke bot access on Telegram unlink via a rotating acting token (`e580baf`)
- fix(api): filter `/api/v0-beta` shifts by publication state and audience (`c46d2a4`)
- fix(api): expose public UUIDs instead of internal primary keys (`c46d2a4`)
- fix(rbac): scope task-less shifts by their own department (`375db17`)

## [0.0.1-alpha] - 2026-07-16

### Fixed

- **planner:** show a shift's saved required volunteer-type counts when its side panel is reopened; the counts were stored correctly but always rendered as 0 because the panel built its lookup with Twig's `merge` filter, which renumbers integer keys and dropped the volunteer-type id (`ca79d40`)
- **2fa:** show the one-time recovery codes after enabling two-factor authentication or regenerating them - the codes were issued but the non-redirect response was discarded by Turbo, so the user never saw them; both flows now redirect to a dedicated page (`a83691a`)
- **install:** accept the setup password when `INSTALL_PASSWORD` carries trailing whitespace (e.g. a secret file's newline), and distinguish an expired setup session from a wrong password (`530f1be`)

### Changed

- **privacy:** add a "Cookies" section to the shipped default privacy notice, documenting that only strictly-necessary session and CSRF cookies are used and that there is no tracking (`ccd3835`)
- **certifications:** render the volunteer-facing certifications list as a card grid instead of a table, reusing the new certification card (`ccd3835`)
- **2fa:** enlarge the one-time recovery codes and adjust their layout on the backup-codes page (`ccd3835`)
- **navigation:** remove the "Locate User" entry from the main navigation menu (`ccd3835`)
- **contact:** rename the DECT extension field to a general 32-char `phone` string on locations, user contacts and volunteer types (`a508f53`)

### Features

- **install:** add a privacy-notice step to the setup wizard - capturing the event name, data controller, privacy contact email and data-retention period - and pre-seed the English (`en_US`) onboarding consent text, which records the volunteer's agreement to share their personal data and states that no tracking cookies are used and that data is deleted after the event (`ccd3835`)
- **manage:** on the volunteer-type form, show certifications as a card grid with a toggle switch to require each one, and render the configuration flags as toggle switches; the same card now backs the volunteer-facing certifications list, and a new read-only certification detail page is linked from both. Adds reusable `forms.switch` and `data.certification_card` macros (`ccd3835`)
- **staffing:** replace the shift staffing search-and-select with a tag-style type-ahead user picker - search by username, pick several from a live dropdown (avatar and a "(staff)" suffix), and assign them all in one submit; built as a reusable `forms.user_select` macro + Stimulus controller (`4ea6943`)
- **security:** enforce the event-wide Access mode gate (public / staff only / admin only), which was previously stored but never applied; the mode is checked on every web and API request, so tightening it signs non-qualifying users out on their next request, and both form and SSO login redirect them to a "system restricted" notice. "Admin only" also admits sub-admins, admins can never lock themselves out, the API is gated too (except the `/info` endpoint), and the digital badge stays reachable so a gated volunteer can still identify themselves to security (`a31410c`)
- **manage:** add JSON export and file-or-paste import to departments and SSO group mappings, matching the locations tool; department exports round-trip their location and volunteer-type links (`11c609f`)
- **sso:** grant global admin / sub admin from two identity-provider role IDs on the SSO roles page; holding both resolves to global admin, and the grants are reconciled on every sign-in so a demotion at the provider takes effect on next login (`a8c7c54`)
- **backstage:** rework Distribute Goodies into the Info Desk "Locate user" tool - check-in, shift review, profile and contact shortcuts, a mining-resistant exact/badge-scan lookup behind a new `user:locate` privilege, and SSO-sourced registration numbers shown on the profile and Digital ID (`5071f94`)
- **locations:** add JSON export/import (paste or upload) with a unique alias as the upsert key and parent-by-alias resolution (`d45ec37`)
- **domain:** add the core domain model (users, shifts, volunteer types, memberships) with repositories (`2b9d4b3`)
- **security:** add local username/password login, privilege-based access control, and an API-key firewall (`ac883d2`)
- **install:** add the idempotent `app:install` seeding command (`0ecc732`)
- **ui:** add the login page, the protected dashboard, and an auth-aware navbar (`789a756`)
- **database:** add the initial schema migration (`484a5f7`)
- **configuration:** add entities and repositories for locations, volunteer types, shift types, departments and certifications (`901edfb`)
- **database:** add the migration for the configuration tables (`2674405`)
- **admin:** add the management area with Location administration and an `admin` super-privilege (`26a3940`)
- **admin:** add Volunteer Type administration (`1569ff1`)
- **admin:** add Shift Type administration (`48ebf04`)
- **admin:** add Department administration with location and volunteer-type links (`803a9be`)
- **admin:** add Certification administration (`7734456`)
- **admin:** add event configuration settings (name, dates, options) (`54e62f2`)
- **config:** add a typed date accessor for stored event dates (`271f87f`)
- **ui:** add a public landing page with the event timeline and a single-sign-on placeholder (`e72a4c6`)
- **cli:** add `app:user:password` to set or reset a user's password (`fbdd1dc`)
- **shifts:** add the shift, sign-up, staffing and worklog domain model (`7672666`)
- **database:** add the migration for the shift tables (`e43ca28`)
- **shifts:** add worked-hours calculation and the sign-up service (`381c40a`)
- **shifts:** add shift administration (create, edit, delete) (`5240014`)
- **shifts:** add per-shift staffing management and no-show handling (`6a2929c`)
- **memberships:** let volunteers join and leave volunteer types, with supporter management (`d81ac06`)
- **shifts:** add shift browsing, sign-up and cancellation, plus a "my shifts" view with hours (`5cbf3b1`)
- **worklog:** let administrators record manual hours (`405201c`)
- **goodies:** add the goodies and cached-hours domain model (`158e86f`)
- **database:** add the migration for the goodies and hours-cache tables (`252312d`)
- **goodies:** add hours caching and goodie eligibility evaluation (`e8f9163`)
- **backstage:** add the backstage dashboard and goodie category administration (`684e8b4`)
- **backstage:** add goodie item administration (`05a1a32`)
- **backstage:** add the goodie distribution workflow (`b2b12f2`)
- **shifts:** add a filterable shift application screen (`2f4e1e5`)
- **shifts:** let managers assign and unassign volunteers from the staffing page (`07653b8`)
- **shifts:** add the manager applications inbox (`b1d8d55`)
- **communications:** add the news, messaging, question and FAQ domain model (`ba6e26f`)
- **database:** add the migration for the communications tables (`fec20dd`)
- **news:** add news administration plus the public feed, detail page and comments (`77ea955`)
- **messaging:** add private one-to-one messaging with an inbox and read tracking (`3687155`)
- **faq:** add questions and the public FAQ (`10813e9`)
- **staff:** add the duty-record and internal-note domain model (`8f4ba93`)
- **database:** add the migration for the staff tables (`ca635d6`)
- **staff:** add duty tracking and staff statistics services (`ba197f1`)
- **staff:** add the operational suite - overview, live view, statistics, team and notes (`359617e`)
- **api:** add the API key management page (`2c8d43d`)
- **api:** add the public read-only API (`db85f0b`)
- **api:** add Atom, RSS, iCal and JSON feeds (`ea459b7`)
- **qr:** add a central QR code generator service (`5d80036`)
- **certifications:** add the user certification, digital-ID and certification-token model (`2fb0e89`)
- **certifications:** add the digital-ID and certification services (`8f66969`)
- **digital-id:** add a scannable personal QR code with a public verification page (`1a14a0c`)
- **certifications:** add apply, self-confirm, administrator QR and login-required check-in flows (`b0d3abd`)
- **theme:** add a theme catalog, resolver and three starter themes (`9d399c6`)
- **theme:** store the selected theme as a nullable slug on user settings (`fa1aac0`)
- **theme:** add the user theme panel, an administrator default, and a theme development kit (`7dbb353`)
- **ui:** move the interface from Bootstrap to the Tabler 1.4 design system (`446bef2`)
- **theme:** add the Eurofurence brand theme in light and dark variants (`a459873`)
- **theme:** add the official event background image to the Eurofurence themes (`51e9098`)
- **config:** store all timestamps in UTC and add a site-wide timezone and date-format configuration page (`92c451f`)
- **deploy:** add the installation wizard, self-healing migrations, a maintenance gate, and container/Kubernetes deployment (`75ff1e0`)
- **security:** encrypt stored secrets, add development single-sign-on and mail services, and gate the installer (`2c573bb`)
- **rbac:** add fine-grained `domain:action` privileges, scoped and time-boxed groups, and sub-administrators (`0caaaed`)
- **audit:** add a forensic audit log with a signing certificate and a legally usable export (`5650726`)
- **onboarding:** add the onboarding wizard, consent tracking, a privacy notice and rich-text editing (`bd33180`)
- **users:** add user management, invitations, badges and personal-data masking (`9239b4b`)
- **gdpr:** add data portability export and the right to be forgotten (`fb55e31`)
- **sso:** add OIDC single sign-on with group mapping and Keycloak provisioning (`9ed9a99`)
- **2fa:** add TOTP two-factor authentication with step-up for sensitive actions (`69392f8`)
- **telegram:** add Telegram account linking with a stand-in bot (`193de9b`)
- **operations:** add an operational status view (`018b34d`)
- **notifications:** add the in-app notification centre and a per-channel preference matrix (`ac7b427`)
- **profile:** add a unified profile page and header (`bb14609`)
- **profile:** show work history, memberships and goodies on the profile (`0e5280c`)
- **account:** add the account settings page (`b8e06ac`)
- **worklog:** let staff self-report worked hours (`4427b49`)
- **bans:** ban volunteers automatically after repeated no-shows (`2f2efdc`)
- **departments:** add the department model, an organizational flag, and require a department on every shift (`188c4aa`)
- **departments:** add the staff departments page and dashboard (`b795a45`)
- **departments:** add the delegated shift-manager workflow, member actions and contact details (`cee44d4`)
- **locations:** add a location hierarchy with visibility rules (`f0e7b4f`)
- **locations:** embed location maps behind a `frame-src` allowlist (`4b44d28`)
- **volunteer-types:** add flags, contact people and required certifications (`ae533bc`)
- **volunteer-types:** add public job-posting pages (`a8c981e`)
- **shifts:** add audience modes and a publication state (`e9958fa`)
- **shifts:** add position groups and named positions (`1feea45`)
- **shifts:** deduplicate worked-hour calculation across overlapping records (`e1f2f6d`)
- **shifts:** add check-in rules that vary by event phase (buildup, main event, teardown) (`3bf885c`)
- **shifts:** protect sign-up and assignment against concurrent updates (`4e1070f`)
- **shifts:** restructure the shift routes around volunteer, staff and manager roles (`f4a3b5c`)
- **planner:** add the standard planner grid with drag-and-paint editing (`04b2d51`)
- **planner:** add the planner side panel, toolbar and add-shift modal (`ac6496d`)
- **planner:** add the guided shift creation wizard (`9113c52`)
- **planner:** add the draft and publication lifecycle for planned shifts (`13fc100`)
- **planner:** add the advanced matrix planner (`6bd22ab`)
- **planner:** allow matrix-planner editing and copying an existing structure (`9ab4a28`)
- **availability:** let volunteers declare when they are available (`caa80a2`)
- **availability:** use declared availability when planning and assigning (`3944a61`)
- **availability:** add availability requests and shareable shift invitation links (`4c70611`)
- **assignment:** let staff apply for shifts (`0c974f8`)
- **assignment:** add manual assignment and manager overrides (`0058d26`)
- **assignment:** add automatic assignment proposals (`7460e81`)
- **assignment:** warn when a volunteer crosses the event-hours threshold (`093cfd8`)
- **departments:** add the department shift management grid (`72b1e86`)
- **staff:** add the schedule timeline with PDF export (`b232dbe`)
- **staff:** add the staffing matrix with PDF export (`1a2d213`)
- **chat:** add the conversation model (`7af8f06`)
- **chat:** add the info desk queue with conversation claiming (`366919f`)
- **chat:** add the chat interface with near-real-time polling (`ae09a15`)
- **chat:** allow message editing and restrict sensitive content (`a80992c`)
- **chat:** let volunteers contact their shift contacts directly (`cd04158`)
- **calls:** add the global call for help (`2e94350`)
- **bounty-board:** add the bounty board for open, unstaffed work (`ff7d8c6`)
- **sso:** manage identity-provider group mappings from the administration UI (`6af4575`)
- **accounts:** add department import, two-factor recovery codes, and step-up on administrative access (`d0412c9`)
- **ui:** replace native browser confirmation dialogs with in-app modals (`24de264`)
- **security:** use public UUIDs in URLs instead of internal database identifiers (`83d1976`)
- **ui:** add the component gallery and developer reference for the shared interface macros (`902df38`)
- **departments:** derive a member's position from their identity-provider roles, let admins and sub-admins staff a department by hand, and require step-up on every screen that shows identity-provider IDs (`f3350da`)

### Bug Fixes

- **security:** drop the unused remember-me badge from the login authenticator (`17dbbf5`)
- **deploy:** reset the install password default to empty (`a36fd10`)
- **config:** normalise stored event dates to UTC so the configuration form saves, and hide the main menu from guests (`49a13bb`)
- **onboarding:** auto-confirm the default volunteer type and grant the Volunteer group so new accounts are usable straight after login (`bf4bac8`)
- **sso:** confirm volunteer types that are granted through a group mapping (`85c24ca`)
- **digital-id:** stop the QR page pulling the user back after they navigate away (`f966941`)
- **rbac:** let volunteers see Volunteer Types and Locations (`c1fea6a`)
- **navigation:** hide the Volunteer Types and Locations menu entries from users without permission (`b918f56`)
- **planner:** send the department identifier, not `NaN`, when painting shifts (`fde9587`)
- **security:** keep the interface development kits out of production and behind administrator access (`fca068d`)
- **audit:** write audit events reliably by running the messaging worker in every deployment, and alarm when it stalls (`7f3ece5`)
- **exports:** store audit and GDPR export archives where every process can reach them - the worker built them on a disk the web container could not see, so a data export could never be downloaded - and delete them once their retention window closes (`40c2ab2`)
- **security:** sign the user out cleanly when their session expires, instead of loading the login page inside the navbar and leaving their personal data on screen; return them to the page they were on, and make the inactivity limit admin-configurable (`533e280`)
- **audit:** repair the audit log page, which still asked the export record whether its archive was on disk after the archives moved to pluggable storage (`1142fd4`)
- **operations:** give the ban screen and the Info Desk greeting and closing messages real defaults, so a fresh install shows a sentence where one is expected instead of nothing (`684bbc9`)
- **security:** sign users out after 60 minutes of inactivity, the interval the code already claimed - the form model's value was overwritten on load, so the effective default was 30 (`f8a18af`)
- **theme:** apply a chosen theme immediately instead of leaving the previous one in place until the next full page load (`8decc18`)
- **observability:** log the failures the application was discarding - an unreachable database, an unusable timezone, a failed identity-provider lookup, a two-factor gate that fails open - and stop showing the identity provider's raw error text on the login page (`049a8fa`)
- **matrix:** staff positions from the grid, and refresh it without a reload (`1e96df9`)
- **shift-tasks:** let department managers own their tasks, and create them while planning (`8f0d3ab`)
- **planner:** keep the grid where the manager left it when a shift is painted, freeze the hour column while scrolling, and label setup and teardown days above the date (`e454a99`)

### Refactoring

- **ui:** restructure the navbar and move the audit log into the management area (`cdf4099`)
- **shifts:** rename "Shift Type" to "Shift Task" across routes, templates and labels (`7dc6461`)
- **ui:** repair the unusable shared macros and remove the misleading ones (`224d6b0`)
- **ui:** move templates onto the shared macros (`236e4f5`)
- **ui:** move the shift and news index pages onto the shared macros (`0ed5b28`)

### Tests

- **core:** cover the foundation model, the security gate, and the database-backed flows (`2fb3554`)
- **admin:** cover management-area access control and the event configuration store (`61152d3`)
- **ui:** cover the landing page and the password command (`109c56b`)
- **shifts:** cover hours calculation and the sign-up service (`fd9a5a1`)
- **goodies:** cover the hours cache and goodie eligibility (`1c5ab63`)
- **shifts:** cover eligibility status and sign-up options (`1517a75`)
- **communications:** cover notification email rules and the communications repositories (`192047a`)
- **staff:** cover duty-record duration and duty start/end/hours (`0e839be`)
- **api:** cover API key authentication and access to the public API and feeds (`b0b6f08`)
- **certifications:** cover the QR generator, the certification service and scan authorisation (`010f4d7`)
- **shifts:** update the unit tests for the changed hours and route signatures (`9a57477`)
- **suite:** truncate tables between tests instead of rebuilding the schema, cutting the run time sharply (`2a5f2ba`)
- **shifts:** cover shift browsing, sign-up and cancellation (`fe6f1bd`)

### Documentation

- **project:** add in-repo contributor guidance (stack, commands, conventions) (`2c87a05`)
- **project:** expand the contributor guidance with the interface, theming and dialog rules (`72ef7f8`)
- **sso:** document Keycloak group mapping and fix the development provisioning script (`bc0852a`)
- **project:** rewrite the README and align the licence metadata with the MIT licence (`5a3cfc6`)
- **project:** add this changelog, covering the project history (`b96b46a`)

### Build & CI

- **docker:** fix the development container environment (`ba26385`)
- **docker:** run the development containers as the host user, so files they write into the bind-mounted `var/` are not owned by a user the host cannot write to (`7524f90`)

### Chores

- **project:** initial Symfony application skeleton (`3a4728c`)
- **cleanup:** tidy code comments, docblocks and the importmap (`186b3db`)
- **platform:** add PDF rendering, pluggable file storage, reusable polling refresh, operational configuration keys and default seed data (`db30084`)
- **ui:** move the fluid-layout class from the body onto the navbar headers (`5178769`)
- **cleanup:** remove implementation-history and dangling-reference comments (`f539efc`)
- **comments:** restate the comments that narrated what the code used to do as the rules it now follows (`860139a`)
