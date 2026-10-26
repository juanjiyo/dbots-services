# dbots — NiCK SET remaining options

Objective: extend `NiCK SET` with the user-configurable account options from the
original `ni.mrc`: `EMAIL`, `URL`, `QUIT`, `PRIVATE`, `KILL` and `AUTOMODE`.
`PASSWORD` is already implemented; `VHOST` was deliberately moved to a future
`HostServ`.

## Specs

- **S1** — `/msg NiCK SET EMAIL <email>` updates the account email; without a
  value it clears it.
- **S2** — `/msg NiCK SET URL <url>` updates the account URL; without a value
  it clears it.
- **S3** — `/msg NiCK SET QUIT ON|OFF` controls whether the last quit message
  is shown by `INFO`.
- **S4** — `/msg NiCK SET PRIVATE ON|OFF` controls whether account info is
  hidden from `INFO`.
- **S5** — `/msg NiCK SET KILL ON|QUICK|OFF` sets nick protection on login:
  `ON` (normal), `QUICK` (reduced grace), `OFF` (disabled).
- **S6** — `/msg NiCK SET AUTOMODE <modes>` sets modes applied automatically on
  identify (e.g. `+x`); without a value it clears them.
- **S7** — All options require the user to be identified; each mutating call
  republishes the account profile to the IRCd.
- **S8** — `INFO` reflects the new fields (`URL`, `EMAIL`, `PRIVATE`, `KILL`,
  `AUTOMODE`).
- **S9** — Help entries and `docs/commands.md` updated.
- **S10** — Unit tests for every option.

## Tasks

- [ ] **T1** (S1-S6) — Extend `Account` entity with `url`, `quitVisible`,
  `private`, `kill`, `automode` fields.
- [ ] **T2** (S1-S6) — Update `SqliteAccountRepository` to persist and hydrate
  the new fields.
- [ ] **T3** (S1-S6) — Update `AccountBlockProvider` to publish the new fields
  in the UDB `N` block.
- [ ] **T4** (S1-S6) — Create `UpdateAccountSettingHandler` with option
  validation.
- [ ] **T5** (S1-S6, S8) — Extend `NickServCommandHandler::onSet` to dispatch
  every option and republish.
- [ ] **T6** (S8) — Update `AccountInfoHandler` to show the new fields.
- [ ] **T7** (S9) — Update `NickServHelp` and `docs/commands.md`.
- [ ] **T8** (S10) — Unit tests.
- [ ] **T9** — Run `composer check`, commit, and push.

## Log

- **L1** — User wants roadmap items in order; NiCK SET is next after OPeR SET.
- **L2** — Reference `ni.mrc` shows `SET EMAIL|URL|QUIT|PRIVATE|KILL|AUTOMODE`
  as per-account configuration. `PASSWORD` already exists and `VHOST` moves to
  HostServ.
