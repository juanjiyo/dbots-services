# dbots — OPeR INVITE command

Objective: add the `INVITE <#canal>` command to `OPeR`, matching the original
`op.mrc` behaviour.

## Specs

- **S1** — `/msg OPeR INVITE <#canal>` invites the sender to the given channel.
- **S2** — The command requires the sender to be an IRC operator (`+o`), like
  the other OPeR oper commands.
- **S3** — The service sends `:<uid> INVITE <senderNick> <#canal>` over the S2S
  link, the same wire form the original `op.mrc` used.
- **S4** — The command appears in `OPeR HELP`.
- **S5** — `docs/commands.md` marks `invite` as done.

## Tasks

- [x] **T1** (S3) — Add `invite(string $targetNick, string $channel): void` to
  `EnforcementInterface` and implement it in `S2sEnforcement`.
- [x] **T2** (S1, S2) — Add `onInvite` to `OperServCommandHandler`.
- [x] **T3** (S4) — Add the help entry.
- [x] **T4** (S5) — Update `docs/commands.md`.
- [x] **T5** — Unit tests.
- [ ] **T6** — Run `composer check`, commit, and push.

## Log

- **L1** — User wants every remaining `todo` item done except ChanFix; OPeR
  `INVITE` is the smallest of them.
