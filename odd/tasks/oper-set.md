# dbots — OPeR SET command

Objective: add the `SET` command to `OPeR`, matching the original `op.mrc`
behaviour for configuring the UDB `S` block service settings.

## Specs

- **S1** — `/msg OPeR SET <option> <value>` is only usable by IRC operators
  (`+o`).
- **S2** — Supported options, mapped to UDB `S` block paths:
  - `SUFIJO <letters>` -> `J` (virtual host suffix; letters only).
  - `NICKSERV nick!ident@host` -> `N`.
  - `CHANSERV nick!ident@host` -> `C`.
  - `IPSERV nick!ident@host` -> `I`.
  - `PASSPROTECT <times>:<seconds>` -> `F` (both integers).
  - `PREFIJOS <chars>` -> `P` (prefix characters for +q +a +o +h +v).
- **S3** — Invalid values produce a syntax error specific to the option.
- **S4** — Unknown options produce a generic syntax/option error.
- **S5** — Settings are persisted and published to the UDB `S` block so the
  IRCd sees them on the next sync and via live `INS` frames.
- **S6** — `docs/commands.md` marks `SET` as done.
- **S7** — Help entry for `OPeR SET`.
- **S8** — Unit tests for validation, persistence, and UDB publication.

## Tasks

- [ ] **T1** (S5) — `UdbSettingsRepositoryInterface` and SQLite implementation.
- [ ] **T2** (S5) — `UdbSettingsBlockProvider` to merge settings into the S
  block.
- [ ] **T3** (S2-S4) — `SetUdbSettingHandler` with validation.
- [ ] **T4** (S5) — `UdbSettingsProjection` to publish changes via
  `UdbAuthority`.
- [ ] **T5** (S1-S4) — `onSet` in `OperServCommandHandler` with oper gate.
- [ ] **T6** (S7) — Help entry.
- [ ] **T7** (S6) — Update `docs/commands.md`.
- [ ] **T8** (S8) — Unit tests.
- [ ] **T9** — Wire repository, provider, and projection in `DaemonFactory` and
  `config/services.yaml`.
- [x] **T10** — Run `composer check`, commit, and push.

## Log

- **L1** — User wants the remaining roadmap items in order, skipping CeNTeR RAW,
  ChanFix and HostServ.
- **L2** — Reference `op.mrc`: `SET SUFIJO|NICKSERV|CHANSERV|IPSERV|PASSPROTECT|PREFIJOS`
  writes `S::J|N|C|I|F|P` values to the UDB.
