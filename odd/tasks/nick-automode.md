# NiCK AUTOMODE

Implement `AUTOMODE <nick> [modos]` for NiCK with the original mIRC syntax and behaviour. Apply the stored modes automatically when the user is identified (`+r`).

Branch: `feat/center-hot-modules`
Test runner: `composer check`

## Specs

- **S1** (format): NiCK accepts the standalone command `AUTOMODE <nick> [modos]` (Spanish spelling also accepted where the original did). This matches the original dBots mIRC command.
- **S2** (set): When modes are provided, validate them and store them as the account's `automode`. Apply them immediately if the target nick is online and identified.
- **S3** (clear): When no modes are provided, remove the account's `automode`.
- **S4** (mode validation): Users can only set modes they are allowed to wear. Modos de IRCop/staff (`B`, `A`, `o`, `k`, `q`, and any other oper-only modes) and `+z` (SSL) are rejected with an error. This validation also applies when the daemon applies a stored automode at login.
- **S5** (auto-apply): When a user receives user mode `+r` (`UMODE2 +r`), the daemon reads the account's stored `automode` and sends `SVS2MODE` to apply it.
- **S6** (permissions): A normal user may only change the automode of their own account. IRC operators may change the automode of any registered nick.
- **S7** (docs): Update `NickServHelp` and `docs/commands.md` to list `AUTOMODE` as done.
- **S8** (tests): Add unit tests for setting, clearing, validation, permissions, and auto-apply on `+r`.

## Tasks

- [x] **T1** (S1, S6) — Add `AUTOMODE` dispatch to `NickServCommandHandler`.
- [x] **T2** (S2, S3, S4) — Create `UpdateAutomodeHandler` with mode validation.
- [x] **T3** (S5) — Apply stored automode in `Daemon::onUmode2` when `+r` is received.
- [x] **T4** (S7) — Update `NickServHelp` and `docs/commands.md`.
- [x] **T5** (S8) — Add unit tests.
- [x] **T6** — Run `composer check`, commit, and push.

## Log

- L1: User wants `AUTOMODE` kept with the original mIRC format: `AUTOMODE <nick> [modos]`.
- L2: User clarified oper-only modes (`+BAokq` and similar) and `+z` are blocked for normal users; only IRC operators or services may apply them.
- L3: User confirmed implementation should proceed.
