# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) and entries are written
as [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).

Critter 2.0 is production. **`1.1.0`**

## [Unreleased]

### Added

- feat(manage): `/manage/volunteer-types/repair` finds the users who finished onboarding without a
  Critter type and gives it to one of them, to a selection, or to all of them, recording each. It
  leads with the cause rather than the symptom: onboarding matches the base type on its role, a type
  only carries a role if somebody gave it one, and while no type claims `volunteer` the repair
  assigns nobody anything, so the screen offers to hand the role to an existing type first
- feat(manage): the Critter type form exposes the system role, so a base type that never had one is
  recoverable without editing the database by hand. Only one type may hold each role, and the form
  says so instead of failing on the database constraint
- feat(demo): `bin/demo-instance` builds and serves an instance of fictional data on a database and
  port of its own, so the development instance keeps running untouched; `app:demo:seed` fills it and
  refuses to run where the data could be real
- feat(demo): `bin/demo-screenshots.php` and `bin/build-slides.php` build an introduction slide deck
  from that instance; it carries everything it needs, so it opens from an e-mail attachment with no
  network at all. The deck itself is built into the ignored `docs/slides/`, not committed
- feat(ui): a favicon and a navbar logo of the app's own, in place of the Symfony skeleton's
  placeholder

### Changed

- chore(templates): comments across all 271 Twig templates sit above what they explain and say why
  rather than what, and commented-out markup is gone. Three that no longer matched the code were
  corrected, including one telling the reader the board needs `importmap('app')` when the file
  renders `importmap('board')`, which is the single change that would break the board's isolation
- perf(tests): the suite runs in ~1 minute against ~19 before, and the per-test database reset costs
  9ms against 1.5s; it needs `docker compose -f compose.dev.yaml up -d test-database`
- test: `bin/ptest` runs the suite in parallel, one database per worker
- feat(shifts): the per-shift check-in override is relabelled "Require check-in (Setup/Teardown)"
  and shown read-only, in the shift form and in both planner panels. It forces the event check-in
  requirement on a shift outside the main event, which requires it anyway, so the switch only ever
  changed a setup or teardown shift
- docs(testing): the three test layers, what each one catches, and what the browser suite's
  flakiness is not

### Fixed

- fix(board): opening the shifts view no longer raises the call-for-help confirmation once for every
  shift on the page before anyone has pressed anything. Twig prints a false boolean as the empty
  string and Stimulus reads an empty value as true, so each closed dialog told its controller it was
  open and showed itself as soon as it connected
- fix(certifications): the scanning QR rotates from a live region instead of a
  `<meta http-equiv="refresh">`. Turbo swaps the body and never loads a document, so the refresh
  stayed scheduled against the page that set it and pulled the user back there long after they had
  moved on. A token already inside the rotation margin is reissued rather than shown, so the region
  is never handed a moment that has passed
- fix(staff): the live duty board names the operational departments nobody is covering again. It had
  been switched off on both sides by accident. Organizational departments stay out of the warning,
  since nobody is ever on duty in one, and it appears only beside the grid, because with nobody on
  duty at all the empty state already says so
- fix(ui): a toast asked not to hide now reaches Bootstrap as a boolean it accepts. Twig bound the
  `'true'`/`'false'` pair to the default rather than to the option, so an explicit setting rendered
  as an empty attribute or a bare `1` and the toast failed to build
- fix(planner): the toolbar offers the matrix view again. The link had been left commented out,
  which made the view reachable only by typing its URL
- fix(shifts): every screen naming a shift leads with its title and follows with its task, the way
  the browse list already did. The planner, the staff apply grid and the staff schedule showed the
  task alone, so a department running one task all week drew a column of blocks that all read the
  same word. My Shifts, the bounty board, the department grid, the staff stats table, the backstage
  lookup and the assignment proposal gained the task line they never had
- fix(shifts): the planner and staff apply grids leave a half-hour shift room for its time and its
  title; both were short enough to clip the title away entirely
