# dbots — MemoServ preferences (NOMEMOS / SET NOTIFY)

Objective: port the `NOMEMOS` blacklist and `SET NOTIFY` notification mode from
`me.mrc` so users can control who may send them memos and when they are
notified.

## Specs

- **S1** — `NOMEMOS ADD <nick>` blocks the account of `<nick>` from sending
  memos to the caller. Requires the caller to be identified; `<nick>` must be a
  registered account.
- **S2** — `NOMEMOS DEL <nick>` removes that account from the caller's blacklist.
- **S3** — `NOMEMOS LIST` lists the blocked accounts.
- **S4** — `SET NOTIFY ON|LOGON|NEW|OFF` chooses when unread memos are announced:
  - `ON` — on login/identify and when a new memo is received.
  - `LOGON` — only on login/identify.
  - `NEW` — only when a new memo is received.
  - `OFF` — never.
- **S5** — A blacklisted sender cannot send a memo; `SEND` replies that the
  recipient has them blocked.
- **S6** — Login-time notification (`notifyPending`) only runs for modes `ON`
  and `LOGON`.
- **S7** — Immediate notification after a successful `SEND` only runs for modes
  `ON` and `NEW`, and only if the recipient is currently online.
- **S8** — Help entries for both commands and `docs/commands.md` updated.
- **S9** — Unit tests for blacklist and notify modes.

## Tasks

- [x] **T1** (S4, S6, S7) — `MemoPreferencesRepositoryInterface` and SQLite
  implementation for notify mode.
- [x] **T2** (S1-S3) — Blacklist add/del/list in the same repository.
- [x] **T3** (S5) — Update `SendMemoHandler` to reject blacklisted senders.
- [x] **T4** (S6-S7) — Wire preferences into `MemoServCommandHandler` for
  `notifyPending` and immediate notifications.
- [x] **T5** (S1-S3) — `NOMEMOS` command handler.
- [x] **T6** (S4) — `SET NOTIFY` command handler.
- [x] **T7** (S8) — Help text and `docs/commands.md`.
- [x] **T8** (S9) — Unit tests.
- [x] **T9** — Run `composer check`, commit, and push.

## Log

- **L1** — User wants every remaining `todo` done except ChanFix; MemoServ
  `NOMEMOS` / `SET NOTIFY` is the next smallest item.
- **L2** — Reference `me.mrc` behaviour: `NOMEMOS ADD|DEL|LIST <nick>` blocks the
  sender's account; `SET NOTIFY ON|LOGON|NEW|OFF` controls login-time and
  immediate memo announcements.
