# 2026-10-25 — clients refused after an IRCd restart

Status: **partially resolved**. One real bug fixed; one symptom still unexplained
and only reproducible from the services host.

## Symptom

After restarting UnrealIRCd, new clients were refused. Two different errors
appeared depending on how fast the client spoke:

- `451 * :You have not registered` when `NICK`/`USER` were sent immediately after
  the TCP connect.
- `ERROR :Closing Link: (UDB synchronization unavailable; this server is
  temporarily not accepting new connections)` when the client paused first.

Existing sessions kept working, and every service stayed linked the whole time.

## What was ruled out, and how

| Suspicion | How it was ruled out |
|---|---|
| UDB not ready | `/UDB STATUS` from an oper: `READY`, `OK`, `ADMISSION ALLOWED` |
| `maxperip` | Raised to 9999 for `127.0.0.1` **and applied with a real rehash**; still refused |
| `connect-flood` | Still refused after 60 s of total silence |
| TLS vs. plaintext | Same refusal on 6667 and 6697 |
| Per-IP throttling | Same refusal from all three host addresses (127.0.0.1, LAN, tailscale) |
| Spamfilters | None of the configured rules match `NICK` or `USER` |
| Bans | No trace in the log, and the module reported admission as allowed |

## Root cause found: an epoch per reconnect

`RunCommand` builds a new `Daemon` on every retry, and `DaemonFactory` built a new
`UdbAuthority` with a **fresh epoch each time**. UDB reads the epoch as the
authority instance's identity, so after any link drop the IRCd was negotiating
with what looked like a different authority: `HEL` confirmed, `INF` announced,
`RECONCILE_ROUND_START`, and then nothing. It never reached `READY` on its own and
the node went stale after the 300 s `stale-timeout`, which is when admission is
refused.

Fixed in `33f14e8`: the epoch belongs to the factory instance, which lives as long
as the process, so every reconnect within one run presents the same identity.

## Still open

Connections **originating from the services host itself** are refused, with any
source address, and the IRCd logs no `LOCAL_CLIENT_CONNECT` for them at all. The
same IRCd accepts clients from the LAN and from mobile, and reports admission as
allowed. So the refusal happens before the connect is logged, for a host that is
also the linked services server.

Next place to look: the module's pre-registration hook (the one that can deny a
local client before the connect log), and whether the link's peer identity is
leaking into client admission for the peer's own address.

## Lessons

- **`/UDB STATUS` is the source of truth**, not inference from log silence.
- **A client must wait ~2 s after connecting** before sending `NICK`/`USER`, or
  the IRCd answers `451`. Several hours were spent chasing a fault that was the
  probe racing registration.
- **Do not debug this with a stream of connections.** The evidence gets muddier
  with every attempt, and limits from different layers (`maxperip`,
  `connect-flood`, registration timing) all look alike from the outside.
- Restarting the IRCd is what surfaced all of this: it had been up for days, so
  the epoch bug had never been exercised.
