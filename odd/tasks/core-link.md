# dbots — core link (S2S)

Objective: build a dBoTs-style services family on an Ares-shaped architecture (PHP, hexagonal), independent of any upstream.
Branch: `main` (initial work unit). Delivery: `single-pr` until the repo has more than one contributor.
Test runner: `vendor/bin/phpunit` (unit), `vendor/bin/phpunit --group integration` (live IRCd).

## Specs

- **S1** — The daemon links to UnrealIRCd 6 as a services server over TLS on the S2S port, using `PASS`, `PROTOCTL EAUTH=<name> SID=<sid>`, `PROTOCTL <capabilities>` and `SERVER <name> 1 :<description>`, in that order.
- **S2** — The daemon answers `PING <token>` with `:<sid> PONG <token>`, keeping the link alive.
- **S3** — The daemon answers `NETINFO` with `NETINFO 0 <ts> 6100 * 0 0 0 :<network>`.
- **S4** — On the remote `EOS` the daemon sends its own `:<sid> EOS` and reports the link as synced.
- **S5** — Any `ERROR` line from the IRCd moves the link to a failed state carrying the IRCd's message; the daemon never reports synced on a failed link.
- **S6** — The link configuration validates the server name and SID (`^[0-9][0-9A-Z]{2}$`) and rejects an empty password, failing at construction time rather than at connect time.
- **S7** — The handshake is transport-independent: it is driven by lines in and lines out, so it is unit-testable with no socket.

## Tasks

- **T1** (S6) — `ServerLink` value object with validation. inline.
- **T2** (S1, S2, S3, S4, S5, S7) — `Handshake` state machine: start, consume, flush. inline.
- **T3** (S1) — `TlsStreamTransport` adapter behind `LinkTransportInterface`. inline.
- **T4** (S1) — `LinkRunner` + `bin/dbots-link` entrypoint. inline.
- **T5** (S1-S7) — Unit tests for handshake and value object; opt-in integration test against a live IRCd. inline.

## Log

- **L1** — User: "Lo primero sería arrancar con OperServ, ChanServ, etc. Es decir, los básicos" / "quiero intentar hacer unos dbots como Ares, es decir, PHP, C y tal" / "Haz lo que tengas que hacer".
- **L2** — Decision: S2S services daemon (not mIRC-style client bots), because the basic services need server authority (modes, UID management, NK enforcement). C is deliberately absent: the socket work is PHP.
- **L3** — Spike `/tmp/opencode/s2s-proto/probe.php` proved the handshake against the live local UnrealIRCd 6.2.7: link synced (`SLOG info link SERVER_SYNCED`) and a pseudo-client introduced via `UID` appeared in `user.list` as `003AAAAAA` on `bots.sitiochat.es`.
- **L4** — Enabling change made to the local IRCd: `link bots.sitiochat.es` now also accepts `127.0.0.1` (`conf/unrealircd.conf`, backup at `/tmp/opencode/unrealircd.conf.bak-*`). `bots.sitiochat.es` was already in `ulines`.
- **L5** — UID field order captured from the live burst and mirrored from the IRCd itself, not from documentation: `UID <nick> 0 <ts> <ident> <host> <uid> 0 <modes> <vhost> <cloak> <account> :<realname>`.
