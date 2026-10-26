# dbots — CeNTeR RAW / NORAW

Objective: implement the `RAW <line>` and `NORAW ADD|DEL|LIST <command>` commands in
CeNTeR, gated to Root, with a blocklist and an audit trail.

## Specs

- **S1** — `RAW <line>` is Root-only. The sender must have an active Root session
  (`ROOT <password>`) or hold the Root rank.
- **S2** — `RAW <line>` sends the supplied text verbatim to the IRCd via the existing
  `LinkTransportInterface::writeLine()`, prefixed with the services SID (`:<sid> <line>`).
- **S3** — Before sending, the first token of `<line>` is checked against a blocklist.
  If it is blocked, the command is rejected and the attempt is logged.
- **S4** — `NORAW ADD|DEL|LIST <command>` manages the blocklist. It is Root-only.
  The list is persisted (SQLite, same pattern as other repositories).
- **S5** — Default blocked commands: `SQUIT`, `CONNECT`, `KILL`, `GLINE`, `UNGLINE`,
  `KLINE`, `UNKLINE`, `RESTART`, `DIE`, `OPER`, `LOCOP`, `SDIE`.
- **S6** — Audit trail: every allowed `RAW` usage and every blocked attempt is logged
  through the existing `DebugRelay` so it appears in the debug channel.
- **S7** — Replies keep the original Spanish wording style:
  - Syntax: `Error de Sintaxis: RAW <linea>`
  - Blocked: `Lo siento, el comando <cmd> está bloqueado y no puede ser enviado por RAW.`
  - Sent: `Ejecutado <line>.`
  - NORAW syntax: `Error de Sintaxis: NORAW ADD|DEL|LIST <comando>`
  - NORAW added: `Bloqueado el comando <cmd>`
  - NORAW removed: `Liberado el comando <cmd>`
  - NORAW list empty: `Lista vacia.`
- **S8** — Update `CeNTeRHelp` and `docs/commands.md` to mark `RAW` and `NORAW` as done.

## Tasks

- [x] **T1** (S4) — Create `RawBlocklistRepositoryInterface` and SQLite implementation
  with default blocked commands.
- [x] **T2** (S1-S3, S6-S7) — Add `RAW` dispatch and handler in
  `CenTeRServCommandHandler`, with transport injection and debug logging.
- [x] **T3** (S4-S7) — Add `NORAW ADD|DEL|LIST` dispatch and handler.
- [x] **T4** (S8) — Update `CeNTeRHelp` and `docs/commands.md`.
- [x] **T5** — Add unit tests for blocklist repository, RAW gating, blocked commands,
  allowed commands, and NORAW management.
- [x] **T6** — Run `composer check`, commit, and push.

## Risk

- **High** — `RAW` can send arbitrary lines to the IRCd. Mitigations: Root-only gate,
  blocklist with dangerous defaults, audit logging.

## Log

- **L1** — User asked to continue with CeNTeR; the only remaining item is RAW/NORAW.
