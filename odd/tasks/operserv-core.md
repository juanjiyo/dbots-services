# dbots — OperServ core

Objective: the operator commands that need server authority, gated on real oper status.

## Specs

- **S1** — A mutating command (`KILL`, `KICK`) requires the sender to carry user mode `+o`. Operator status is never inferred from a nickname or a registered account.
- **S2** — `KILL <nick> [reason]` kills a connected user; without a reason it reports "Killed by OperServ".
- **S3** — `KICK <#channel> <nick> [reason]` removes a user from a channel through the server, not through channel access.
- **S4** — Targets are resolved by nickname to UID from the network state; an offline target is reported, never guessed.
- **S5** — `STATS` reports the visible user count and `IRCOPS` lists connected operators.
- **S6** — Enforcement is a port: the IRC adapter writes `KILL` and `SAKICK` as the ULine server, so the command layer is testable without a network.

## Tasks

- **T1** (S6) — `EnforcementInterface` and `S2sEnforcement`.
- **T2** (S1-S5) — `OperServCommandHandler`.
- **T3** — Fourth pseudo-client (`<sid>A00004`).
- **T4** — 8 unit tests; 78 in the suite.

## Log

- **L1** — Continued autonomously, using `sitiochat/dbots` `op.mrc` as the command inventory reference.
- **L2** — Verified live with a real oper: RPC `user.set_oper` granted `netadmin` to a test client, `KILL KillTarget probando OperServ` produced "Client killed: KillTarget!db@localhost [by: bots.sitiochat.es]" and the victim's connection dropped.
- **L3** — A non-oper trying `KILL` gets "This command is restricted to IRC operators." and nothing is written to the link.
- **L4** — Not implemented yet from the reference inventory: GLINE, MODE, OP/DEOP, SPAM, clones, SETTIME, ROOT, APODERA, RENAME, LIMPIA, BLOCK, DROPNDB.
- **L5** — Added `OP`/`DEOP` and `MODE` through `SAMODE`, still gated on `+o`. Verified live: an oper applied `+m` to a channel and the IRCd accepted it.
- **L6** — `GLINE` and the rest of the reference inventory are deliberately not implemented yet: they need TKL wire work and a duration policy that deserves its own unit.

- **L7** — Added `GLINE`/`UNGLINE` over UnrealIRCd TKL: `TKL + G <user> <host> <set-by> <expire> <set-at> :<reason>` and `TKL - G <user> <host> <set-by>`. Duration accepts `0` (permanent), a bare number (minutes) or a suffix (`30m`, `2h`, `1d`, `1w`).
- **L8** — Verified live against the IRCd's own ban list: after `GLINE *@203.0.113.99 10m pruebas de gline`, `server_ban.list` reported `type: gline`, `set_by: 004` (our SID), the exact expiry and reason; after `UNGLINE`, the entry was gone. The test mask is TEST-NET on purpose, so the verification cannot lock anybody out.

- **L9** — Read the reference before coding, and it changed the design: in `op.mrc`, `APODERA` takes over a **channel**, `RENAME` forces a connected user's nickname and `DROPNDB` deletes the nick from the database. Implementing the names without reading would have produced three wrong commands.
- **L10** — Implemented: `RENAME <nick> [newnick]` over `SVSNICK`; `DROPNDB <nick>` deletes the account and its UDB record; `APODERA <#channel> [nick]` reassigns the founder and re-publishes the channel.
- **L11** — Verified live: the victim's connection was renamed, `#finaltest` changed founder, and `DROPNDB RegFlow` removed the account so the same credential no longer identifies. A broken daemon start (a projection built after its use in `Kernel`) was caught by the service failing to come up, not by the tests.

- **L12** — `KILLCLONES` and `BLOCK` are not kill loops in the reference: both G-Line the nick's **host**, 60 minutes for clones and 300 for a block. Implemented that way, reusing the TKL path, with `LISTCLONES` reporting who shares a host.
- **L13** — The network state now tracks each user's host (UID field 4), which the clone commands need.
- **L14** — Added a protected-host guard (`localhost`, `127.0.0.1` by default): the clone commands refuse to G-Line it. Without it, an operator testing from the same box would ban themselves and everyone else on it. Verified live: `LISTCLONES` found three clients on `localhost`, and `BLOCK` correctly refused.
- **L15** — Deliberately not implemented, with reasons: `SETTIME` mutates the network clock (an outage vector for timestamps), `ROOT` needs a root-list product model we do not have, `LIMPIA` delegates to ChanServ in the reference and has no defined mode policy here, and `SPAM` has no written definition. Each deserves its own unit rather than a guess.
