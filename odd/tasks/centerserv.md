# dbots — CeNTeR

Objective: the control service. It manages the network staff ladder and the
module system; nothing else from `ce.mrc` survived the classification below.

## Specs

- **S1** — `CeNTeR` is introduced as `<SID>A00008` and joins the debug channel with
  the other services.
- **S2** — The dBoTs gate was `c.r CA` (Co-Administrador). With that rank removed,
  the gate is **Administrador** (7).
- **S3** — Handing out **Administrador or above**, and loading code, requires
  **Root** (8). You cannot promote yourself to the rank that promotes.
- **S4** — `ROOT <password>` opens a Root session for the sender, which is how the
  first Root exists at all; `ROOT ADD|DEL <nick>` moves somebody on the ladder.
- **S5** — `PREOPER`/`OPER`/`ADMIN` take `ADD|DEL <nick>` and answer with the
  original wording: `<nick> añadido como <rango> de la red.`,
  `<nick> ya es un representante de la red.`, `<nick> no es un <rango> de la red.`
- **S6** — A nick must be registered before it can hold a rank:
  `El nick <nick> no está registrado.`
- **S7** — `REMOPER <nick>` strips whatever rank the account holds.
- **S8** — `MODULO LIST|LOAD|UNLOAD` reads and writes the module state. A change
  applies on the next start; hot loading is a later task.
- **S9** — An IRCd oper with `+o` but no rank cannot use CeNTeR: the original put
  the service above oper level, and so does this.

## Commands that did not come over

| Group | Commands | Why |
|---|---|---|
| Duplicates OPeR | `KILL`, `GLINE`, `CLONES` | OPeR already answers them; two doors to one action is a bug factory |
| mIRC machinery | `MODULO`'s mIRC side, `WMODULO`, `REGENERAR`, `OPTIMIZA`, `UPDATE`, `BACKUP`, `ACTUALIZA`, `TABLA`, `BOT`, `DCLONES`, `DEBUGC`, `FSNOMASK`, `QUIT`, `NORAW` | They drive the mIRC script loader, the bot's ini database, or its process |
| Process lifecycle | `RESTART` | systemd owns the daemon; a service that restarts itself is a footgun |
| Dangerous, decided later | `RAW` | Sends arbitrary lines to the IRCd; needs its own security review |

`PRIVATE`-style toggles and the rest of `ce.mrc`'s dispatch are listed in
`docs/commands.md`.

## Tasks

- **T1** (S1-S7) — **done**. Service, dispatch, ladder commands.
- **T2** (S8) — **done**. `MODULO LIST|LOAD|UNLOAD`, Root-gated.
- **T3** — Hot module loading (apply without restart).
- **T4** — `RAW`, if it is ever wanted, behind Root with an audit trail.

## Log

- **L1** — The dBoTs ladder had five rungs; Co-Administrador was removed by the
  operator, so the gate moved from CA to Administrador and `c.r CA`/`c.r A`
  collapsed into one tier.
- **L2** — Without a rank nobody could use CeNTeR at all, which is why
  `ROOT <password>` exists: it is the way in for the first Root. The dBoTs answer
  was `%conf.root`, a configured nick.
- **L3** — Verified live: `CeNTeR` is on the network next to the other services,
  `HELP` renders, and both `OPER ADD` without a rank and a wrong `ROOT` password
  are refused.