- fix(planner): saving a shift in the side panel keeps its check-in override. The control is
  read-only, so the browser submits nothing for it, and the missing field was read as "off": any
  edit at all switched off an override the panel was showing as ticked
- fix(schedule): a staff schedule cell names its location by full path, so it says which building
  "Hall 5" is in
- fix(ui): the scrollbar gutter no longer opens an empty strip down the left of any page long enough
  to scroll, and the page no longer jumps sideways when a dialog opens
- fix(onboarding)!: a user finishing onboarding is given the base volunteer type even when the event
  has renamed it; renaming it stopped the assignment silently. Run `doctrine:migrations:migrate`:
  one additive migration pins the base types and assigns the type to users who finished without one
- fix(rbac): every seeded staff group holds `news:view`, so staff are not met with a 403 on the page
  sign-in sends them to; a migration grants it to existing databases
- fix(onboarding): the baseline permission group is granted to staff as well, matching what SSO
  provisioning has always done
- fix(sso): a permission group a mapping names but the installation does not have is logged instead
  of skipped in silence
- test(security): the access-control gate test built no schema and passed only when another test had
  already built one

### Security

- fix(deps): guzzlehttp/guzzle 7.15.1 to 7.15.3, closing CVE-2026-69246 (a noncanonical host bypasses
  host-based checks) and CVE-2026-69245 (a noncanonical cookie domain keeps subdomain scope)
- fix(security): the login form generates its stateless CSRF token instead of posting the sentinel,
  so a session that has submitted any other form can still sign in

## [1.1.0] - 2026-08-10

Run `doctrine:migrations:migrate`: three additive migrations. One grants `manageshifts:view` to every
staff group, so the staff shift screen matches the banner offering it. No goodie gains a
certification requirement until an administrator attaches one.

### Added

- feat(live): a Mercure transport; the browser cannot widen the topic set signed into its token (`21fa882`)
- feat(live): the notification bell and status widget refresh on a signal instead of polling (`0418376`)
- feat(live): chat and typing (`efff9cd`), the bounty board and Info Desk queue (`298fd12`), the digital ID card (`f2ebf34`)
- feat(live): the planner and matrix are announced to, so a remote change never lands mid-drag (`3134aca`)
- feat(shifts): shift groups - applying to one member signs the volunteer up for all of them (`2f3c1f5`)
- feat(certifications): applicant and holder lists (`70aa316`), approve, decline and revoke (`a11536e`)
- feat(certifications): grant one by hand for a paper certificate (`37c9d83`), and decide in bulk (`9089874`)
- feat(certifications): a holders export, a compliance report and overview counts (`0cce2dc`)
- feat(certifications): warn before one lapses, and let volunteers renew or withdraw (`a6d7529`)
- feat(shift): the optional description reaches the planner, the API, the JSON export and iCal (`12a8f02`)
- feat(chat): a command to purge the empty support conversations the defect below left behind (`9cecb76`)
- feat(shifts): the staff apply screen as one day, a column per department against a shared axis (`0ee1c73`)
- feat(planner): select a range by dragging across empty grid, or the focused block with Enter (`af92d66`)
- feat(planner): scroll arrows on the day headers, so a grid wider than the screen looks scrollable (`3da6df6`)
- feat(rbac): a permission matrix at `/manage/groups/matrix`, every group against every permission (`5710a90`, `b0fc254`)
- feat(bot): carry `my_state_reason`, so a volunteer reads the obstacle instead of "ineligible" (`16750d6`)
- feat(volunteer-types): add volunteers to a critter type from the manage screens, several at once (`2abc271`)
- feat(ui): vendor the Tabler icon webfont through importmap (`2abc271`)
- feat(dev): `app:db:import-prod` replaces a development database with a sanitised production dump
- feat(shifts): browse cards redesigned; every action moves into one shift dialog (`c8f1d5d`)
- feat(shifts): Atom, iCal and RSS subscription links on My Shifts (`4f08ecd`)
- feat(checkin): an admin-set check-in message per language, shown to whoever has not checked in (`acf7686`)
- feat(shifts): a banner points staff at their own apply screen (`65af589`)
- feat(profile): the profile lists the departments a volunteer belongs to (`fa271d1`)
- feat(onboarding): travel dates, a theme step, and staff finishing on availability (`46376d6`)
- feat(goodies): a goodie item can require certifications (`7fd5bb8`)

