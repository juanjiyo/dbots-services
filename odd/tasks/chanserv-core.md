# dbots — ChanServ core

Objective: register channels through ChanServ so UDB owns their lifecycle: the
founder keeps `+q` and the channel carries `+r`.

## Specs

- **S1** — `CHANSERV REGISTER <#channel>` (alias `REGISTRA`) registers the channel with the sender's account as founder.
- **S2** — Only an identified sender can register: the sender must carry `+r` and their nick must exist in the account store. A client UID never carries the account name, so identity is derived, never trusted from the frame.
- **S3** — A channel is registered once; names are compared case-insensitively and stored lowercased.
- **S4** — `CHANSERV INFO <#channel>` reports founder and registration date.
- **S5** — `CHANSERV DROP <#channel>` works only for the founder and removes the profile.
- **S6** — Registration and drop are projected to UDB immediately (`INS`/`DEL` on `C::<channel>::founder`), so the IRCd applies or removes `+q` and `+r` on the live channel.
- **S7** — Channel records never include the block letter in their path, matching the N block convention.

## Tasks

- **T1** (S3) — `ChannelName`, `Channel` domain objects.
- **T2** (S1, S3, S4, S5) — `RegisterChannelHandler`, `DropChannelHandler`.
- **T3** (S3) — `SqliteChannelRepository`.
- **T4** (S2) — Network state tracks user modes; `UMODE2`/`MODE` update them.
- **T5** (S1, S4, S5) — `ChanServCommandHandler`.
- **T6** (S6, S7) — `ChannelBlockProvider`, `UdbChannelProjection`, `CompositeBlockProvider`.
- **T7** — Wiring: second pseudo-client (`<sid>A00002`) and per-service reply sender.
- **T8** — 8 unit tests; 56 in the suite.

## Log

- **L1** — Continued autonomously from the NickServ milestone.
- **L2** — Two identity bugs found by running it. First, the channel founder was stored as `fwAAAQ==`: the 11th field of a client `UID` is the base64 IP, not the account. Second, `+r` arrives *after* the introduction as `:nick UMODE2 +r`, so a snapshot of modes at join time misses identification. Fixed by tracking modes and resolving identity from `+r` plus our own account store.
- **L3** — Verified live: `REGISTER #dbtest` produces `MODE #dbtest -o FreshUser`, `MODE #dbtest +r`, `MODE #dbtest +q FreshUser`, and the founder keeps `+q` when rejoining later.
- **L4** — Verified live: `DROP #dbtest` produces `MODE #dbtest -q FreshUser` and `MODE #dbtest -r`, and the profile disappears from SQLite.
- **L5** — Debug lines are gated behind `DBOTS_DEBUG=1` so the unit stays quiet.
- **L6** — Added `ACCESS LIST|ADD|DEL` and `TOPIC`. Access exports `#channel::access::nick` (UDB's JOIN hook only checks the child exists and the user has +r) and the topic exports `#channel::topic`. Dropping a channel also removes its access rows and topic record.
- **L7** — Verified live: `ACCESS ADD`/`LIST`/`TOPIC` reply correctly and `channel.get` reports topic "Bienvenidos al canal" set by ChanServ with modes `nrt`.
