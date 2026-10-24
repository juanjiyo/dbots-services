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
