# Command reference

Every command below answers `HELP` and `AYUDA`:

```text
/msg NiCK HELP            index of commands you may use
/msg NiCK HELP REGISTER   one page: syntax, aliases and an example
```

The index is permission-filtered: operator-only commands simply do not appear
for a normal user, and asking for one by name says so instead of showing it.

Colours are the ones the original mIRC help used, so the output looks familiar:
`3` for the header, `12` for command names, `10` for labels. Every coloured run
closes with a bare `\x03`, so a client falls back to its own palette instead of
inheriting ours for the rest of the line.

The authoritative command names are the ones in the original mIRC scripts
(`sitiochat/dbots/sistema/*.mrc`). Spanish is the primary spelling on this
network, so both are accepted wherever the original had both.

Status: **done** works and is verified; **partial** exists but covers less than
the original; **todo** is not implemented.

## NiCK

| Command | Status | Notes |
|---|---|---|
| `REGISTER` / `REGISTRA <password> [email]` | done | Creates the account; the IRCd then asks you to identify with `/NICK nick:password` |
| `IDENTIFY <password>` | done | Verifies the password and points you at the native flow |
| `INFO <nick>` | done | Registration date, email presence, founded channels, unread memos |
| `ESTADO` / `STATUS [nick]` | done | Registered / forbidden / suspended / noexpire |
| `SET PASSWORD <current> <new>` | done | |
| `DROP <password>` | done | Refuses while the account still founds channels |
| `SUSPEND <nick> [reason]`, `UNSUSPEND`, `LISTSUSPEND` | done | Operator only; UDB `N::<nick>::suspend` |
| `FORBID <nick> [reason]`, `UNFORBID`, `LISTFORBID` | done | Operator only; UDB `N::<nick>::forbid` |
| `SWHOIS <nick> <text>` | done | Operator only; UDB `N::<nick>::swhois` |
| `NOEXPIRE <nick>` | done | Operator only; toggles the flag |
| `SET` other options (EMAIL, URL, QUIT, PRIVATE, KILL, AUTOMODE…) | done | |
| `SENDPASS <nick>` | done | IRC-operator only; generates a temporary password and sends it via memo |
| `SENDNEWPASS <nick>` | done | IRC-operator only; generates a temporary password and sends it to the account's email address |
| `GETPASS <nick>` | dropped | Passwords are hashed; `GETPASS` cannot read them back |
| `MARCA` / `MARCADEL` / `MERCADEL` | dropped | dBots-specific mark workflows with no UDB equivalent |
| `MSGNR` | dropped | Automatic login notice; no real use case identified |
| `AUTOMODE <nick> [modos]` | done | Original mIRC format; applies modes automatically on `+r` |
| `BLACKMAIL ADD|DEL|LIST <dominio>` | done | IRC-operator only; blocks registration emails from listed domains |
| `VREG ON|OFF` | done | IRC-operator only; toggles email validation for registration |
| `VALIDAR <código>` | done | Completes registration when VREG is ON |
| `migrar` | dropped | dBots-specific migration workflow with no equivalent in the new UDB-backed architecture |

## CHaN

Channel registration belongs to **CReG**, not ChanServ: `ch.mrc` has no
`REGISTRA`, `cr.mrc` does. ChanServ points at CReG instead, and CReG keeps the
approval flow — you request, staff approve. The original *supports* stage
(`APOYA`/`APOYOS`) was dropped by request, so a request goes straight from
`REGISTRA` to `PENDIENTE`.

