# dbots — staff ladder

Objective: bring back the network staff ladder the dBoTs kept per representative,
so the services stop pretending that "IRCop" is the only kind of staff.

## Specs

- **S1** — Ranks are Pre-Operador (4), Operador (5), Administrador (7) and Root
  (8), on the original dBoTs scale. **Co-Administrador was removed by decision.**
- **S2** — A rank is stored per account, not per nick, so it survives a nick change
  and cannot be borrowed by holding somebody's nickname.
- **S3** — "Operator" means Operador or above; that is the `$s.n(nick) >= 5` the
  original compared against in ChanServ and CReG.
- **S4** — A representative counts as an operator even without IRC user mode `+o`,
  and an IRCd oper with `+o` keeps counting even without a rank. Both paths stay,
  because the IRCd can grant oper status on its own.
- **S5** — In ChanServ, reaching Operador gives channel authority 500: the same as
  the founder, which is what the original did with `$s.n(...) >= 5`.
- **S6** — The ladder is network-wide state, not channel state: it does not live in
  the UDB channel block and is not published to the IRCd.

## Tasks

- **T1** (S1, S2) — **done**. `StaffRank`, repository, SQLite table `staff_ranks`.
- **T2** (S3, S4, S5) — **done**. `StaffAuthority` wired into ChanServ and CReG.
- **T3** (S6) — Commands to manage it (`PREOPER`, `OPER`, `ADMIN`, `REMOPER`,
  `ROOT`) land with CeNTeR.

## Log

- **L1** — The ladder closes a fudge: CReG required "level 5" and ChanServ exempted
  `$s.n >= 5`, but nothing stored a level, so both were standing in for IRC user
  mode `+o`.
- **L2** — `+o` still counts as operator. Dropping it would have taken staff powers
  away from every oper the IRCd already knows.
