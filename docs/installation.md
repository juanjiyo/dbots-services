# Installation

Two machines are involved conceptually, but they are usually the same box: the
**IRCd** (UnrealIRCd 6.2) and **dbots**, which links to it over TLS.

Order matters. dbots configures UDB admission as fail-closed: an IRCd told to
take its database from a propagator will refuse new clients while that
propagator is not linked. So install and start dbots **before** pointing
`udb::propagator` at it.

## 1. Requirements

| Component | Version | Notes |
|---|---|---|
| PHP | 8.3 or newer | 8.5 is what it is developed and tested on |
| PHP extensions | `pdo_sqlite`, `openssl`, `ctype`, `json`, `mbstring` | `openssl` is required for the S2S TLS link |
| Composer | 2.x | |
| UnrealIRCd | 6.2.x with the `third/udb` module (UDB 4) | |

```bash
sudo apt install php8.5-cli php8.5-sqlite3 php8.5-mbstring composer
```

There is no database server: state lives in one SQLite file.

## 2. Get the code

```bash
git clone https://github.com/juanjiyo/dbots-services.git
cd dbots-services
composer install --no-dev
```

## 3. Configure the link

### On the IRCd side

Three things: a link block, a uline entry, and the propagator.

```conf
listen {
    ip *;
    port 6900;
    options { tls; serversonly; }
}

link bots.example.org {
    incoming { mask 127.0.0.1; }
    password "a-long-shared-secret";
    class servers;
}

ulines {
    bots.example.org;
}

udb {
    propagator "bots.example.org";
};
```

* `bots.example.org` must be the same FQDN you put in `DBOTS_LINK_NAME`.
* It must be in `ulines`, or the IRCd will treat the link as a regular server
  and none of the service powers work.
* The port is the **server** port (`serversonly`), not the client port.

### On the dbots side

`DBOTS_LINK_SID` must be unique on the network. Ares-style deployments commonly
take `00X`; pick one nobody else uses.

## 4. Configure the daemon

Defaults for every variable live in `.env`. Do **not** put secrets there: it is
committed. The deployment overrides them from a file outside the repository.

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
```

| Variable | Meaning |
|---|---|
| `DBOTS_LINK_NAME` | Server name presented to the IRCd; must match the `link` block and `ulines` |
| `DBOTS_LINK_SID` | Server ID, `^[0-9][0-9A-Z]{2}$`, unique on the network |
| `DBOTS_LINK_HOST` / `_PORT` | S2S endpoint (TLS) |
| `DBOTS_LINK_PASSWORD` | The password of the `link` block |
| `DBOTS_LINK_TLS_VERIFY` | `0` for a self-signed certificate, `1` to validate the chain |
| `DBOTS_LINK_DESCRIPTION` | Server description shown in `/links` |
| `DBOTS_DATABASE` | SQLite file; its directory is created on first run |
| `DBOTS_ROOT_PASSWORD` | Enables `ROOT <password>`, needed for `SETTIME`. Empty disables it |
| `DBOTS_RECONNECT_DELAY` | Seconds between relink attempts |

Note for `.env` itself: **values containing spaces must be quoted**, or Symfony's
`Dotenv` refuses to parse the file.

## 5. Data directory

```bash
sudo install -d -o "$USER" -g "$USER" -m 700 /var/lib/dbots
```

The SQLite file holds account password hashes, so it deserves the same care as
`/etc/shadow`. Back it up:

```bash
sqlite3 /var/lib/dbots/dbots.sqlite ".backup '/var/backups/dbots-$(date +%F).sqlite'"
```

## 6. Run it

Foreground, to see what happens:

```bash
set -a; . /etc/dbots/env; set +a
php bin/console dbots:run
```

As a user service:

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
loginctl enable-linger "$USER"     # keep it running without an active session
```

`enable-linger` matters: without it the daemon stops when you log out, and a
fail-closed IRCd will then refuse every new client.

## 7. Verify

```bash
# 1. the link and the capability negotiation
grep -E "SERVER_SYNCED|UDB_HEL_CONFIRMED" /path/to/ircd/logs/ircd.log | tail -2

# 2. a client can connect at all (this proves the node is READY)
#    if it is refused with "UDB initialization pending", the round did not finish

# 3. register an account from IRC
/msg NickServ REGISTER <password>

# 4. identify natively from a new connection
/NICK <nick>:<password>
# expect: 900 ... You are now logged in as <nick>

# 5. inspect what the IRCd really stored
/DBQ N::<nick>::pass        (as an oper; the value is redacted by design)
/DBQ K                      (e.g. row count for the spamfilter block)
```

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `SID collision` on link | Another server already uses that SID | Change `DBOTS_LINK_SID` |
| `Link denied (No matching link configuration)` | Name or mask mismatch | `DBOTS_LINK_NAME` must equal the `link` block name, and the mask must accept the source IP |
| Clients refused: `UDB synchronization unavailable` | The IRCd wants a propagator that is not linked | Start dbots, or remove `udb::propagator` while you work |
| Clients refused: `UDB initialization pending` | The reconciliation round has not finished | Check the log for `UDB_SYNC_ABORT`; usually a bad record in a block |
| `FormatException` from Dotenv | A value with spaces in `.env` | Quote it |
| Services answer but nothing happens | The server is not in `ulines` | Add it and rehash |
| `K rows=0` after a spamfilter add | The K record was rejected, not ignored | `/DBQ K` tells you; inspect the record shape |

## 9. Upgrading

```bash
git pull
composer install --no-dev
systemctl --user restart dbots
```

Schema changes are applied on boot: repositories add missing columns with
`ALTER TABLE` when they find an older database. Back up the SQLite file first
anyway.
