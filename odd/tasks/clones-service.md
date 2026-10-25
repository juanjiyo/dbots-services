# dbots — CLoNeS service

Objective: replace the standalone Python clones bot (`/home/juanjo/irc/clones-bot/`)
with a built-in dbots service named `CLoNeS`, introduced like `CHaN` or `OPeR`.

## Specs

- **S1** — `CLoNeS` is introduced as `<SID>A00009`, after `CeNTeR`.
- **S2** — The service is listed in `HeLP` under `BOTS`.
- **S3** — Commands require `Administrador` (CeNTeR rank) because they change the
  IRCd configuration.
- **S4** — `PERM <IP|host> <N>` creates a permanent `<connect>` exception with
  `localmax="N" globalmax="N"`.
- **S5** — `ADD <IP|host> <N> <duration>` creates a temporary exception. Duration
  accepts `30d`, `6m`, `1y` (mirroring the Python bot).
- **S6** — `RENEW <IP|host> <duration>` and `EDIT <IP|host> <N> <duration>`
  update an existing entry.
- **S7** — `DEL <IP|host>` removes the entry.
- **S8** — `INFO [IP|host]` shows the entry for the given host or, without
  argument, the entry linked to the caller's nick.
- **S9** — `LIST` shows all active entries.
- **S10** — Every mutation writes `/home/irc/inspircd/conf/clones.conf` (path
  configurable via `DBOTS_CLONES_CONF`) and sends `SIGHUP` to the InspIRCd PID
  read from `DBOTS_INSPIRCD_PID_FILE`.
- **S11** — Expired temporary entries are removed automatically; the daemon runs
  a cleanup pass periodically.

## Tasks

- [x] **T1** (S1, S2) — Introduce `CLoNeS` as a service and wire it into the daemon,
  factory, and help catalog.
- [x] **T2** (S10) — `ClonesExceptionRepositoryInterface` and SQLite implementation.
- [x] **T3** (S3-S9) — `ClonesServCommandHandler` with command parsing and replies in
  Spanish, matching the Python bot wording.
- [x] **T4** (S10) — `InspircdClonesWriter` that renders `<connect>` blocks and
  triggers `SIGHUP`/REHASH.
- [x] **T5** (S11) — Periodic cleanup of expired exceptions.
- [x] **T6** — Unit tests and live verification against InspIRCd.

## Log

- **L1** — User confirmed the Python clones bot should become a dbots service
  "like ChanServ" instead of running as an external bot.
- **L2** — Active bot reference: `/home/juanjo/irc/clones-bot/`, CLoNeS v2.1,
  writes `<connect>` blocks to `/home/irc/inspircd/conf/clones.conf` and reloads
  with `SIGHUP`.