### Changed

- feat(shifts)!: a role requiring a certification is closed to volunteers who do not hold one (`a04e422`)
- feat(shifts)!: only volunteers are held to one shift at a time; staff may work parallel shifts (`5033508`)
- feat(shifts)!: staff are checked in when they finish onboarding, so the gate stops refusing them (`875b482`)
- feat(planner)!: drop the four-lane cap; every parallel shift renders and the day column widens (`3da6df6`)
- feat(volunteer-types)!: base types stay global, out of any single department's reach (`6228735`, `e458a15`)
- feat(locations)!: a location is named by its full path, in the UI, the iCal feed and the bot (`d677277`)
- feat(goodies)!: a goodie is refused to somebody missing a certification it requires (`933f76b`)
- feat(rbac): every staff group can reach the staff shift screen (`65af589`)
- perf(shifts): the apply screen cost 285 queries for 60 shifts and now costs 43 (`0ee1c73`)
- perf(live): one region per department on a signal, replacing a Turbo Frame per row on a timer (`62938b1`)
- docs(api): document `my_state_reason`, the `my_state` precedence and the overlap policy (`16750d6`)
- docs: correct the project guide to what is installed, and state the comment rule (`3aedb7d`, `09f6f87`)
- style: explanations move above the functions they explain (`cb8f054`)
- chore(deploy): more nginx buffer headroom, so large response headers are served instead of 502 (`2abc271`)

### Fixed

- fix(live): SSO sign-in returned 502 for staff; their subscriber token outgrew the header buffer
- fix(live): admins lost the bell, the status widget and the hub URL, because live regions were gated on onboarding (`e823a1c`)
- fix(chat): opening `/messages` contacted the Info Desk, queueing a conversation per visitor (`88b6f1e`)
- fix(availability): painting two consecutive whole days lost the second on reload (`779e449`)
- fix: read optional numeric request values with a cast; a blank field answered 400 (`02e7629`)
- fix(certifications): a grant through the user picker answered 400, because it submits `user[]` (`9089874`)
- fix(planner): a click selects a shift instead of nudging it; any movement counted as a drag (`af92d66`)
- fix(shifts): overlap is reported before availability, so no button is offered the write refuses (`5033508`)
- fix(shifts): a shift that no longer exists renders a page saying so instead of an error (`4e4c8d6`)
- fix(profile): adding a worklog from your own profile was refused; the CSRF id was wrong (`9da0f76`)
- fix(ui): the checkboxes in a multi-select combobox were clipped outside the scrolling menu (`b0fc254`)
- fix(telegram): the link-status poll outlived its page, so Turbo left it running from elsewhere (`8ae4d5d`)
- fix(manage): clearing a text field on the operations screen answered 500 instead of saving (`65af589`)

## [1.0.2] - 2026-07-29

### Added

