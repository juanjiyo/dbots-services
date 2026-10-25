# Installation (from zero)

This walks a newcomer from an empty machine to a working stack. Two pieces are
involved and the order matters:

```text
UnrealIRCd 6.2  +  third/udb module   <---TLS S2S--->   dbots (this repository)
        ^                                                    |
        '- owns the clients, channels and modes              '- owns the database
```

**dbots does nothing on its own.** It is a UDB propagator: the module inside the
IRCd is what stores the network database, and dbots is the process that feeds it
and answers the service commands. Without the module, there is no native login,
no `+r`, no registered channels.

Install dbots **before** telling the IRCd to take its database from it. Admission
is fail-closed: an IRCd waiting for a propagator refuses every new client until
that propagator links.

## 1. Prerequisites

```bash
# Debian/Ubuntu
sudo apt update
sudo apt install -y build-essential git curl libssl-dev libpcre2-dev \
                    libargon2-dev libsodium-dev libjansson-dev \
                    php8.5-cli php8.5-sqlite3 php8.5-mbstring composer
```

| Piece | Version |
|---|---|
| UnrealIRCd | 6.2.x |
| `third/udb` module | UDB 4 (the `unrealircd-udb` project) |
| PHP | 8.3+ (8.5 is what it is tested on), with `pdo_sqlite`, `openssl`, `ctype`, `json`, `mbstring` |
| Composer | 2.x |

## 2. Build UnrealIRCd from source

You need the **source tree**, not just the installed binary: third-party modules
are compiled against it.

```bash
curl -LO https://www.unrealircd.org/downloads/unrealircd-6.2.7.tar.gz
tar xzf unrealircd-6.2.7.tar.gz
cd unrealircd-6.2.7

./Config            # accept the defaults, or set the install prefix
make
sudo make install
```

Keep this directory around. `./unrealircd module` (in the installed tree) will
refuse to run without it.

## 3. Install the UDB module

The module lives in its own repository; it ships a single amalgamated source
file.

```bash
git clone https://github.com/davidlig/unrealircd-udb.git
cp unrealircd-udb/dist/udb.c unrealircd-6.2.7/src/modules/third/udb.c
```

Compile it with the rest of the modules:

```bash
cd unrealircd-6.2.7
make
sudo make install
```

`make install` copies `udb.so` into `/opt/unrealircd/modules/third/`. Check it
landed:

```bash
/opt/unrealircd/unrealircd module-status | grep udb
```

Then load it in the IRCd configuration:

```conf
loadmodule "third/udb";
```

> If your install is binary-only and you no longer have the source tree, the
> module manager cannot help: `unrealircd module` reports
> *"The UnrealIRCd source is required"*. Download the same 6.2.x source, put
> `udb.c` in `src/modules/third/`, `make`, and copy the resulting `.so` into
> `modules/third/`.

## 4. Minimal IRCd configuration

In `/opt/unrealircd/conf/unrealircd.conf`:

```conf
me {
    name "irc.example.org";
    info "Example IRC Network";
    sid "001";
}

/* clients */
listen { ip *; port 6667; }
listen { ip *; port 6697; options { tls; } }

/* servers: dbots links here */
listen {
    ip *;
    port 6900;
    options { tls; serversonly; }
}

link bots.example.org {
    incoming { mask 127.0.0.1; }        # the address dbots connects from
    password "a-long-shared-secret";
    class servers;
}

/* services get server powers only if they are ULines */
ulines {
    bots.example.org;
}

/* the network database is owned by dbots */
udb {
    propagator "bots.example.org";
};
```

Validate and start:

```bash
/opt/unrealircd/unrealircd configtest
/opt/unrealircd/unrealircd start
```

At this point clients will be **refused** with `UDB initialization pending`
until dbots links. That is the fail-closed design working, not a bug.

## 5. Install dbots

```bash
git clone https://github.com/juanjiyo/dbots-services.git
cd dbots-services
composer install --no-dev
```

Configuration lives in environment variables. Defaults are in `.env` (committed,
no secrets); the deployment overrides them from a file outside the repository.

```bash
sudo install -d -m 700 /etc/dbots
sudo tee /etc/dbots/env >/dev/null <<'EOF'
DBOTS_LINK_NAME=bots.example.org
DBOTS_LINK_SID=004
DBOTS_LINK_HOST=127.0.0.1
DBOTS_LINK_PORT=6900
DBOTS_LINK_PASSWORD=a-long-shared-secret
DBOTS_LINK_TLS_VERIFY=0
DBOTS_LINK_DESCRIPTION=dbots services
DBOTS_DATABASE=/var/lib/dbots/dbots.sqlite
DBOTS_ROOT_PASSWORD=another-long-secret
DBOTS_RECONNECT_DELAY=5
EOF
sudo chmod 600 /etc/dbots/env

sudo install -d -o "$USER" -g "$USER" -m 700 /var/lib/dbots
```

