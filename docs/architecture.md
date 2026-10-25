# Architecture

dbots is a daemon, not a web application: it links to an IRCd as a server,
replicates the network user database and answers IRC commands from pseudo-clients
called `NiCK`, `CHaN`, `MeMo`, `OPeR`, `CReG` and `HeLP`.

## Why a daemon and not client bots

The original dBots were mIRC scripts: they connected as normal users. That works
until a service needs authority — setting a channel mode without `+o`, forcing a
nick change, managing accounts, banning a host. A client bot has to ask; a
server is obeyed.

So dbots links as a ULine server over the S2S protocol and, more importantly,
becomes the **UDB propagator**: the module that owns the network database and
feeds it to the IRCd. That is what makes `NICK nick:password` authenticate
natively, with no IDENTIFY command in the middle.

## Layers

```text
Adapter/In/Irc      IRC command handlers, one directory per service
Application         use cases: register, drop, send memo, suspend...
Domain              entities and value objects: Nick, Password, Channel, Memo, SpamRule
Adapter/Out         SQLite repositories, S2S enforcement, UDB projection
Application/Port    the interfaces those adapters implement
```

Nothing in `Domain` or `Application` knows about sockets, SQL or IRC framing.
That is what lets the protocol be tested without a network.

## The two flows

**A command arrives** (`Daemon::onLine`):

```text
line -> IrcMessageParser -> Daemon
                              |- UID / NICK / QUIT  -> NetworkState
                              |- ACCOUNT / UMODE2   -> NetworkState (identity)
                              |- DB                 -> UdbAuthority
                              '- PRIVMSG            -> the four command handlers
```

Handlers resolve the sender's identity through `AccountResolver`: it requires
user mode `+r` **and** a registered account for the current nick. It never trusts
the frame, because a client `UID` line carries the user's modes and their IP but
never the account name.

**A change reaches the network**:

```text
handler -> use case -> repository (SQLite)
                    '- projection -> UdbAuthority -> INS/DEL over the link
```

The projection is the only path to the IRCd. State lives in SQLite; UDB is a
replica the IRCd owns.

## The UDB authority

`UdbAuthority` speaks HEL 4: it acknowledges the IRCd's handshake, announces the
six block inventories when selected as propagator, serves snapshots on request
and pushes live mutations. Blocks it publishes:

| Block | Owner | Content |
|---|---|---|
| `N` | `NiCK` | account password, forbid, suspend, swhois, vhost |
| `C` | `CHaN` | channel founder, topic, access list |
| `K` | `OPeR` | dynamic spamfilter profiles |
| `S` | `NiCK` | the service mask, so notices come from a service nick |
| `I`, `L` | — | empty for now |

`NativeDigest` computes the canonical block digest. It is a byte-for-byte port
of the module's own reference implementation, and the tests assert against values
produced by that reference — a drift there would break every reconciliation.

## Symfony

The project moved from a hand-written kernel to Symfony 8.1 for the same reason
Ares uses it: services declared in `config/services.yaml`, autowiring, a console
entrypoint and `debug:container`.

The container owns the object graph. `Bootstrap\DaemonFactory` exists only for
what cannot be autowired — the per-service nicknames and the UIDs derived from
the server SID — and keeps that derivation in one place.

Each service gets `<SID>A0000n` in the order it is introduced: `NiCK` (`01`),
`CHaN` (`02`), `MeMo` (`03`), `OPeR` (`04`), `DeBuG` (`05`), `CReG` (`06`) and
`HeLP` (`07`). A new service takes the next free suffix and joins the debug
channel with the rest.
