# dbots — Service nickname reservation

Objective: reserve every service pseudo-client nickname on UnrealIRCd 6.2.x so that nobody except the U-lined services server can use them.

## Specs

- **S1** — When the daemon introduces its built-in services (`NiCK`, `CHaN`, `MeMo`, `OPeR`, `CReG`, `HeLP`, `CeNTeR`, `CLoNeS`) and the optional `DeBuG` client, it sends a server-level Q-Line immediately after the `UID` line.
- **S2** — Every module pseudo-client introduced by `ModuleRuntime` gets the same reservation after its `UID` line.
- **S3** — The reservation command is UnrealIRCd `SQLINE`: `:<sid> SQLINE <nick> :Reservado para Servicios`. U-lined servers are still allowed to use the nick, so the service itself is not blocked.
- **S4** — The reservation is sent through the existing S2S enforcement adapter so the wire format lives in one place.

## Tasks

- [x] **T1** (S4) — Add `reserveNick(string $nick, string $reason): void` to `EnforcementInterface` and implement it in `S2sEnforcement` as `SQLINE`.
- [x] **T2** (S1) — Wire `EnforcementInterface` into `Daemon` and reserve each built-in service nick right after `UID`.
- [x] **T3** (S2) — Wire `EnforcementInterface` into `ModuleRuntime` and reserve each module nick right after `UID`.
- [x] **T4** — Update `OperServCommandHandlerTest` fake enforcement for the new interface method.
- [x] **T5** — Add `S2sEnforcementTest` covering the `SQLINE` wire format.
- [x] **T6** — Update `ModuleRuntimeTest` to verify module nicks are reserved.
- [x] **T7** — Run `composer check`, commit, and push.

## Log

- **L1** — User added the requirement: service nicks (`NiCK`, `CHaN`, `MeMo`, `OPeR`, `CReG`, `HeLP`, `CeNTeR`, `CLoNeS`, module nicks, `DeBuG`) must be forbidden/reserved so only the services can use them.
- **L2** — Old UnrealIRCd 3.2.x config used `ban nick { mask "*Nick*"; reason "Reservado para Servicios"; }`; UnrealIRCd 6.2.x equivalent for services is the S2S `SQLINE` command.
