# dbots — NickServ core

Objective: a minimal NickServ that registers nicknames and authenticates users on UnrealIRCd.
Branch: `main`. Test runner: `vendor/bin/phpunit --testsuite unit`.

## Specs

- **S1** — `NICKSERV REGISTER <password> [email]` (alias `REGISTRA`) creates an account for the sender's current nickname and logs the sender in.
- **S2** — `NICKSERV IDENTIFY <password>` (alias `IDENTIFICA`) authenticates the sender against the stored hash and logs them in.
- **S3** — A nickname can only be registered once; the check is case-insensitive.
- **S4** — Passwords are stored as a hash and never echoed back, never logged and never placed in a reply.
- **S5** — A password shorter than 6 characters is rejected at registration; on login it is reported as a wrong password, never as a policy violation.
- **S6** — Login means both `SVSLOGIN` and `SVS2MODE +r`: SVSLOGIN alone does not set the identified mode.
- **S7** — Replies are NOTICEs sent from the NickServ pseudo-client UID to the sender UID.
- **S8** — Accounts persist in SQLite, schema created on first use.

## Tasks

- **T1** (S3, S4, S5) — Domain: `Nick`, `Password`, `Account`. inline.
- **T2** (S1, S2, S3, S5) — `RegisterNickHandler`, `IdentifyHandler` with explicit result enums. inline.
- **T3** (S8) — `SqliteAccountRepository`. inline.
- **T4** (S6, S7) — `S2sAccountLogin`, `IrcReplySender`, `ServiceUidFormatter`. inline.
- **T5** (S1, S2) — Wire parsing, `NetworkState` (UID → nick), `NickServCommandHandler`. inline.
- **T6** — `Kernel` wiring + `bin/dbots`. inline.
- **T7** — Unit tests (34) and one live end-to-end run. inline.

## Log

- **L1** — User: "si" (continuar con NickServ mínimo).
- **L2** — Sender identity: UnrealIRCd 6 introduces users by UID, so a PRIVMSG prefix is a UID, not a nick. `NetworkState` is fed from the burst during `connect()`, not only afterwards. Missing this would have made every command answer "unknown sender".
- **L3** — Verified live: REGISTER replies "Nickname registered. You are now identified." from `NickServ!NickServ@bots.sitiochat.es`; a second connection with IDENTIFY replies "Password accepted. You are now identified.".
- **L4** — Verified live via JSON-RPC: the registered client carries **+r** (`modes: irwxT`).
- **L5** — **Open finding (blocks real account binding):** `SVSLOGIN` sets no account because the IRCd is its own UDB authority; the account does not exist in its database. In production the daemon must be the UDB propagator — the same role Ares plays. Until then, +r works but `account` stays empty, so +R channels and account-based checks do not see the user.
- **L6** — Dev-only change to the local IRCd: the `udb { propagator "services.sitiochat.es"; }` block is commented out so clients can connect while Ares is off. Backup of `/opt/unrealircd/data/udb` and `.udb_state` taken first. Restore the propagator when Ares comes back.
- **L7** — Next milestone: UDB protocol (HEL 4 + the six-block snapshot) so accounts live in the IRCd and native `NICK nick:pass` authentication works.