| Command | Status | Notes |
|---|---|---|
| `REGISTER` | moved | Channel registration lives in CReG; CHaN answers with a redirect |
| `IDENTIFY <#channel> <password>` | done | The channel password identifies its founder for the session, account or not |
| `INFO <#channel>`, `DROP <#channel>` | done | Founder only for `DROP` |
| `ACCESS LIST\|ADD\|DEL <#channel> <nick> [level]` | done | Level -1..499, gated by `ACC-LIST`/`ACC-CHANGE`; kept in SQLite — UDB treats `C::access` as a join allowlist |
| `TOPIC <#channel> <text>` | done | UDB `C::<channel>::topic` |
| `LEVELS <#channel> LIST\|SET\|RESET [key] [level]` | done | Thresholds -1..500 per command; 500 is founder or IRCop. Only a founder-level user changes it |
| `SET <#channel> DESC\|URL\|EMAIL\|ENTRYMSG [value]` | done | Services-only settings; the entry message greets on join with `<nick>` replaced |
| join reactor (entry message, akick, `NOJOIN`, auto rank) | done | Runs on `SJOIN` once the link is synced |
| mode reactor (`MLOCK`, `SECUREOPS`, `SECUREHALFOPS`, `+q` for the founder) | done | Corrects the channel after somebody else changes modes |
| topic reactor (`TOPICLOCK`, `KEEPTOPIC`) | done | Reverts or remembers the topic after a change |
| `SET <#channel> <OPTION> ON\|OFF` | done | `TOPICLOCK`, `KEEPTOPIC`, `DEFTOPIC`, `SECUREOPS`, `SECUREHALFOPS`, `AUTOLIMIT`, `PBANS`, `PMODE`, `PRIVATE`, `SECURE`, `RESTRICTED`, `NOEXPIRE`, `DEBUG` |
| `SET <#channel> MLOCK [modes]` | done | Validated against the original letter set and applied at once |
| `SET <#channel> PASSWORD <password>` | done | Hashed; `IDENTIFY` accepts only the new one |
| `OP` / `DEOP` / `HALFOP` / `DEHALFOP` / `VOICE` / `DEVOICE <#channel> <nick>` | done | Threshold per rank from the table; removing also needs standing above the holder |
| `INVITE <#channel>`, `UNBAN <#channel>` | done | `UNBAN` sends `-b <nick>`, so it only lifts a ban written as the bare nick |
| `CLEAR <#channel> <option>` | done | `MODES`, `BANS`, `OPS`, `HALFOPS`, `VOICES`, `USERS`, `TOPIC`; bounded by what the mode stream has shown us |
| `KICK` | dropped | By decision: channels kick with native `+o` |
| `AKICK <#channel> ADD\|DEL\|LIST\|ENFORCE [nick] [reason]` | done | `AKICK-CHANGE` 499, `AKICK-LIST` 300; 50 entries max |
| `DELACCESS` | done | Self-removal from a channel access list |
| `SECUREOPS`… | done | Part of the SET option switches |
| `SUSPEND <#channel> <reason>`, `UNSUSPEND <#channel>`, `LISTSUSPEND` | done | Oper only; publishes `C::<channel>::suspend`, so the IRCd enforces it |
| `FORBID <#channel> <reason>`, `UNFORBID`, `LISTFORBID` | done | Oper only; drops the profile and publishes `C::<channel>::forbid` |
| `LIST <pattern>`, `GETPASS <#channel>` | done | Oper only; `GETPASS` reports the hash instead of the secret |
| `ESPECIAL` | dropped | It drives a pool of eleven entry bots that this daemon does not run |
| `SET FOUNDER\|SUCCESSOR` | done | Founder transfer and successor assignment |

## MeMo

| Command | Status | Notes |
|---|---|---|
| `SEND <nick> <text>`, `LIST`, `READ <n>`, `DEL <n>` | done | Memos belong to the account, not the session |
| `NOMEMOS ADD\|DEL\|LIST <nick>` | done | Blacklist of accounts that cannot send you memos |
| `SET NOTIFY ON\|LOGON\|NEW\|OFF` | done | When to announce unread memos |
| `OPERS` / `ADMINS` / `COADMINS` / `PREOPERS` / `USERS` | dropped | Left out by decision; may be revisited later |

## OPeR

| Command | Status |
|---|---|
| `KILL`, `KICK`, `OP`, `DEOP`, `MODE` | done |
| `GLINE <user@host> <duration> [reason]`, `UNGLINE` | done |
| `RENAME <nick> [newnick]`, `DROPNDB <nick>`, `APODERA <#channel> [nick]` | done |
| `LISTCLONES`, `KILLCLONES`, `BLOCK` | done |
| `JAIL <nick>`, `UMODE <nick> +/-modes` | done | Requires IRC operator (`+o`) |
| `ELINE ADD\|DEL\|LIST\|VIEW\|COUNT ...` | done | Requires CeNTeR `Administrador`; IP-based masks |
| `SERVERMAP` | done | Requires CeNTeR `Administrador` |
| `CONEXIONES LIST <min>`, `CONEXIONES VIEW <host>` | done | Requires CeNTeR `Administrador` |
| `LIMPIA <#channel>`, `STATS`, `IRCOPS`, `ROOT`, `SETTIME`, `SPAM LIST\|ADD\|DEL` | done |
| `INVITE <#channel>` | done | |
| `VIEWIP <nick>` | done | Shows the user's real IP; moved from NiCK | |
| `SET SUFIJO\|NICKSERV\|CHANSERV\|IPSERV\|PASSPROTECT\|PREFIJOS <valor>` | done | IRC-operator only; UDB S block |

