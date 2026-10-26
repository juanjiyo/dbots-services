# NiCK email validation (BLACKMAIL, VREG, VALIDAR)

Implement the original dBots email-based registration controls: domain blacklist, optional email validation, and validation code submission.

Branch: `feat/center-hot-modules`
Test runner: `composer check`

## Specs

- **S1** (BLACKMAIL): NiCK accepts `BLACKMAIL ADD|DEL|LIST <domain>` (oper-only). Domains on the list block registration when the supplied email belongs to one of them.
- **S2** (BLACKMAIL storage): Blacklisted domains are persisted in SQLite and projected to UDB as `S::blackmail::<domain>`.
- **S3** (VREG): NiCK accepts `VREG ON|OFF` (oper-only). When OFF, registration behaves as today. When ON, registration with an email becomes pending until validated.
- **S4** (VREG storage): The VREG flag is persisted as a UDB setting.
- **S5** (REGISTER with VREG ON): When a user runs `REGISTER <password> <email>` and VREG is ON, the account is **not** created immediately. A random code is generated and sent to the email address. The nick is reserved for the duration of the code.
- **S6** (email sender abstraction): The system uses an `EmailSenderInterface`. Two real implementations are provided: SMTP (traditional) and Symfony Mailer. When email config is empty/blank, a `DisabledEmailSender` is used. In disabled mode the code is still generated and logged/stored so `VALIDAR` can be tested.
- **S7** (VALIDAR): `VALIDAR <código>` checks the pending registration for the identified nick. If the code matches and has not expired, the account is created.
- **S8** (pending cleanup): Pending registrations expire after 15 minutes; the nick reservation is released.
- **S9** (help/docs): Update `NickServHelp` and `docs/commands.md` to list `BLACKMAIL`, `VREG`, and `VALIDAR` as done.
- **S10** (tests): Add unit tests for BLACKMAIL, VREG ON/OFF, REGISTER pending, VALIDAR success/failure/expiry, and email sender selection.

## Tasks

- [x] **T1** (S1, S2) — Create `BlackmailRepository`, SQLite table, and `BlackmailHandler`.
- [x] **T2** (S3, S4) — Wire `VREG ON|OFF` into `SetUdbSettingHandler` or a dedicated setting handler.
- [x] **T3** (S6) — Create `EmailSenderInterface`, `DisabledEmailSender`, `SmtpEmailSender`, `SymfonyMailerEmailSender`, and config selection.
- [x] **T4** (S5, S7, S8) — Create `PendingRegistrationRepository` and modify `RegisterNickHandler`/`NickServCommandHandler` for pending flow.
- [x] **T5** (S9) — Update `NickServHelp` and `docs/commands.md`.
- [x] **T6** (S10) — Add unit tests.
- [ ] **T7** — Run `composer check`, commit, and push.

## Log

- L1: User wants `BLACKMAIL`, `VREG`, and `VALIDAR` implemented; `migrar` left out.
- L2: Email must be real, but SMTP is not configured yet because this is a test network.
- L3: Two real email sender options: SMTP traditional and Symfony Mailer. Empty config = disabled; when disabled the validation code is kept testable.
- L4: `VREG` is the global ON/OFF switch for email validation. OFF = immediate registration; ON = pending until `VALIDAR` succeeds.
