# dbots — MemoServ core

Objective: leave messages for an account that survives disconnections.

## Specs

- **S1** — `MEMOSERV SEND <nick> <message>` (alias `ENVIA`) stores a memo for another account. The recipient must be a registered account.
- **S2** — Only an identified sender can send, list, read or delete: identity comes from `+r` plus the account store.
- **S3** — `LIST` (alias `LISTA`) shows only the caller's memos with id, sender, date and an unread marker.
- **S4** — `READ <n>` (alias `LEE`) returns the body and marks it read; a memo that is not the caller's behaves as missing.
- **S5** — `DELETE <n>` deletes only the caller's memo.
- **S6** — Memos belong to the account, not the session, so they survive nick changes and restarts.
- **S7** — A memo has no UDB projection: there is no memo block, so it never enters the replicated database.

## Tasks

- **T1** (S6) — `Memo` domain object and `MemoRepositoryInterface`.
- **T2** (S1, S3, S4, S5) — `SendMemoHandler`, `ReadMemoHandler`, `DeleteMemoHandler`; `SqliteMemoRepository`.
- **T3** (S2) — shared `AccountResolver` (extracted from ChanServ so identity is resolved in one place).
- **T4** (S1-S5) — `MemoServCommandHandler`.
- **T5** — Third pseudo-client (`<sid>A00003`) with its own reply sender.
- **T6** — 9 unit tests; 65 in the suite.

## Log

- **L1** — Continued autonomously after ChanServ.
- **L2** — Identity logic was extracted into `AccountResolver` before adding a third service, instead of copy-pasting the `+r` + account-store rule a second time.
- **L3** — Verified live: `SEND` replies "Memo sent to FreshUser", `LIST` shows "#1 from FinalUser ... [unread]", `READ 1` returns the body and clears the unread marker.
- **L4** — Reads and deletes are scoped by recipient at the SQL level, so a guessed id cannot reach another account's memo.
