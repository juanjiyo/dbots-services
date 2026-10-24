# dbots — UDB 4 propagator

Objective: make dbots the UDB authority so the IRCd authenticates with `NICK nick:password`.
Source of truth: `davidlig/unrealircd-udb`, `doc/udb_technical_en.md` and `tests/support/udb_test_support/native_reference.py`.

## Specs

- **S1** — HEL 4 negotiation: answer `DB <us> HEL 4 <selector> <epoch> OCL` with `ACK <our-name> <our-epoch> OCL`, where the epoch is exactly 16 lowercase hex characters.
- **S2** — When the peer selects us as propagator (`selector == our server name`), announce `INF` for the six blocks (N, C, I, S, L, K) with count, canonical digest, watermark, root SID and root epoch.
- **S3** — On `RES <round> <block>`, serve the block as `BEGIN` / `PUT`* / `END`, all carrying the block digest and the current watermark.
- **S4** — Record paths include the block letter as their first component (`N::alice::pass`); the digest hashes that exact path.
- **S5** — Canonical block digest is byte-compatible with the module's reference implementation, including empty blocks.
- **S6** — Live mutations: `INS <epoch> <seq> <Block::path> :<typed-value>` with a strictly monotonic sequence shared across blocks.
- **S7** — A registered account is projected as `N::<nick>::pass = crypt:<bcrypt hash>`, which is the credential form the IRCd verifies for `NICK nick:password`.

## Tasks

- **T1** (S5) — `NativeDigest` port. Verified against the Python reference: 12/12 digests identical.
- **T2** (S1, S2, S3, S6) — `UdbAuthority` state machine.
- **T3** (S7) — `AccountBlockProvider`, `UdbAccountProjection`.
- **T4** — Wiring in `Kernel` and `Daemon`.
- **T5** — 7 unit tests for the authority (41 total in the suite).

## Log

- **L1** — User: "haz todo" — build the whole thing including native login.
- **L2** — First probe failed because the epoch was 8 hex characters; the module silently ignores malformed HEL. With 16 hex the IRCd logs `UDB_HEL_CONFIRMED`.
- **L3** — Captured live: `HEL 4 -` → ACK → `HEL 4 bots.sitiochat.es` (selection) → INF×6 → `RES 1 N/C/S` → BEGIN/PUT/END → the IRCd ACKs each block. This is the first end-to-end confirmation that dbots can act as the authority.
- **L4** — The IRCd only requests N, C and S; I, L and K are local to the node.
- **L5** — **Open:** the node does not reach READY. After the first authority disconnect it logged `UDB_NOT_READY_DENY_CLIENT` and stopped issuing HEL on the next link, so live native-auth could not be confirmed. Needs a clean IRCd restart to continue; restarting drops connected users, which is the user's call.
- **L6** — While unverified, the local IRCd was returned to standalone authority mode (`udb { }`) so clients can connect. The propagator line stays commented until the restart test.
