# Command reference

The authoritative command names are the ones in the original mIRC scripts
(`sitiochat/dbots/sistema/*.mrc`). Spanish is the primary spelling on this
network, so both are accepted wherever the original had both.

Status: **done** works and is verified; **partial** exists but covers less than
the original; **todo** is not implemented.

## NickServ

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

## ChanServ

Note: in the original, **channel registration belongs to CReG, not ChanServ**.
`ch.mrc` has no `REGISTRA`; `cr.mrc` does. dbots currently registers channels
through ChanServ, which is a known deviation to correct when CReG is ported.

| Command | Status | Notes |
|---|---|---|
| `REGISTER` / `REGISTRA <#channel>` | partial | Works, but belongs in CReG with approval |
| `INFO <#channel>`, `DROP <#channel>` | done | Founder only for `DROP` |
| `ACCESS LIST\|ADD\|DEL <#channel> [nick]` | done | UDB `C::<channel>::access::<nick>` |
| `TOPIC <#channel> <text>` | done | UDB `C::<channel>::topic` |
| `OP` / `DEOP` / `HALFOP` / `DEHALFOP` / `VOICE` / `DEVOICE`, `KICK`, `INVITE`, `UNBAN`, `CLEAR` | todo | Channel-level actions for the founder |
| `AKICK`, `LEVELS`, `DELACCESS`, `SECUREOPS`… | todo | The rank model |
| `SET *` (founder, successor, entrymsg, mlock, topiclock…), `SUSPEND`, `FORBID`, `GETPASS`, `BFOUNDER`, `ESPECIAL`, `LIST` | todo | |

## MemoServ

| Command | Status | Notes |
|---|---|---|
| `SEND <nick> <text>`, `LIST`, `READ <n>`, `DEL <n>` | done | Memos belong to the account, not the session |
| `NOMEMOS`, `SET` / `SET NOTIFY` | todo | |
| `OPERS` / `ADMINS` / `COADMINS` / `PREOPERS` / `USERS` | todo | Memos to a group |

## OperServ

| Command | Status |
|---|---|
| `KILL`, `KICK`, `OP`, `DEOP`, `MODE` | done |
| `GLINE <user@host> <duration> [reason]`, `UNGLINE` | done |
| `RENAME <nick> [newnick]`, `DROPNDB <nick>`, `APODERA <#channel> [nick]` | done |
| `LISTCLONES`, `KILLCLONES`, `BLOCK` | done |
| `LIMPIA <#channel>`, `STATS`, `IRCOPS`, `ROOT`, `SETTIME`, `SPAM LIST\|ADD\|DEL` | done |
| `invite`, `SET` | todo |

## Services that do not exist yet

| Bot | Command surface |
|---|---|
| **CReG** (`cr.mrc`) | `REGISTRA`, `ACEPTA`, `DENIEGA`, `CANCELA`, `LIBERA`, `LISTA`, `INFO`, `ESTADO`, `RESET`, `MARCA`, `OFICIAL`, `COMERCIAL`, `ADMIN`, `OP` — channel registration with operator approval (`APOYA`/`APOYOS` are dropped: no longer needed) |
| **CenTeR** (`ce.mrc`) | Administrative console: `KLINE`, `GLINE`, `RAW`, `RESTART`, `MODULO`, `CLONES`, `SAJOIN`… |
| **GlobalServ** (`gl.mrc`) | `GLOBAL`, network-wide announcements |
| **HelpServ** (`he.mrc`) | `BOT`, `CANAL`, `CMODES`, `UMODES`, credits |
| **NoServ** (`no.mrc`) | `MOTD`, `REPRESENTANTES`, `ALTA`/`BAJA` |
| **PreOpServ** (`pr.mrc`) | `IGNORA`, `MSG`, `MODE` for pre-operators |

Absolute counts: OperServ is complete against its reference; NickServ is about a
third; ChanServ less than that; MemoServ roughly half.
