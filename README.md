# dbots

IRC services for UnrealIRCd 6 built as a UDB authority, in PHP, with no runtime
dependencies beyond the standard library.

The daemon links to the IRCd as a services server (S2S), takes ownership of the
distributed user database (UDB 4) and answers `NickServ` commands. Because UDB
owns the accounts, authentication is native:

```text
/NICK alice:password
```

There is no IDENTIFY step: the IRCd validates the credential against the profile
dbots publishes and applies the account and `+r` itself.

## Status

| Piece | State |
|---|---|
| S2S link (HEL 4, EOS, sync) | Working, verified live |
| UDB 4 authority (HEL/INF/RES/BEGIN/PUT/END) | Working, verified live |
| Canonical block digests | Verified byte-for-byte against the module reference |
| NickServ `REGISTER` / `IDENTIFY` / `INFO` / `SET PASSWORD` / `DROP` | Working |
| ChanServ `REGISTER` / `INFO` / `DROP` / `ACCESS` / `TOPIC` | Working, verified live |
| Native login (`NICK nick:password`) | Working, verified live |
| MemoServ `SEND` / `LIST` / `READ` / `DELETE` | Working, verified live |
| OperServ `KILL` / `KICK` / `OP` / `DEOP` / `MODE` / `GLINE` / `UNGLINE` / `RENAME` / `DROPNDB` / `APODERA` / `LISTCLONES` / `KILLCLONES` / `BLOCK` / `LIMPIA` / `SETTIME` / `ROOT` / `STATS` / `IRCOPS` | Working, verified live |
| OperServ `SPAM` | Stored only: the rule never becomes an IRCd filter (see README note) |

## Operations

The daemon runs as a user systemd unit.

```bash
systemctl --user status dbots        # state
systemctl --user restart dbots       # apply a code change
tail -f var/dbots.log                # output
```

Configuration lives in `~/.config/dbots/env` (mode 600):

| Variable | Meaning |
|---|---|
| `DBOTS_LINK_NAME` | Server name presented to the IRCd; must match a `link` block and `ulines` |
| `DBOTS_LINK_SID` | Server ID; unique on the network (`^[0-9][0-9A-Z]{2}$`) |
| `DBOTS_LINK_HOST` / `DBOTS_LINK_PORT` | S2S endpoint (TLS) |
| `DBOTS_LINK_PASSWORD` | Password of the `link` block |
| `DBOTS_LINK_TLS_VERIFY` | `0` for a self-signed certificate |
| `DBOTS_DATABASE` | SQLite path for accounts |
| `DBOTS_RECONNECT_DELAY` | Seconds between relink attempts |

The IRCd side needs:

```text
link bots.sitiochat.es {
    incoming { mask 127.0.0.1; }
    password "change-me";
    class servers;
}

ulines { bots.sitiochat.es; }

udb {
    propagator "bots.sitiochat.es";
};
```

UDB admission is fail-closed: while dbots is not linked, the IRCd refuses new
clients with `UDB initialization pending`. Keep the unit enabled before pointing
`udb::propagator` at it.

## Verifying a deployment

```bash
# 1. the link and the capability negotiation
grep UDB_HEL_CONFIRMED /opt/unrealircd/logs/ircd.log | tail -1

# 2. a fresh account, from an IRC client
/msg NickServ REGISTER <password>

# 3. native login from a new connection
/NICK <nick>:<password>
# expect: 900 ... You are now logged in as <nick>

# 4. the account is really bound
curl -sk -u 'rpc-user:password' https://127.0.0.1:8600/api \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"user.get","params":{"nick":"<nick>"},"id":1}'
# expect account != null and modes containing r
```

## Architecture

```text
src/
├── Domain/Link/            ServerLink, LinkState
├── Application/
│   ├── Link/               Handshake (pure state machine), LinkRunner
│   ├── Port/Out/           LinkTransportInterface
│   └── Daemon.php          line dispatch: UID/NICK/QUIT, DB frames, PRIVMSG
├── Adapter/Out/Transport/  TlsStreamTransport
├── Irc/
│   ├── Adapter/In/         IrcMessage, IrcMessageParser
│   ├── Adapter/Out/Irc/    ServiceUidFormatter
│   └── Application/        NetworkState (UID -> nick)
├── ChanServ/
│   ├── Adapter/In/Irc/     ChanServCommandHandler
│   ├── Adapter/Out/        SQLite channels
│   └── Application/        RegisterChannel, DropChannel + ports
├── OperServ/
│   ├── Adapter/In/Irc/     OperServCommandHandler
│   ├── Adapter/Out/Irc/    KILL / SAKICK enforcement
│   └── Application/Port/   EnforcementInterface
├── MemoServ/
│   ├── Adapter/In/Irc/     MemoServCommandHandler
│   ├── Adapter/Out/        SQLite memos
│   └── Application/        SendMemo, ReadMemo, DeleteMemo + port
├── NickServ/
│   ├── Adapter/In/Irc/     NickServCommandHandler
│   ├── Adapter/Out/        SQLite accounts, SVSLOGIN, NOTICEs
│   └── Application/        RegisterNick, Identify + ports
└── Udb/
    ├── NativeDigest.php    canonical block digest (port of the module reference)
    ├── UdbAuthority.php    HEL/INF/RES/BEGIN/PUT/END + live INS
    ├── AccountBlockProvider.php    N and S blocks
    ├── ChannelBlockProvider.php    C block
    ├── CompositeBlockProvider.php  one six-block view
    └── UdbAccountProjection.php
```

