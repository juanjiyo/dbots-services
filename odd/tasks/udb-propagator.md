# dbots — UDB 4 propagator

Objective: make dbots the UDB authority so the IRCd authenticates with `NICK nick:password`.
Source of truth: `davidlig/unrealircd-udb`, `doc/udb_technical_en.md` and `tests/support/udb_test_support/native_reference.py`.

## Specs

- **S1** — HEL 4 negotiation: answer `DB <us> HEL 4 <selector> <epoch> OCL` with `ACK <our-name> <our-epoch> OCL`, where the epoch is exactly 16 lowercase hex characters.
- **S2** — When the peer selects us as propagator (`selector == our server name`) or asks for bootstrap (`selector == "?"`), announce `INF` for the six blocks (N, C, I, S, L, K) with count, canonical digest, watermark, root SID and root epoch.
- **S3** — On `RES <round> <block>`, serve the block as `BEGIN` / `PUT`* / `END`, all carrying the block digest and the current watermark.
- **S4** — Record paths include the block letter as their first component (`N::alice::pass`); the digest hashes that exact path.
- **S5** — Canonical block digest is byte-compatible with the module's reference implementation, including empty blocks.
- **S6** — Live mutations: `INS <epoch> <seq> <Block::path> :<typed-value>` with a strictly monotonic sequence shared across blocks.
- **S7** — A registered account is projected as `N::<nick>::pass = crypt:<bcrypt hash>`, which is the credential form the IRCd verifies for `NICK nick:password`.
- **S8** — Reconciliation round identifiers must be strictly monotonic across reconnects: never reuse a round id, even if two links start in the same second.

## Tasks

- **T1** (S5) — `NativeDigest` port. Verified against the Python reference: 12/12 digests identical. ✅
- **T2** (S1, S2, S3, S6) — `UdbAuthority` state machine. ✅
- **T3** (S7) — `AccountBlockProvider`, `UdbAccountProjection`. ✅
- **T4** — Wiring in `Kernel` and `Daemon`. ✅
- **T5** — 7 unit tests for the authority (41 total in the suite). ✅
- **T6** (S2) — Offer inventory also when the peer sends `HEL 4 ?` (bootstrap seeker), not only when it names us directly. ✅
- **T7** (S8) — Use a strictly monotonic round id in `UdbAuthority::announceInventory` so reconnects in the same second cannot collide. ✅
- **T8** — Verify the changes against the live IRCd and the unit suite. ✅

## Log

- **L1** — User: "haz todo" — build the whole thing including native login.
- **L2** — First probe failed because the epoch was 8 hex characters; the module silently ignores malformed HEL. With 16 hex the IRCd logs `UDB_HEL_CONFIRMED`.
- **L3** — Captured live: `HEL 4 -` → ACK → `HEL 4 bots.sitiochat.es` (selection) → INF×6 → `RES 1 N/C/S` → BEGIN/PUT/END → the IRCd ACKs each block. This is the first end-to-end confirmation that dbots can act as the authority.
- **L4** — The IRCd only requests N, C and S; I, L and K are local to the node.
- **L7** — Bug found by the live run: replies were addressed to `params[0]` (the target, i.e. ourselves) instead of the frame's source. The IRCd therefore never saw the HEL ACK and aborted the link after 60 s with `UDB_HEL_TIMEOUT`. Fixed by replying to the sender SID; the probe had masked it because it used the SID captured from PROTOCTL.
- **L8** — **Verified end to end:** after the fix, the IRCd logs `UDB_HEL_CONFIRMED` and starts reconciliation. A client registering through NickServ is logged in natively (`900 ... You are now logged in as DBUser`), and a second connection with `NICK DBUser:hunter2` authenticates without IDENTIFY: `user.get` reports `account: "dbuser"`, `modes: irwxT` (+r) and `security-groups: ["known-users"]`.
- **L5** — (superseded by L7/L8) The node did not reach READY while our replies were misaddressed. After the first authority disconnect it logged `UDB_NOT_READY_DENY_CLIENT` and stopped issuing HEL on the next link, so live native-auth could not be confirmed. Needs a clean IRCd restart to continue; restarting drops connected users, which is the user's call.
- **L9** — Second bug: record paths must NOT include the block letter. The module parses `path[0]` as the block and starts components at `path[3]`, and the reference digests `{nick}::pass` while the wire carries `N::{nick}::pass`. Hashing the letter as a component matched for empty blocks by accident and was rejected (`storage result -5`) on non-empty ones.
- **L10** — Verified again with the corrected digests: a non-empty N block reconciles, the S service mask is accepted, and a fresh registration plus `NICK nick:password` both work (`registro=True login_nativo=True`).
- **L11** — Deployed as a user systemd unit `dbots.service` with `EnvironmentFile=~/.config/dbots/env` and `Restart=always`; lingering enabled so it survives logout. The IRCd keeps `udb { propagator "bots.sitiochat.es"; }` active, so the fail-closed admission only triggers if dbots is down.
- **L12** — User: "Revisa Ares (que está en /opt/ares) para ver como se conectan estos services, que comandos envian, cuales reciben (de UDB) y así podemos aplicar fixes a dbots". Compared Ares' `UnrealUdbProtocolHandler`/`UdbSessionCoordinator` with dbots' `UdbAuthority`. Two reliability gaps identified: dbots only announces inventory when the peer names us explicitly (Ares also responds to `?`), and dbots uses `time()` as the round id (Ares keeps a monotonic counter). No changes needed for NETINFO/EOS (already handled by `Handshake`) or for the ACK propagator (both echo the services name).
- **L6** — (historical) While unverified, the local IRCd was returned to standalone authority mode (`udb { }`) so clients can connect. The propagator line stays commented until the restart test.