## CReG

Channel registration, ported from `cr.mrc`. A request needs the requester to be
identified and to have `@` in the channel; staff then approve or deny it, and
either answer leaves a memo for the founder.

`REGISTRA` takes a **channel password**, as the original did. It is stored with
the request, copied to the channel on approval, and lets the founder identify
with `CHaN IDENTIFY <#channel> <password>` even without their account — that is
how the original proved ownership.

| Command | Status | Notes |
|---|---|---|
| `REGISTRA <#channel> <password> [description]` | done | `REGISTER` also accepted |
| `ESTADO <#channel>` | done | Pending, accepted or denied |
| `CANCELA <#channel>` | done | Withdraws your own pending request |
| `LISTA [status]` | done | Operator only |
| `INFO <#channel>` | done | Operator only |
| `ACEPTA <#channel>` | done | Operator level 5 or a CReG administrator; registers the channel |
| `DENIEGA <#channel> [reason]` | done | Same gate as `ACEPTA` |
| `LIBERA <#channel>` | done | Frees a denied request so it can be asked again |
| `ADMIN ADD\|DEL\|LIST [nick]` | done | Who may approve registrations |
| `OFICIAL <#channel> [description]` | done | Registers a network channel |
| `COMERCIAL <#channel> <founder> [description]` | done | Registers a channel on somebody else's behalf |

## HeLP

Global help, ported from `he.mrc`. `HeLP` serves prose pages rather than the
per-command index the other services have, and it is what a newcomer is pointed
at first.

| Topic | Status | Notes |
|---|---|---|
| `AYUDA` / `HELP` | done | Index; `AYUDA <topic>` is the same as `<topic>` |
| `UMODES`, `CMODES` | done | The user and channel modes the network documents |
| `BOTS` | done | The services the daemon actually introduces, so it cannot drift |
| `NICK`, `CANAL` | done | Registration walkthroughs, with the CReG password step |
| `CREDITOS` | done | The dBoTs authors |
| anything else | done | `Comando desconocido <cmd>`, as the original |

## CeNTeR

The control service: the staff ladder and the module system. Its gate is
**Administrador**, and anything that hands out Administrador or above — or loads
code — needs **Root**.

`ROOT <password>` opens a Root session; that is the way in for the very first
Root, since nobody holds a rank on a fresh install.

| Command | Status | Notes |
|---|---|---|
| `PREOPER`, `OPER` `ADD\|DEL <nick>` | done | Administrador |
| `ADMIN ADD\|DEL <nick>` | done | Root |
| `REMOPER <nick>` | done | Root; strips whatever rank was there |
| `ROOT <password>`, `ROOT ADD\|DEL <nick>` | done | Session gate and Root rank |
| `MODULO LIST\|LOAD\|UNLOAD [name]` | done | Root; applied immediately |
| `KILL`, `GLINE`, `CLONES` | not here | They live in OPeR; two doors to one action is a bug factory |
| `RAW <linea>`, `NORAW ADD|DEL|LIST <comando>` | done | Root only; RAW sends a raw line to the IRCd, NORAW blocks dangerous commands |
| `RESTART`, `MODULO` (mIRC side), `TABLA`, `BOT`, `UPDATE`, `OPTIMIZA`… | dropped | They drove the mIRC script loader, the bot's ini database or its process |

## CLoNeS

Clone exception manager. Requires a CeNTeR rank of **Administrador** because
every mutation rewrites the UnrealIRCd `allow` include and signals the IRCd.

| Command | Status | Notes |
|---|---|---|
| `PERM <IP\|host> <N>` | done | Permanent `allow` block with `maxperip N` |
| `ADD <IP\|host> <N> <duration>` | done | Temporary exception; duration is `30d`, `6m` or `1y` |
| `RENEW <IP\|host> <duration>` | done | Updates expiration, keeps `N` |
| `EDIT <IP\|host> <N> <duration>` | done | Changes `N` and expiration |
| `DEL <IP\|host>` | done | Removes the exception |
| `INFO [IP\|host]` | done | Without argument, shows the host linked to the caller's nick |
| `LIST` | done | Lists active permanent and temporary exceptions |

## GlobalServ

Network-wide announcements, ported from `gl.mrc`. The service gate is **Administrador**;
commands that originally required Root keep that requirement.

