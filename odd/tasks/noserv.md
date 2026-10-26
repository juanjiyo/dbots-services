# dbots — NoServ (NoticiasServ)

Objective: implement the complete `NoServ` / `NoticiasServ` service (`no.mrc`),
including subscription management, broadcast news, per-server entry messages,
and the external news fetch.

## Specs

- **S1** — `NoServ` is introduced as `<SID>A00011` with realname
  "Network News Services" and joins the debug channel when configured.
- **S2** — `ALTA` / `BAJA` toggle the caller's subscription to the news service.
  Requires the user to be identified (`+r`).
- **S3** — `EMAIL <mensaje>` (Administrador) sends the message by email to every
  account with an active subscription.
- **S4** — `MOTD ADD|DEL|LIST <mensaje>` (Administrador) manages a persisted list
  of news items for users. `ADD` stores the item and immediately broadcasts it to
  all connected users with the header `[ Noticias de la Red ]`.
- **S5** — `NICK ADD|DEL|LIST <nick> <mensaje>` (Administrador) manages temporary
  pseudo-clients that act as entry MOTD bots. `ADD` introduces the nick,
  associates the message, and replies with success. `DEL` quits the nick and
  removes it. `LIST` shows active MOTD nicks and messages.
- **S6** — `REPRESENTANTES ADD|DEL|LIST <mensaje>` (Administrador) manages a
  persisted list of news for staff. `ADD` stores the item and immediately
  broadcasts it to all connected users with rank Pre-Operador or above, with the
  header `[ Noticias para Representantes ]`.
- **S7** — `NOTICIAS` (Administrador) fetches the configured external news URL
  (`DBOTS_NEWS_URL` env) and replies with the fetched text, truncated to a safe
  length. If the URL is not configured or the fetch fails, replies with an error.
- **S8** — `SERVIDOR <servidor> [mensaje]` (Administrador) manages entry messages
  for users connecting through a given server. With a message, it stores it;
  without a message, it removes it. The server name is matched against connected
  servers and against the first 3 characters (SID) of each user's UID.
- **S9** — `HELP`/`AYUDA` renders the NoServ help catalog.
- **S10** — Update `docs/commands.md` to remove NoServ from "Services that do not
  exist yet" and add a `## NoServ` section.

## Repositories needed

- `NewsSubscriptionRepositoryInterface` + SQLite + in-memory: account => boolean.
- `NewsMessageRepositoryInterface` + SQLite + in-memory: list of news items for
  users (`MOTD`) and for staff (`REPRESENTANTES`).
- `ServerEntryMessageRepositoryInterface` + SQLite + in-memory: server name =>
  message.
- `MotdNickRepositoryInterface` + SQLite + in-memory: nick => message for
  temporary MOTD pseudo-clients.

## Tasks

- [x] **T1** (S1) — Create `NoServCommandHandler`, `NoServHelp`, and wire the
  service into `Daemon`, `DaemonFactory`, and `onPrivmsg`.
- [x] **T2** (S2) — Implement `ALTA` / `BAJA` with subscription repository.
- [x] **T3** (S3) — Implement `EMAIL` using `EmailSenderInterface`.
- [x] **T4** (S4, S6) — Implement `MOTD` and `REPRESENTANTES` repositories and
  broadcast commands.
- [x] **T5** (S5) — Implement `NICK ADD|DEL|LIST` temporary pseudo-clients.
- [x] **T6** (S7) — Implement `NOTICIAS` external HTTP fetch.
- [x] **T7** (S8) — Implement `SERVIDOR` entry messages.
- [x] **T8** (S9) — Add help entries.
- [x] **T9** (S10) — Update `docs/commands.md` and HeLP service list.
- [x] **T10** — Add unit tests for all commands and gates.
- [x] **T11** — Run `composer check`, commit, and push.

## Risk

- **Medium** — Mass messaging and email; forced joins are not part of NoServ, but
  temporary pseudo-clients and external HTTP fetch add surface. Mitigations:
  rank gates, audit logging through `DebugRelay`, configurable external URL.

## Log

- **L1** — User asked for complete NoServ.
- **L2** — `NOTICIAS` is an external HTTP fetch, not a message broadcast; will be
  implemented as a configurable URL fetch.