- feat(auth): lead the login page with SSO and collapse the password form behind a link
- feat(auth): let an administrator switch password sign-in off; `ROLE_ADMIN` always keeps it
- feat(planner): confirm the department before the grid, publish bar and side panel load
- feat(planner): create a shift task inline from the modal, the wizard and the edit panel
- feat(planner): set or replace the shift task across a batch selection
- feat(planner): delete a batch selection, or all of a department's draft shifts at once
- feat(planner): lay out parallel shifts side by side, capped at four lanes per day (`5d378d1`)
- feat(planner): a Paint/Select drag mode; Paint creates over existing shifts (`5d378d1`)
- feat(planner): outline the shifts a publish attempt rejected (`5d378d1`)
- feat(volunteer-types): rank types by sort order, so Staff and Volunteer head every picker
- feat(department): paginate and search the member tables per section (`72bee1f`)
- feat(users): replace eager user dropdowns with a type-ahead picker (`344622a`)
- feat(admin): storage and backup connectivity diagnostics (`233fad4`, `56f8c7b`)
- feat(consent): let volunteers control, edit and withdraw contact visibility (`9fd2cd3`)
- feat(telegram): one-tap account linking via deep link (`b46d07c`)
- feat(onboarding): make the Telegram step link accounts instead of only skipping (`f885230`)
- feat(shifts): a Start duty tile on the Shift Manager landing (`b1cd634`)
- feat(shifts): record per-shift attendance as `checked_in_at` and `checked_out_at` (`375db17`)
- feat(errors): branded HTTP error pages inside the site layout (`9e4c708`)
- feat(i18n): make the whole browser UI translatable
- feat(i18n): full German catalogs in informal du-form (`694b6c5`)
- feat(i18n): a partial Klingon (`tlh`) locale; the route back to English stays untranslated
- feat(users): let admins queue an onboarding re-run, per user or for all (`534f268`)
- feat(bot-api): the `/api/bot/*` surface for the Telegram bot (`375db17`)
- feat(bot-api): expose per-category Telegram notification consent (`375db17`)
- feat(notifications): a Manager alert category, separate from the global call (`375db17`)

### Changed

- feat(planner)!: require a shift task wherever a shift is created or saved, not only to publish
- feat(planner)!: offer only the critter types a department may staff with
- feat(planner)!: require a shift task before a shift can be published (`5d378d1`)
- feat(api)!: return UUID strings instead of integer ids from `/api/v0-beta` (`c46d2a4`)
- fix(planner): stack the Starts and Ends fields so the whole date and time are legible
- fix(planner): keep a grid block's times readable rather than clipped behind the draft badge
- test: add the Stimulus and browser test layers, and run the suite in CI (`1114b6f`)
- test: point the redirect assertions at the news page, which replaced the dashboard in `7c30bd1` (`ca35e24`)
- chore: rotate dev and test logs daily (`2d57325`)
- docs(api): document `/api/bot` and the visibility and identifier rules (`c46d2a4`)
- docs(deploy): document the `BOT_API_TOKEN` secret (`375db17`)

### Fixed

- fix(sso): a pre-seeded or SSO user was refused after onboarding; staff groups are not supersets of the baseline (`ca35e24`)
- fix(planner): batch-edit selections read as integers, making every batch action a silent no-op
- fix(planner): seed the paint defaults from the toolbar rather than the controller's own
- fix(planner): clear the side panel selection after a grid reload
- fix(planner): refresh the draft counter and publish button after an edit (`5d378d1`)
- fix(planner): order department shifts deterministically so lanes stay stable (`5d378d1`)
- fix(department): stop the member tables capturing their own row links (`4242d65`)
- fix(matrix): staff any user from the cell editor via type-ahead search (`bf7d480`)
- fix(ui): stop `user_select` building a malformed double-`?` search URL (`bf7d480`)
- fix(sso): emit an https `redirect_uri` behind a TLS-terminating proxy (`821efe3`)
- fix(audit): cap audited identifiers at their column widths; an over-long username answered 500

### Security

- feat(auth): throttle password sign-in. An address locks on its own; an account only once failures come from several
- feat(auth): list and lift active lockouts on `/manage/login-lockouts`, behind `security:lockout:manage`
- fix(contact): resolve contact channels through consent, not `ROLE_STAFF` (`6bf37b2`)
- fix(pii): require a fresh 2FA step-up before unmasking personal data (`6bf37b2`)
- fix(users): match email exactly to close enumeration in management search (`6bf37b2`)
- fix(bot-api): revoke bot access on Telegram unlink via a rotating acting token (`e580baf`)
- fix(api): filter `/api/v0-beta` shifts by publication state and audience (`c46d2a4`)
- fix(api): expose public UUIDs instead of internal primary keys (`c46d2a4`)
- fix(rbac): scope task-less shifts by their own department (`375db17`)

