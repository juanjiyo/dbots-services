# dbots — NiCK SENDPASS (oper only)

Objective: add an IRC-operator-only `SENDPASS <nick>` command to NiCK that
resets a registered account's password and delivers the new password to the
account via memo.

## Specs

- **S1** — `/msg NiCK SENDPASS <nick>` is only usable by IRC operators (`+o`).
- **S2** — The target nick must be a registered account; otherwise NiCK replies
  that it is not registered.
- **S3** — A new random password is generated, hashed, and stored on the
  account.
- **S4** — The new password is sent to the target account via MeMo, bypassing
  any no-memo blacklist (a service memo is not a user memo).
- **S5** — The operator receives confirmation that the password was reset and
  that a memo was sent.
- **S6** — `GETPASS` is deliberately not implemented because passwords are
  hashed.
- **S7** — Help entry for `SENDPASS` appears in the operator-only section of
  NiCK HELP.
- **S8** — `docs/commands.md` updated: `SENDPASS` done, `GETPASS` removed/dropped.

## Tasks

- [x] **T1** (S3) — `SendPasswordHandler` that generates a password, updates the
  account, and stores a service memo.
- [x] **T2** (S1, S2, S5) — `onSendPass` in `NickServCommandHandler` with oper
  gate.
- [x] **T3** (S7) — Help entry.
- [x] **T4** (S4) — Wire `MemoRepositoryInterface` into the handler/factory.
- [x] **T5** (S8) — Update `docs/commands.md`.
- [x] **T6** — Unit tests.
- [ ] **T7** — Run `composer check`, commit, and push.

## Log

- **L1** — User wants every remaining `todo` done except ChanFix.
- **L2** — User clarified: `SENDPASS` is IRC-operator-only; `GETPASS` is out.
- **L3** — Original `ni.mrc` sent the existing password via email; dbots hashes
  passwords, so the equivalent is generating a new password and delivering it
  via memo.
