# dbots — modules

Objective: let anybody add a bot to the network without writing a separate
daemon. A module is a directory under `modules/` that the services daemon loads,
introduces as its own pseudo-client and routes messages to, the way `.mrc` files
were loaded and unloaded in the mIRC generation (`sistema/modulos/<name>`).

## Specs

- **S1** — A module is a directory `modules/<Name>/` containing `module.json` with
  `class`, `nick`, `description` and optional `enabled_by_default`.
- **S2** — A module implements `Dbots\Module\ModuleInterface`: it declares the nick
  it speaks as and receives every parsed message plus the network state and a
  context with its own UID and the transport.
- **S3** — The daemon introduces each enabled module as a pseudo-client with its
  own UID, allocated after the built-in services, and joins the debug channel with
  the rest when the relay is on.
- **S4** — Messages addressed to the module's nick or UID are routed to it; every
  message is also offered to it through the generic hook, so a module can watch
  the network without owning a nick.
- **S5** — Enabled state lives in SQLite (`modules(name, enabled)`), seeded from
  `enabled_by_default` on first sight, so a module can ship enabled or disabled.
- **S6** — `MODULO LIST|LOAD|UNLOAD` in `CeNTeR` reads and writes that state and
  reports what is available on disk. A change applies on the next start; hot
  loading is deliberately out of scope for the first version.
- **S7** — Modules are PHP running inside the daemon: loading one is trusting it
  with the process. Only Root-level staff may enable one, and the documentation
  must say so plainly.
- **S8** — A module that throws must not take the link down: the daemon catches,
  logs and keeps serving.

## Tasks

- **T1** (S1, S2) — **done**. `ModuleInterface`, `ModuleContext`, manifest.
- **T2** (S3, S4, S5) — **done**. Registry, SQLite state, introduction and routing.
- **T3** (S6) — **done**. `CeNTeR MODULO LIST|LOAD|UNLOAD`; hot loading applies the change immediately.
- **T4** (S7, S8) — **done**. A module that throws is caught and logged; the
  trust warning is in `docs/installation.md`.
- **T5** — **done**. `modules/Ping` answers `PING` and proves the path live.

## Log

- **L1** — Requested by the operator: instead of writing a new bot in Python for
  every idea, a module should be loadable the way `.mrc` modules were. The mIRC
  generation had eleven of them (`away`, `bautista`, `charlas`, `docking`, `link`,
  `number`, `quotes`, `rrusa`, `ubot`, `uptime`, `vserv`), loaded by
  `CeNTeR MODULO`.
- **L2** — Trust is the whole design: a module is not a sandbox. It runs in the
  daemon process with its privileges, so the gate is Root and the documentation has
  to say it without softening.

- **L3** — Verified live: `PiNG` appears in `user.list` next to the six services
  and answers `PRIVMSG PiNG :hola` with `PONG HOLA`. The first attempt answered
  nothing because the IRCd addresses a service by UID, not by nick; the fix is
  `ModuleContext::isAddressedTo()`, so no module author has to learn that the hard
  way.
- **L4** — Hot loading is handled by `ModuleRuntime`: the registry still owns the
  on-disk list and persisted state, but the runtime owns the live set, allocates
  stable UIDs, and sends UID/JOIN or QUIT when `refresh()` is called from
  `CenTeRServCommandHandler`.
- **L5** — Added the module trust warning to `docs/installation.md`: modules run
  inside the daemon process and require Root-level staff to enable.