## [0.0.1-alpha] - 2026-07-16

### Added

- feat(install): a privacy-notice step in the setup wizard, and a seeded English consent text (`ccd3835`)
- feat(manage): certifications as toggleable cards on the volunteer-type form, plus a detail page (`ccd3835`)
- feat(staffing): a type-ahead user picker that assigns several volunteers in one submit (`4ea6943`)
- feat(security): enforce the event-wide access mode, which was stored but never applied. Admins cannot lock themselves out and the digital badge stays reachable (`a31410c`)
- feat(manage): JSON export and import for departments and SSO group mappings (`11c609f`)
- feat(sso): grant admin and sub-admin from provider role IDs, reconciled on every sign-in (`a8c7c54`)
- feat(backstage): the Info Desk "Locate user" tool, behind `user:locate`, with exact-match lookup (`5071f94`)
- feat(locations): JSON export and import, keyed on the alias (`d45ec37`)
- feat(domain): the core domain model and its repositories (`2b9d4b3`)
- feat(security): password login, privilege-based access control and an API-key firewall (`ac883d2`)
- feat(install): add the idempotent `app:install` seeding command (`0ecc732`)
- feat(ui): add the login page, the protected dashboard, and an auth-aware navbar (`789a756`)
- feat(database): add the initial schema migration (`484a5f7`)
- feat(configuration): entities and repositories for the configuration domain (`901edfb`)
- feat(database): add the migration for the configuration tables (`2674405`)
- feat(admin): add the management area with Location administration and an `admin` super-privilege (`26a3940`)
- feat(admin): add Volunteer Type administration (`1569ff1`)
- feat(admin): add Shift Type administration (`48ebf04`)
- feat(admin): add Department administration with location and volunteer-type links (`803a9be`)
- feat(admin): add Certification administration (`7734456`)
- feat(admin): add event configuration settings (name, dates, options) (`54e62f2`)
- feat(config): add a typed date accessor for stored event dates (`271f87f`)
- feat(ui): add a public landing page with the event timeline and a single-sign-on placeholder (`e72a4c6`)
- feat(cli): add `app:user:password` to set or reset a user's password (`fbdd1dc`)
- feat(shifts): add the shift, sign-up, staffing and worklog domain model (`7672666`)
- feat(database): add the migration for the shift tables (`e43ca28`)
- feat(shifts): add worked-hours calculation and the sign-up service (`381c40a`)
- feat(shifts): add shift administration (create, edit, delete) (`5240014`)
- feat(shifts): add per-shift staffing management and no-show handling (`6a2929c`)
- feat(memberships): let volunteers join and leave volunteer types, with supporter management (`d81ac06`)
- feat(shifts): add shift browsing, sign-up and cancellation, plus a "my shifts" view with hours (`5cbf3b1`)
- feat(worklog): let administrators record manual hours (`405201c`)
- feat(goodies): add the goodies and cached-hours domain model (`158e86f`)
- feat(database): add the migration for the goodies and hours-cache tables (`252312d`)
- feat(goodies): add hours caching and goodie eligibility evaluation (`e8f9163`)
- feat(backstage): add the backstage dashboard and goodie category administration (`684e8b4`)
- feat(backstage): add goodie item administration (`05a1a32`)
- feat(backstage): add the goodie distribution workflow (`b2b12f2`)
- feat(shifts): add a filterable shift application screen (`2f4e1e5`)
- feat(shifts): let managers assign and unassign volunteers from the staffing page (`07653b8`)
- feat(shifts): add the manager applications inbox (`b1d8d55`)
- feat(communications): add the news, messaging, question and FAQ domain model (`ba6e26f`)
- feat(database): add the migration for the communications tables (`fec20dd`)
- feat(news): add news administration plus the public feed, detail page and comments (`77ea955`)
- feat(messaging): add private one-to-one messaging with an inbox and read tracking (`3687155`)
- feat(faq): add questions and the public FAQ (`10813e9`)
- feat(staff): add the duty-record and internal-note domain model (`8f4ba93`)
- feat(database): add the migration for the staff tables (`ca635d6`)
- feat(staff): add duty tracking and staff statistics services (`ba197f1`)
- feat(staff): add the operational suite - overview, live view, statistics, team and notes (`359617e`)
- feat(api): add the API key management page (`2c8d43d`)
- feat(api): add the public read-only API (`db85f0b`)
- feat(api): add Atom, RSS, iCal and JSON feeds (`ea459b7`)
- feat(qr): add a central QR code generator service (`5d80036`)
- feat(certifications): add the user certification, digital-ID and certification-token model (`2fb0e89`)
- feat(certifications): add the digital-ID and certification services (`8f66969`)
- feat(digital-id): add a scannable personal QR code with a public verification page (`1a14a0c`)
- feat(certifications): add apply, self-confirm, administrator QR and login-required check-in flows (`b0d3abd`)
- feat(theme): add a theme catalog, resolver and three starter themes (`9d399c6`)
- feat(theme): store the selected theme as a nullable slug on user settings (`fa1aac0`)
- feat(theme): add the user theme panel, an administrator default, and a theme development kit (`7dbb353`)
- feat(ui): move the interface from Bootstrap to the Tabler 1.4 design system (`446bef2`)
- feat(theme): add the Eurofurence brand theme in light and dark variants (`a459873`)
- feat(theme): add the official event background image to the Eurofurence themes (`51e9098`)
- feat(config): store every timestamp in UTC, with a site-wide timezone and format page (`92c451f`)
- feat(deploy): the install wizard, self-healing migrations, a maintenance gate and Kubernetes (`75ff1e0`)
- feat(security): encrypt stored secrets, and gate the installer (`2c573bb`)
- feat(rbac): `domain:action` privileges, scoped time-boxed groups and sub-administrators (`0caaaed`)
- feat(audit): add a forensic audit log with a signing certificate and a legally usable export (`5650726`)
- feat(onboarding): add the onboarding wizard, consent tracking, a privacy notice and rich-text editing (`bd33180`)
- feat(users): add user management, invitations, badges and personal-data masking (`9239b4b`)
- feat(gdpr): add data portability export and the right to be forgotten (`fb55e31`)
- feat(sso): add OIDC single sign-on with group mapping and Keycloak provisioning (`9ed9a99`)
- feat(2fa): add TOTP two-factor authentication with step-up for sensitive actions (`69392f8`)
- feat(telegram): add Telegram account linking with a stand-in bot (`193de9b`)
- feat(operations): add an operational status view (`018b34d`)
- feat(notifications): add the in-app notification centre and a per-channel preference matrix (`ac7b427`)
- feat(profile): add a unified profile page and header (`bb14609`)
- feat(profile): show work history, memberships and goodies on the profile (`0e5280c`)
- feat(account): add the account settings page (`b8e06ac`)
- feat(worklog): let staff self-report worked hours (`4427b49`)
- feat(bans): ban volunteers automatically after repeated no-shows (`2f2efdc`)
- feat(departments): the department model, and a department required on every shift (`188c4aa`)
- feat(departments): add the staff departments page and dashboard (`b795a45`)
- feat(departments): add the delegated shift-manager workflow, member actions and contact details (`cee44d4`)
- feat(locations): add a location hierarchy with visibility rules (`f0e7b4f`)
- feat(locations): embed location maps behind a `frame-src` allowlist (`4b44d28`)
- feat(volunteer-types): add flags, contact people and required certifications (`ae533bc`)
- feat(volunteer-types): add public job-posting pages (`a8c981e`)
- feat(shifts): add audience modes and a publication state (`e9958fa`)
- feat(shifts): add position groups and named positions (`1feea45`)
- feat(shifts): deduplicate worked-hour calculation across overlapping records (`e1f2f6d`)
- feat(shifts): add check-in rules that vary by event phase (buildup, main event, teardown) (`3bf885c`)
- feat(shifts): protect sign-up and assignment against concurrent updates (`4e1070f`)
- feat(shifts): restructure the shift routes around volunteer, staff and manager roles (`f4a3b5c`)
- feat(planner): add the standard planner grid with drag-and-paint editing (`04b2d51`)
- feat(planner): add the planner side panel, toolbar and add-shift modal (`ac6496d`)
- feat(planner): add the guided shift creation wizard (`9113c52`)
- feat(planner): add the draft and publication lifecycle for planned shifts (`13fc100`)
- feat(planner): add the advanced matrix planner (`6bd22ab`)
- feat(planner): allow matrix-planner editing and copying an existing structure (`9ab4a28`)
- feat(availability): let volunteers declare when they are available (`caa80a2`)
- feat(availability): use declared availability when planning and assigning (`3944a61`)
- feat(availability): add availability requests and shareable shift invitation links (`4c70611`)
- feat(assignment): let staff apply for shifts (`0c974f8`)
- feat(assignment): add manual assignment and manager overrides (`0058d26`)
- feat(assignment): add automatic assignment proposals (`7460e81`)
- feat(assignment): warn when a volunteer crosses the event-hours threshold (`093cfd8`)
- feat(departments): add the department shift management grid (`72b1e86`)
- feat(staff): add the schedule timeline with PDF export (`b232dbe`)
- feat(staff): add the staffing matrix with PDF export (`1a2d213`)
- feat(chat): add the conversation model (`7af8f06`)
- feat(chat): add the info desk queue with conversation claiming (`366919f`)
- feat(chat): add the chat interface with near-real-time polling (`ae09a15`)
- feat(chat): allow message editing and restrict sensitive content (`a80992c`)
- feat(chat): let volunteers contact their shift contacts directly (`cd04158`)
- feat(calls): add the global call for help (`2e94350`)
- feat(bounty-board): add the bounty board for open, unstaffed work (`ff7d8c6`)
- feat(sso): manage identity-provider group mappings from the administration UI (`6af4575`)
- feat(accounts): department import, two-factor recovery codes and administrative step-up (`d0412c9`)
- feat(ui): replace native browser confirmation dialogs with in-app modals (`24de264`)
- feat(security): use public UUIDs in URLs instead of internal database identifiers (`83d1976`)
- feat(ui): add the component gallery and developer reference for the shared interface macros (`902df38`)
- feat(departments): derive a member's position from their provider roles, and staff one by hand (`f3350da`)

