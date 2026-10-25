# dbots — Pulsar OperServ commands

Objective: bring the AdminServ/OperServ commands from Pulsar that are not already
covered by CeNTeR or existing OPeR commands into `OPeR`.

## Specs

- **S1** — `JAIL <nick>` (OperServ main.c:1485). Requires the sender to carry
  user mode `+o`. It removes the target from every channel with `SVSPART`, sets
  mode `+C` with `SVSMODE`, and forces them into the configured jail channel with
  `SVSJOIN`. The jail channel is read from `DBOTS_JAIL_CHANNEL`.
- **S2** — `UMODE <nick> +/-modos` (AdminServ main.c:703). Requires `+o`. Sends
  `SVSMODE` for the target. The modes `r` and `h` are rejected.
- **S3** — `ELINE ADD|DEL|LIST|VIEW|COUNT <mask|num> ...` (AdminServ elines.c:57).
  Requires `Administrador` (CeNTeR rank). Manages E-Line exceptions sent to the
  IRCd as `TKL + E <user> <host> <sid> <expire> <set-at> :<reason>`. Stored in
  SQLite so the list survives restart.
- **S4** — `SERVERMAP` (AdminServ main.c:482). Requires `Administrador`. Prints
  the server tree using the network state built from `SERVER` and `SJOIN` lines.
- **S5** — `CONEXIONES LIST <min>` and `CONEXIONES VIEW <host>` (AdminServ
  sessions.c:106). Requires `Administrador`. Lists hosts with at least `min`
  connected users or shows the count and clone limit for one host.

## Tasks

- **T1** (S1) — `JAIL` command in OPeR, including `EnforcementInterface` methods
  for `svsJoin`, `svsPart`, and `userMode`.
- **T2** (S2) — `UMODE` command in OPeR.
- **T3** (S3) — `ELINE` repository, command, and TKL wire format.
- **T4** (S4) — `SERVERMAP` command.
- **T5** (S5) — `CONEXIONES` command.
- **T6** — Update help text, docs, and tests for all of the above.

## Log

- **L1** — User asked to integrate Pulsar AdminServ and Jail commands. Most
  AdminServ commands (`ADMIN`, `OPER`, `UPDATE`, `RESTART`, `SHUTDOWN`, `SETTIME`)
  are already handled by CeNTeR/OPeR or deliberately out of scope.
- **L2** — `CLONES ADD|DEL|LIST` was excluded: the standalone CLoNeS bot already
  manages clone exceptions and will be brought into dbots as a service.