| Variable | Meaning |
|---|---|
| `DBOTS_LINK_NAME` | Server name presented to the IRCd; must equal the `link` block and appear in `ulines` |
| `DBOTS_LINK_SID` | Server ID (`^[0-9][0-9A-Z]{2}$`), unique on the network |
| `DBOTS_LINK_HOST` / `_PORT` | S2S endpoint (TLS) |
| `DBOTS_LINK_PASSWORD` | The password of the `link` block |
| `DBOTS_LINK_TLS_VERIFY` | `0` for a self-signed certificate, `1` to validate the chain |
| `DBOTS_LINK_DESCRIPTION` | Shown in `/links` |
| `DBOTS_DATABASE` | SQLite file; its directory is created on first run |
| `DBOTS_ROOT_PASSWORD` | Enables `ROOT <password>`, which `SETTIME` requires. Empty disables it |
| `DBOTS_RECONNECT_DELAY` | Seconds between relink attempts |
| `DBOTS_DEBUG_CHANNEL` | Channel that receives the service activity relay; empty disables it |

## 6. First run

Foreground first, so you can read what happens:

```bash
set -a; . /etc/dbots/env; set +a
php bin/console dbots:run
```

Expect the link to come up and the log of the IRCd to show `SERVER_SYNCED` and
`UDB_HEL_CONFIRMED`. Stop it with `Ctrl-C`.

## 7. Run it as a service

```bash
install -d ~/.config/systemd/user
cat > ~/.config/systemd/user/dbots.service <<'EOF'
[Unit]
Description=dbots IRC services (UDB authority)
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/dbots-services
EnvironmentFile=/etc/dbots/env
ExecStart=/usr/bin/php /opt/dbots-services/bin/console dbots:run
Restart=always
RestartSec=5
StandardOutput=append:/var/log/dbots.log
StandardError=append:/var/log/dbots.log

[Install]
WantedBy=default.target
EOF

systemctl --user daemon-reload
systemctl --user enable --now dbots
sudo loginctl enable-linger "$USER"
```

`enable-linger` is not optional: without it the daemon stops when you log out,
and the IRCd then refuses every new client.

## Modules (optional)

A module is a directory under `modules/` that contains a `module.json` manifest
and a PHP class implementing `Dbots\Module\ModuleInterface`. dbots loads it as
its own pseudo-client and routes messages addressed to its nick.

**A module is not a sandbox.** It runs inside the dbots process with the same
privileges as the daemon itself: it can send arbitrary S2S commands, read the
database, and impersonate any service. Only enable code you trust as much as the
daemon itself.

For that reason enabling a module requires **Root** level staff (`CeNTeR MODULO
LOAD <name>`). The manifest controls whether a module starts enabled by default:

```json
{
    "class": "Modules\\Ping\\PingModule",
    "nick": "PiNG",
    "description": "Responde PING con PONG. Modulo de ejemplo del sistema de modulos.",
    "enabled_by_default": true
}
```

The bundled `modules/Ping` module answers `PING` with `PONG` and is safe to keep
enabled; it exists only to prove the loading path.

## 8. Verify the installation

```bash
# 1. the link and the capability negotiation
grep -E "SERVER_SYNCED|UDB_HEL_CONFIRMED" /opt/unrealircd/logs/ircd.log | tail -2

# 2. a client can connect (this proves the node is READY)
#    if it is refused: "UDB initialization pending" -> the round did not finish

# 3. from IRC, register an account
/msg NiCK REGISTRA <password>

# 4. from a NEW connection, identify natively
/NICK <nick>:<password>
# expect: 900 ... You are now logged in as <nick>

# 5. inspect what the IRCd really stored (as an oper)
/DBQ N::<nick>::pass     # value is redacted by design
/DBQ K                   # row count of the spamfilter block
```

If step 4 returns `900`, the whole chain works: dbots is the propagator, the
module stores the profile, and the IRCd authenticates on its own.

## 9. Backups

The SQLite file holds password hashes:

```bash
sqlite3 /var/lib/dbots/dbots.sqlite ".backup '/var/backups/dbots-$(date +%F).sqlite'"
```

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `SID collision` | Another server uses that SID | Change `DBOTS_LINK_SID` |
| `Link denied (No matching link configuration)` | Name or mask mismatch | `DBOTS_LINK_NAME` must equal the `link` block name, and the mask must accept the source address |
| `UDB synchronization unavailable` | The IRCd wants a propagator that is not linked | Start dbots |
| `UDB initialization pending` | The reconciliation round has not finished | Look for `UDB_SYNC_ABORT` in the IRCd log |
| `The UnrealIRCd source is required` | Binary-only install | Build the source tree and compile the module there (step 3) |
| `FormatException` from Dotenv | A value with spaces in `.env` | Quote the value |
| Services answer but do nothing | The server is not in `ulines` | Add it and rehash |
| A config change has no effect | Symfony caches the compiled container in `prod` | `php bin/console cache:clear` |
| `K rows=0` after adding a spamfilter | The record was rejected, not stored | `/DBQ K` tells you; check the record shape |

## 11. Upgrading

```bash
cd dbots-services
git pull
composer install --no-dev
systemctl --user restart dbots
```

Schema changes apply on boot (repositories add missing columns with `ALTER
TABLE`). Back up the SQLite file first anyway.
