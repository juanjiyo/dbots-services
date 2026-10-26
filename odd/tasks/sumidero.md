# dbots — Módulo Sumidero

Objective: port the anti-spam/anti-clone sink behaviour of
`/home/juanjo/irc/sitiochat/sumidero` into a dbots module.

## Background

The original SumideroBot is a Python IRC client that uses the UnrealIRCd JSON-RPC
socket. A dbots module is an S2S pseudo-client, so the same actions must be
performed via server-to-server commands.

## Specs

- **S1** — Module `Sumidero` is introduced as a pseudo-client when enabled via
  `CeNTeR MODULO LOAD Sumidero`. Its nick is configurable via `module.json`
  (default `Sumidero`).
- **S2** — Configuration is read from environment variables:
  - `DBOTS_SUMIDERO_SINK` — quarantine channel (default `#Sumidero`).
  - `DBOTS_SUMIDERO_WATCHED` — comma-separated watched channels (default empty).
  - `DBOTS_SUMIDERO_ADMINS` — comma-separated admin nicks/masks.
  - `DBOTS_SUMIDERO_GATE_ENABLED` — enable anti-clon gate (default `yes`).
  - `DBOTS_SUMIDERO_VALIDATE_SECONDS` — seconds before an unidentified user is
    sunk (default `30`).
  - `DBOTS_SUMIDERO_SHUN_TIME` — base SHUN duration (default `15m`).
  - `DBOTS_SUMIDERO_SHUN_MAX` — max SHUN duration (default `24h`).
  - `DBOTS_SUMIDERO_FLOOD_MESSAGES` — messages in the flood window (default `6`).
  - `DBOTS_SUMIDERO_FLOOD_WINDOW` — flood window in seconds (default `8`).
  - `DBOTS_SUMIDERO_FLOOD_LENGTH` — single-message length considered flood
    (default `400`).
  - `DBOTS_SUMIDERO_FLOOD_REPEAT` — identical message repetitions considered
    flood (default `3`).
  - `DBOTS_SUMIDERO_VERIFY_URL` — base URL for web verification (default empty).
  - `DBOTS_SUMIDERO_VERIFY_BRIDGE` — `irc` (default), `sql`, or `none`.
- **S3** — The module joins the sink channel and every watched channel as a
  normal channel member (`JOIN`).
- **S4** — **Anti-clon gate**: when a user connects and is still not identified
  (`+r`) after `DBOTS_SUMIDERO_VALIDATE_SECONDS`, the module sinks them.
- **S5** — **Flood detection**: in watched channels, track per-user message rate,
  single-message length and repeated text. On flood, sink the offender.
- **S6** — **Sink action**: the module
  1. sends `SVSJOIN` to the sink channel,
  2. applies a `SHUN` to `*@<real-host>` for the configured duration,
  3. generates a verification token,
  4. sends a NOTICE to the offender with the reason and verification URL.
- **S7** — **SHUN escalation**: repeat offenders get the duration multiplied by
  the number of offenses, capped at `DBOTS_SUMIDERO_SHUN_MAX`.
- **S8** — **Verification bridge (`irc`)**: a web process or authorized bridge
  sends `PRIVMSG Sumidero :VERIFICAR <token> <ip>`. The module validates the
  token and IP, then removes the SHUN and releases the user.
- **S9** — **Staff commands** (via PRIVMSG to the module, no prefix):
  - `help` — command list.
  - `status` — uptime, active shuns, ignored masks.
  - `release <nick|mask>` — removes the active SHUN and releases the user.
  - `sink <nick> [motivo]` — manually sinks a user.
  - `ignore <nick|mask>` / `unignore <nick|mask>` — exclusion list.
  - `spam add <patrón>` / `spam del <n>` / `spam list` / `spam reload` — manage
    the spam pattern list used for flood detection.
- **S10** — **Persistence**: keep state in a SQLite file (`data/sumidero.db`):
  active shuns, tokens, offenses, ignored masks, added operators.
- **S11** — Extend the module runtime so modules can:
  - receive `EnforcementInterface`,
  - schedule delayed callbacks (for the anti-clon gate),
  - access a per-module SQLite PDO.
- **S12** — Add `SHUN` support to `EnforcementInterface` and `S2sEnforcement`.
- **S13** — Add unit tests for flood detection, anti-clon gate, staff commands,
  and verification.
- **S14** — Update `docs/commands.md` and the CeNTeR `MODULO` help to mention the
  Sumidero module.

## Out of scope (future)

- SQL verification bridge.
- Publishing native UnrealIRCd spamfilters.
- Full web UI.

## Tasks

- [x] **T1** (S11-S12) — Extend `ModuleContext` with `EnforcementInterface`,
  scheduling, and per-module PDO; add `SHUN` to `EnforcementInterface`.
- [x] **T2** (S1-S3) — Create `modules/Sumidero/module.json` and the module
  class; wire it to join configured channels.
- [x] **T3** (S4) — Implement anti-clon gate with scheduled validation.
- [x] **T4** (S5-S7) — Implement flood detection and sink action.
- [x] **T5** (S8) — Implement IRC verification bridge and release.
- [x] **T6** (S9) — Implement staff commands.
- [x] **T7** (S10) — Add SQLite persistence for state.
- [x] **T8** (S13) — Add unit tests.
- [x] **T9** (S14) — Update docs.
- [ ] **T10** — Run `composer check`, commit, and push to `main`.

## Risk

- **High** — The module applies SHUNs and forces users into channels. Mitigations:
  - Only staff commands are gated by admin masks.
  - Flood and anti-clon thresholds are configurable.
  - `release` can undo a SHUN immediately.
  - Extensive unit tests and a conservative default configuration.

## Log

- **L1** — User asked to review `/home/juanjo/irc/sitiochat/sumidero` and create a
  dbots module from it.
- **L2** — Agreed minimum viable scope: staff commands, flood detection,
  anti-clon gate, SHUN + SVSJOIN sink, IRC verification bridge, SQLite state.
