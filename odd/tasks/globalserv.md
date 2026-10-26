# dbots — GlobalServ

Objective: add the `GlobalServ` network announcement service (`gl.mrc`) with all
its commands.

## Specs

- **S1** — `GlobalServ` is introduced as `<SID>A00010` with realname
  "Network Global Announcement Services" and joins the debug channel with the
  other services when configured.
- **S2** — The dBoTs entry gate was `c.r CA` (Co-Administrador). With that rank
  removed, the gate is **Administrador** (7). Commands that previously required
  Root (`c.r R`) keep that requirement.
- **S3** — `GLOBAL <mensaje>` sends a PRIVMSG from `GlobalServ` to every
  connected user. The message is prefixed with `[ Mensaje Global ]` on its own
  line, followed by the user text.
- **S4** — `NICK <nick> <mensaje>` sends a global message under a temporary nick.
  The nick must not be registered and must not be in use. GlobalServ introduces
  the temporary pseudo-client, sends the two-line message to all users, then
  quits it.
- **S5** — `CHAN <canal> <mensaje>` sends the two-line global message to every
  member of the channel.
- **S6** — `JOIN <canal> <motivo>` (Root only) forces every connected user to
  join the channel via `SVSJOIN`, then sends the two-line message.
- **S7** — `PRE-OPER <mensaje>`, `OPER <mensaje>`, `CO-ADMIN <mensaje>`,
  `ADMIN <mensaje>` send the message only to connected users whose account holds
  at least the corresponding rank. `CO-ADMIN` and `ADMIN` are Root-only; the
  others are Administrador-only.
- **S8** — All commands reply with the original Spanish wording, including
  `Mensaje global enviado correctamente.` and `Número de Impactos: N` for
  broadcast commands.
- **S9** — `HELP`/`AYUDA` renders the GlobalServ help catalog.
- **S10** — Update `docs/commands.md` to move GlobalServ from "Services that do
  not exist yet" to a proper section, and update HeLP's `BOTS` topic if it lists
  services.

## Commands and gates

| Command | Gate | Audience |
|---|---|---|
| `GLOBAL <mensaje>` | Administrador | All connected users |
| `NICK <nick> <mensaje>` | Administrador | All connected users, via temp nick |
| `CHAN <#canal> <mensaje>` | Administrador | Channel members |
| `PRE-OPER <mensaje>` | Administrador | Pre-Operador and above |
| `OPER <mensaje>` | Administrador | Operador and above |
| `CO-ADMIN <mensaje>` | Root | Co-Administrador and above |
| `ADMIN <mensaje>` | Root | Administrador |
| `JOIN <#canal> <motivo>` | Root | All connected users (forced join) |

## Tasks

- [x] **T1** (S1) — Create `GlobalServCommandHandler`, `GlobalServHelp`, and wire
  the service into `Daemon`, `DaemonFactory`, `introduceServices`, and `onPrivmsg`.
- [x] **T2** (S3-S6, S8) — Implement `GLOBAL`, `NICK`, `CHAN`, and `JOIN`.
- [x] **T3** (S7, S8) — Implement `PRE-OPER`, `OPER`, `CO-ADMIN`, and `ADMIN`.
- [x] **T4** (S9) — Add `HELP`/`AYUDA` entries.
- [x] **T5** (S10) — Update `docs/commands.md` and HeLP service list.
- [x] **T6** — Add unit tests for all commands and gates.
- [ ] **T7** — Run `composer check`, commit, and push.

## Risk

- **Medium** — GlobalServ can mass-message or mass-join users. Mitigations:
  rank gates, audit logging through `DebugRelay`, and reusing existing
  `EnforcementInterface`/`NetworkState` helpers.

## Log

- **L1** — User asked to implement GlobalServ with all commands from `gl.mrc`.