| Command | Status | Notes |
|---|---|---|
| `GLOBAL <mensaje>` | done | Sends a two-line message to every connected user |
| `NICK <nick> <mensaje>` | done | Introduces a temporary pseudo-client, broadcasts, then quits it |
| `CHAN <#canal> <mensaje>` | done | Sends the message to every member of the channel |
| `JOIN <#canal> <motivo>` | done | Root only; `SVSJOIN`s every user, then broadcasts |
| `PRE-OPER <mensaje>` | done | Administrador; reaches Pre-Operador and above |
| `OPER <mensaje>` | done | Administrador; reaches Operador and above |
| `CO-ADMIN <mensaje>` | done | Root; reaches Administrador and above |
| `ADMIN <mensaje>` | done | Root; reaches Administrador only |

## NoServ

Network news service, ported from `no.mrc`. `ALTA`/`BAJA` only require an identified
nick; every other command requires **Administrador**.

| Command | Status | Notes |
|---|---|---|
| `ALTA` | done | Subscribe the caller's account to news |
| `BAJA` | done | Unsubscribe the caller's account |
| `EMAIL <mensaje>` | done | Sends the message by e-mail to all subscribers |
| `MOTD ADD\|DEL\|LIST [mensaje]` | done | User news list; `ADD` broadcasts immediately |
| `NICK ADD\|DEL\|LIST <nick> [mensaje]` | done | Temporary MOTD pseudo-clients |
| `REPRESENTANTES ADD\|DEL\|LIST [mensaje]` | done | Staff news list; `ADD` broadcasts to Pre-Operador+ |
| `NOTICIAS` | done | Fetches `DBOTS_NEWS_URL` and replies with the text |
| `SERVIDOR <servidor> [mensaje]` | done | Entry message per server; omit message to remove |

## Modules

A bot that is not a network service lives in `modules/<Name>/` with a
`module.json` (`class`, `nick`, `description`, `enabled_by_default`) and a class
implementing `Dbots\Module\ModuleInterface`. The daemon introduces its nick as a
pseudo-client and hands it every parsed message, so a contributor can add a bot in
PHP instead of running another daemon.

`CeNTeR MODULO LIST|LOAD|UNLOAD` manages them; the change is applied immediately
and the module pseudo-client is introduced or quit without restarting the daemon.

**A module is trusted code, not a sandbox.** It runs inside the services process
with its privileges.

## Services that do not exist yet

| Bot | Command surface |
|---|---|
| **CenTeR** (`ce.mrc`) | Administrative console: `KLINE`, `GLINE`, `RAW`, `RESTART`, `MODULO`, `CLONES`, `SAJOIN`… |
| **PreOpServ** (`pr.mrc`) | `IGNORA`, `MSG`, `MODE` for pre-operators |

Relative completeness: OPeR, CReG and HeLP cover their reference; NiCK is about a
third; CHaN and MeMo less than that.

## Debug relay

The original network ran a `de.mrc` "debug" bot: a second S2S link that dumped the
raw protocol into a staff channel. dbots folds that into the daemon, which already
sees the whole stream, and keeps it narrower on purpose:

```conf
DBOTS_DEBUG_CHANNEL=#DeBuG
```

When set, every service — `NiCK`, `CHaN`, `MeMo`, `OPeR`, `CReG`, `HeLP`
and the `DeBuG` relay — joins that channel with operator status, the way the
original services sat in the debug channel. `DeBuG` is the one that writes to it. The `<DeBuG>`
you see is its nickname, and the timestamp is your client's: the message body
carries neither.

```text
JuanJo_Jaen entra al canal #sitioChat
irc.sitiochat.es cambia modos de #sitioChat a +o JuanJo_Jaen
Conecta: NICK: JuanJo_Jaen IDENT: ~jj IP/HOST: 1.2.3.4 REALNAME: Juan SERVIDOR: 001
NickServ añade la prohibición de JuanJo
```

The event table is a port of the original `de.mrc` branches: user introduction,
nick change, join (the `SJOIN` a server really sends), part, topic, quit, kick,
kill, channel and user modes, forced modes, `SWHOIS`, `SETNAME`, host and ident
changes, `AWAY`, notices, `CHATOPS`/`ADCHAT`/`NACHAT`, oper notifications and
server links and squits. UIDs resolve to nicknames.

UDB mutations are translated too (password, forbid, suspend, swhois, vhost,
founder, topic, access, spamfilter); anything else falls back to the framed form.

Two redactions, both deliberate:

* commands whose arguments are a secret (`REGISTER`, `IDENTIFY`, `DROP`, `ROOT`,
  `SET`) keep their name and lose their arguments;
* `N::*::pass` values and long Base64 blobs are replaced before the line is
  written.

Reconciliation traffic is never mirrored; a sync storm would drown the channel.
