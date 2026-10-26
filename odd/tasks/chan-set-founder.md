# dbots — CHaN SET FOUNDER|SUCCESSOR

Objective: implement founder transfer and successor assignment for registered
channels, matching the original `ch.mrc` behaviour.

## Specs

- **S1** — `/msg CHaN SET <#canal> FOUNDER <nick>` transfers channel ownership
to an existing registered nick.
- **S2** — Only the current channel founder or an IRC operator may transfer
ownership.
- **S3** — The target nick must be registered, cannot be the current founder,
and cannot be the current successor.
- **S4** — After transfer, the UDB `C::<canal>::founder` record is republished.
- **S5** — `/msg CHaN SET <#canal> SUCCESSOR [nick]` sets (or clears) the
channel successor.
- **S6** — Only the current channel founder or an IRC operator may set or clear
the successor.
- **S7** — The target nick, when provided, must be registered, cannot be the
current founder, and cannot already be the successor.
- **S8** — Clearing a non-existent successor reports that there is no successor.
- **S9** — Help entries and `docs/commands.md` updated.
- **S10** — Unit tests cover founder transfer, successor set/clear, permission
denials, and validation errors.

## Tasks

- [x] **T1** (S1-S8) — Add `successor` field to `Channel` entity.
- [x] **T2** (S1-S8) — Extend `ChannelRepositoryInterface` with
`updateSuccessor` and update SQLite + in-memory implementations.
- [x] **T3** (S1-S4) — Add `publishFounder` to channel projection and wire it.
- [x] **T4** (S1-S8) — Implement `SET FOUNDER` and `SET SUCCESSOR` in
`ChanServCommandHandler`.
- [x] **T5** (S9) — Update `ChanServHelp` and `docs/commands.md`.
- [x] **T6** (S10) — Add unit tests.
- [x] **T7** — Run `composer check`, commit, and push.

## Log

- **L1** — User approved continuing with `CHaN SET FOUNDER|SUCCESSOR` after
`NiCK SET`.
- **L2** — Reference `ch.mrc` shows `SET FOUNDER` requires target registration,
disallows founder==successor, and only founder/IRCop may run it; `SET
SUCCESSOR` behaves the same but allows clearing the value.
