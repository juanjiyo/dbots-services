# dbots — PreOpServ port

Status: **parked / will not port**. After reviewing the original `op.mrc`, the
pre-operator commands live inside OPeR and use the `PreOperador` staff rank. The
Pulsar C module `modules/preopserv` is a different beast with `BOT`/`UNBOT`
semantics that do not map cleanly to dbots UDB. The operator decided not to port
it; if pre-operator commands are wanted later, they should be added to OPeR
instead.

## Open decisions

- **D1** — Form factor: built-in service (like `OPeR`) or loadable module? The
  existing module system has no dependency injection, so a module cannot reach the
  account store or staff ranks. A built-in service is the natural fit.
- **D2** — `BOT` / `UNBOT` semantics. Pulsar sets a vhost **and** sends a custom
  `o` block (`b:1`) to the IRCd. dbots UDB only publishes `N`, `C`, `I`, `S`, `L`,
  `K`. Options:
  1. Set only the vhost (`nick.bot.<network>`) and ignore the bot flag.
  2. Add an `isBot` flag to the account, persist it, but do not project it to the
     IRCd until the UDB module supports it.
  3. Drop `BOT` / `UNBOT` from this port and keep only `OP`, `DEOP`, `INVITE`.
- **D3** — Permission gate. Pulsar requires the sender to be an IRC oper (`+o`)
  *and* a services oper/root for commands. dbots can require `+o` plus the
  relevant CeNTeR rank, or only the CeNTeR rank.

## Specs (pending D1-D3)

- **S1** — `PrEoP` is introduced as `<SID>A00010`, after `CLoNeS`.
- **S2** — Listed in `HeLP` under `BOTS`.
- **S3** — `OP <#canal> <nick>` and `DEOP <#canal> <nick>` give or remove `+o` on
  a channel member.
- **S4** — `INVITE <nick> <#canal>` invites a user to a channel.
- **S5** — `BOT <nick>` / `UNBOT <nick>` manage the bot/vhost status of a
  registered account.
- **S6** — Replies use the original Pulsar wording where it makes sense.
- **S7** — Unit tests for every command; `composer check` stays green.

## Tasks

- [ ] **T1** (S1, S2) — Introduce `PrEoP` as a built-in service and wire it into
  the daemon, factory, and help catalog.
- [ ] **T2** (S3-S4) — `PreOpCommandHandler` with `OP`, `DEOP`, `INVITE`.
- [ ] **T3** (S5) — `BOT` / `UNBOT`.
- [ ] **T4** (S6) — Help text and command docs.
- [ ] **T5** (S7) — Tests.
- [ ] **T6** — Run `composer check`, commit, and push.

## Log

- **L1** — User asked to port `/home/juanjo/irc/services/pulsar/modules/preopserv`
  together with the future Pulsar antispam port.
- **L2** — Reference C module commands: `HELP`, `OP`, `DEOP`, `INVITE` (oper+), and
  `BOT` / `UNBOT` (services root). It also registers a NickServ callback that
  forbids registering the service nick.
