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
| `SET` other options (EMAIL, URL, QUIT, PRIVATE, KILL, VHOST, AUTOMODE…) | todo | |
| `SENDPASS` / `SENDNEWPASS`, `GETPASS` | todo | Passwords are hashed, so they cannot be read back; only a reset is possible |
| `VHOST`, `DROPVHOST`, `VIEWIP` | todo | |
| `MARCA` / `MARCADEL` / `MERCADEL`, `MSGNR`, `automode`, `blackmail`, `validar`, `vreg`, `migrar` | todo | dBots-specific workflows with no UDB equivalent |

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
| `ACCESS LIST\|ADD\|DEL <#channel> <nick> [level]` | done | Level -1..499, gated by `ACC-LIST`/`ACC-CHANGE`; UDB stores the entry without the level |
| `TOPIC <#channel> <text>` | done | UDB `C::<channel>::topic` |
| `LEVELS <#channel> LIST\|SET\|RESET [key] [level]` | done | Thresholds -1..500 per command; 500 is founder or IRCop. Only a founder-level user changes it |
| `SET <#channel> DESC\|URL\|EMAIL\|ENTRYMSG [value]` | done | Services-only settings; the entry message greets on join with `<nick>` replaced |
| `SET <#channel> PASSWORD <password>` | done | Hashed; `IDENTIFY` accepts only the new one |
| `OP` / `DEOP` / `HALFOP` / `DEHALFOP` / `VOICE` / `DEVOICE <#channel> <nick>` | done | Threshold per rank from the table; removing also needs standing above the holder |
| `INVITE <#channel>`, `UNBAN <#channel>` | done | `UNBAN` sends `-b <nick>`, so it only lifts a ban written as the bare nick |
| `CLEAR <#channel> <option>` | todo | `MODES`, `BANS`, `OPS`, `HALFOPS`, `VOICES`, `USERS`, `TOPIC` |
| `KICK` | dropped | By decision: channels kick with native `+o` |
| `AKICK`, `LEVELS`, `DELACCESS`, `SECUREOPS`… | todo | The rank model |
| `SET *` (founder, successor, entrymsg, mlock, topiclock…), `SUSPEND`, `FORBID`, `GETPASS`, `BFOUNDER`, `ESPECIAL`, `LIST` | todo | |

## MeMo

| Command | Status | Notes |
|---|---|---|
| `SEND <nick> <text>`, `LIST`, `READ <n>`, `DEL <n>` | done | Memos belong to the account, not the session |
| `NOMEMOS`, `SET` / `SET NOTIFY` | todo | |
| `OPERS` / `ADMINS` / `COADMINS` / `PREOPERS` / `USERS` | todo | Memos to a group |

## OPeR

| Command | Status |
|---|---|
| `KILL`, `KICK`, `OP`, `DEOP`, `MODE` | done |
| `GLINE <user@host> <duration> [reason]`, `UNGLINE` | done |
| `RENAME <nick> [newnick]`, `DROPNDB <nick>`, `APODERA <#channel> [nick]` | done |
| `LISTCLONES`, `KILLCLONES`, `BLOCK` | done |
| `LIMPIA <#channel>`, `STATS`, `IRCOPS`, `ROOT`, `SETTIME`, `SPAM LIST\|ADD\|DEL` | done |
| `invite`, `SET` | todo |

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

## Services that do not exist yet

| Bot | Command surface |
|---|---|
| **CenTeR** (`ce.mrc`) | Administrative console: `KLINE`, `GLINE`, `RAW`, `RESTART`, `MODULO`, `CLONES`, `SAJOIN`… |
| **GlobalServ** (`gl.mrc`) | `GLOBAL`, network-wide announcements |
| **NoServ** (`no.mrc`) | `MOTD`, `REPRESENTANTES`, `ALTA`/`BAJA` |
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