### Changed

- docs(privacy): a Cookies section in the default privacy notice; no tracking cookies are used (`ccd3835`)
- refactor(certifications): render the certifications list as a card grid (`ccd3835`)
- style(2fa): enlarge the one-time recovery codes and adjust their layout on the backup-codes page (`ccd3835`)
- feat(navigation): remove the "Locate User" entry from the main navigation menu (`ccd3835`)
- refactor(contact)!: the DECT extension becomes a general 32-char `phone` field (`a508f53`)
- refactor(ui): restructure the navbar and move the audit log into the management area (`cdf4099`)
- refactor(shifts): rename "Shift Type" to "Shift Task" across routes, templates and labels (`7dc6461`)
- refactor(ui): repair the unusable shared macros and remove the misleading ones (`224d6b0`)
- refactor(ui): move templates onto the shared macros (`236e4f5`)
- refactor(ui): move the shift and news index pages onto the shared macros (`0ed5b28`)
- test(core): cover the foundation model, the security gate, and the database-backed flows (`2fb3554`)
- test(admin): cover management-area access control and the event configuration store (`61152d3`)
- test(ui): cover the landing page and the password command (`109c56b`)
- test(shifts): cover hours calculation and the sign-up service (`fd9a5a1`)
- test(goodies): cover the hours cache and goodie eligibility (`1c5ab63`)
- test(shifts): cover eligibility status and sign-up options (`1517a75`)
- test(communications): cover notification email rules and the communications repositories (`192047a`)
- test(staff): cover duty-record duration and duty start/end/hours (`0e839be`)
- test(api): cover API key authentication and access to the public API and feeds (`b0b6f08`)
- test(certifications): cover the QR generator, the certification service and scan authorisation (`010f4d7`)
- test(shifts): update the unit tests for the changed hours and route signatures (`9a57477`)
- test(suite): truncate between tests instead of rebuilding the schema, cutting the run sharply (`2a5f2ba`)
- test(shifts): cover shift browsing, sign-up and cancellation (`fe6f1bd`)
- docs(project): add in-repo contributor guidance (stack, commands, conventions) (`2c87a05`)
- docs(project): expand the contributor guidance with the interface, theming and dialog rules (`72ef7f8`)
- docs(sso): document Keycloak group mapping and fix the development provisioning script (`bc0852a`)
- docs(project): rewrite the README and align the licence metadata with the MIT licence (`5a3cfc6`)
- docs(project): add this changelog, covering the project history (`b96b46a`)
- build(docker): fix the development container environment (`ba26385`)
- build(docker): run the dev containers as the host user, so `var/` stays writable (`7524f90`)
- chore(project): initial Symfony application skeleton (`3a4728c`)
- chore(cleanup): tidy code comments, docblocks and the importmap (`186b3db`)
- chore(platform): PDF rendering, pluggable storage, operational config keys and seed data (`db30084`)
- chore(ui): move the fluid-layout class from the body onto the navbar headers (`5178769`)
- chore(cleanup): remove implementation-history and dangling-reference comments (`f539efc`)
- chore(comments): restate history-narrating comments as the rules they imply (`860139a`)

