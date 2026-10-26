# dbots — CHaN DELACCESS

Objective: implement `CHaN DELACCESS <#canal>` so a user can remove themselves
from a channel's access list without needing `ACC-CHANGE` permission.

## Specs

- **S1** — `/msg CHaN DELACCESS <#canal>` removes the sender's account from the
  channel access list.
- **S2** — The command requires the user to be identified.
- **S3** — The channel must exist and not be suspended.
- **S4** — The sender must be present in the channel access list.
- **S5** — If the sender's access level is `-1` (NOJOIN), only the founder can
  remove it; a non-founder is denied.
- **S6** — Help entries and `docs/commands.md` updated.
- **S7** — Unit tests cover success, not-identified, not-in-list, suspended
  channel, and NOJOIN restriction.

## Tasks

- [x] **T1** (S1-S5) — Add `DELACCESS` dispatch to `ChanServCommandHandler`.
- [x] **T2** (S6) — Update `ChanServHelp` and `docs/commands.md`.
- [x] **T3** (S7) — Add unit tests.
- [x] **T4** — Run `composer check`, commit, and push.

## Log

- **L1** — User clarified `VIEWIP` comment was about `VIEWIP`, not `DELACCESS`.
- **L2** — `DELACCESS` stays in CHaN as a self-service command.