The handshake is a pure state machine fed with lines, so the protocol is tested
without sockets. Only `TlsStreamTransport` knows about sockets, and only
`UdbAuthority` knows about UDB framing.

## Operator commands

```text
/msg OperServ KILL <nick> [reason]
/msg OperServ KICK <#channel> <nick> [reason]
/msg OperServ STATS
/msg OperServ IRCOPS
/msg OperServ OP|DEOP <#channel> <nick>
/msg OperServ MODE <#channel> <modes> [arg]
/msg OperServ GLINE <user@host> <duration> [reason]
/msg OperServ UNGLINE <user@host>
/msg OperServ RENAME <nick> [newnick]
/msg OperServ DROPNDB <nick>
/msg OperServ APODERA <#channel> [nick]
/msg OperServ LISTCLONES <nick>
/msg OperServ KILLCLONES <nick> [reason]   (G-Lines the host for 60m)
/msg OperServ BLOCK <nick> [reason]        (G-Lines the host for 300m)
/msg OperServ LIMPIA <#channel>
/msg OperServ ROOT <password>              (grants SETTIME)
```

`SPAM` keeps rules in SQLite and replies that enforcement is not active. The six
`K::F` records do reach the IRCd, but the module does not materialise them as a
filter, so the command says so instead of implying protection.

Clone commands G-Line the host the nick is connected from, and refuse to do so
when the host is protected (`localhost`, `127.0.0.1`), so an operator testing
from the same machine cannot ban the machine.

Durations: `0` is permanent, a bare number means minutes, and `30m`, `2h`,
`1d`, `1w` are accepted. G-Lines go out as UnrealIRCd `TKL`, so the IRCd stores
and enforces them like any other server ban.

Mutating commands require user mode `+o` on the connection. Oper status is read
from the connection itself, never inferred from a nickname or an account.

## Memos

```text
/msg MemoServ SEND <nick> <message>
/msg MemoServ LIST
/msg MemoServ READ <number>
/msg MemoServ DELETE <number>
```

Memos belong to the account, not the session, and have no UDB projection: there
is no memo block, so they never enter the replicated database.

## Channel registration

```text
/msg ChanServ REGISTER #channel     (you must be identified)
/msg ChanServ INFO #channel
/msg ChanServ DROP #channel         (founder only)
/msg ChanServ ACCESS LIST|ADD|DEL <#channel> [nick]
/msg ChanServ TOPIC <#channel> <text>
```

Access entries are exported to the C block as `#channel::access::nick`; UDB's JOIN
hook uses their presence, so a listed account with `+r` passes the registered-channel
restrictions. The topic is exported as `#channel::topic` and applied by the IRCd.

The founder comes from the sender's account, and the profile is projected to the
C block, so UDB applies `+q` to the founder and `+r` to the channel immediately.
Dropping removes both.

Note that identity is derived from `+r` plus the account store: a client `UID`
frame carries the user modes and the IP, never the account name, and `+r` arrives
after the introduction as `UMODE2`.

## Tests

```bash
vendor/bin/phpunit --testsuite unit          # 46 tests, no network
DBOTS_LINK_PASSWORD=... vendor/bin/phpunit --group integration
```

The integration suite links to a live IRCd and asserts the link syncs.

## Design decisions

- **S2S authority, not client bots.** The basic services need server powers
  (modes without `+o`, account management, forced nick changes). A daemon that
  links as a server has them; an mIRC-style bot does not.
- **No C.** The socket and the protocol are PHP. C only earns its place inside
  the IRCd, and that would be a module, not a service.
- **One translation resolver, interfaces over `method_exists`.** The conventions
  that keep this codebase from repeating the duplication seen elsewhere.