### Fixed

- fix(planner): the side panel showed saved volunteer-type counts as 0; Twig's `merge` renumbered the keys (`ca79d40`)
- fix(2fa): recovery codes were issued but never shown, because Turbo discarded the response (`a83691a`)
- fix(install): accept a setup password carrying a secret file's trailing newline (`530f1be`)
- fix(security): drop the unused remember-me badge from the login authenticator (`17dbbf5`)
- fix(deploy): reset the install password default to empty (`a36fd10`)
- fix(config): normalise stored event dates to UTC so the configuration form saves (`49a13bb`)
- fix(onboarding): a new account is usable straight after login (`bf4bac8`)
- fix(sso): confirm volunteer types that are granted through a group mapping (`85c24ca`)
- fix(digital-id): stop the QR page pulling the user back after they navigate away (`f966941`)
- fix(rbac): let volunteers see Volunteer Types and Locations (`c1fea6a`)
- fix(navigation): hide the Volunteer Types and Locations menu entries from users without permission (`b918f56`)
- fix(planner): send the department identifier, not `NaN`, when painting shifts (`fde9587`)
- fix(security): keep the interface development kits out of production and behind administrator access (`fca068d`)
- fix(audit): run the messaging worker everywhere, and alarm when it stalls (`7f3ece5`)
- fix(exports): the worker built export archives on a disk the web container could not read (`40c2ab2`)
- fix(security): an expired session loaded the login page inside the navbar, leaving personal data on screen (`533e280`)
- fix(audit): the log page still asked whether an archive was on disk after storage became pluggable (`1142fd4`)
- fix(operations): real defaults for the ban screen and Info Desk messages, so a fresh install is not blank (`684bbc9`)
- fix(security): the inactivity limit was 30 minutes, not the 60 the code claimed (`f8a18af`)
- fix(theme): apply a chosen theme immediately rather than at the next full page load (`8decc18`)
- fix(observability): log the failures the application was discarding, including a 2FA gate failing open (`049a8fa`)
- fix(matrix): staff positions from the grid, and refresh it without a reload (`1e96df9`)
- fix(shift-tasks): let department managers own their tasks, and create them while planning (`8f0d3ab`)
- fix(planner): keep the grid position when painting, and freeze the hour column while scrolling (`e454a99`)
