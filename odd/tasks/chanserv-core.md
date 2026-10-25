# dbots — ChanServ core

Objective: register channels through ChanServ so UDB owns their lifecycle: the
founder keeps `+q` and the channel carries `+r`.

## Specs

- **S1** — `CHANSERV REGISTER <#channel>` (alias `REGISTRA`) registers the channel with the sender's account as founder.
- **S2** — Only an identified sender can register: the sender must carry `+r` and their nick must exist in the account store. A client UID never carries the account name, so identity is derived, never trusted from the frame.
- **S3** — A channel is registered once; names are compared case-insensitively and stored lowercased.
- **S4** — `CHANSERV INFO <#channel>` reports founder and registration date.
- **S5** — `CHANSERV DROP <#channel>` works only for the founder and removes the profile.
- **S6** — Registration and drop are projected to UDB immediately (`INS`/`DEL` on `C::<channel>::founder`), so the IRCd applies or removes `+q` and `+r` on the live channel.
- **S7** — Channel records never include the block letter in their path, matching the N block convention.

## Tasks

- **T1** (S3) — `ChannelName`, `Channel` domain objects.
- **T2** (S1, S3, S4, S5) — `RegisterChannelHandler`, `DropChannelHandler`.
- **T3** (S3) — `SqliteChannelRepository`.
- **T4** (S2) — Network state tracks user modes; `UMODE2`/`MODE` update them.
- **T5** (S1, S4, S5) — `ChanServCommandHandler`.
- **T6** (S6, S7) — `ChannelBlockProvider`, `UdbChannelProjection`, `CompositeBlockProvider`.
- **T7** — Wiring: second pseudo-client (`<sid>A00002`) and per-service reply sender.
- **T8** — 8 unit tests; 56 in the suite.

## Log

- **L1** — Continued autonomously from the NickServ milestone.
- **L2** — Two identity bugs found by running it. First, the channel founder was stored as `fwAAAQ==`: the 11th field of a client `UID` is the base64 IP, not the account. Second, `+r` arrives *after* the introduction as `:nick UMODE2 +r`, so a snapshot of modes at join time misses identification. Fixed by tracking modes and resolving identity from `+r` plus our own account store.
- **L3** — Verified live: `REGISTER #dbtest` produces `MODE #dbtest -o FreshUser`, `MODE #dbtest +r`, `MODE #dbtest +q FreshUser`, and the founder keeps `+q` when rejoining later.
- **L4** — Verified live: `DROP #dbtest` produces `MODE #dbtest -q FreshUser` and `MODE #dbtest -r`, and the profile disappears from SQLite.
- **L5** — Debug lines are gated behind `DBOTS_DEBUG=1` so the unit stays quiet.
- **L6** — Added `ACCESS LIST|ADD|DEL` and `TOPIC`. Access exports `#channel::access::nick` (UDB's JOIN hook only checks the child exists and the user has +r) and the topic exports `#channel::topic`. Dropping a channel also removes its access rows and topic record.
- **L7** — Verified live: `ACCESS ADD`/`LIST`/`TOPIC` reply correctly and `channel.get` reports topic "Bienvenidos al canal" set by ChanServ with modes `nrt`.

---

# ch.mrc parity (second stage)

Objective: bring CHaN up to the original `ch.mrc` surface, starting with the
channel settings the founder can change. The reference is
`sitiochat/sistema/ch.mrc` (2171 lines); its dispatch table is `chanserv.procesa`
on line 290 and `SET` is `chanserv.set` on line 900.

## Specs (this stage)

- **S8** — `SET` requires a registered, non-suspended channel whose sender is the
  founder by account, or identified with the channel password, or an IRCop.
  Denial is `Solo el fundador puede hacer eso.`
- **S9** — `SET <#channel> DESC <text>` stores the description. Without text the
  answer is `Error de Sintaxis: SET <canal> DESC <texto>`; on success,
  `La Descripción de <canal> ha sido cambiada a <texto>.`
- **S10** — `SET <#channel> URL [url]` with no url answers
  `URL para <canal> deshabilitada.`; otherwise it stores the url and answers
  `URL para <canal> cambiada a <url>.`
- **S11** — `SET <#channel> EMAIL [address]` follows S10 with
  `Dirección de E-Mail para <canal> deshabilitada.` and
  `Dirección de E-Mail para <canal> cambiada a <address>.`
- **S12** — `SET <#channel> ENTRYMSG [text]` with no text answers
  `Mensaje de entrada para <canal> deshabilitado.`; otherwise it stores the text
  and answers `Mensaje de entrada para <canal> cambiado.`
- **S13** — `SET <#channel> PASSWORD <password>` stores a hash, never the
  plaintext, and the new password replaces the old one for `CHaN IDENTIFY`.
  Deliberate deviation: the answer is `Contraseña de <canal> cambiada.` instead
  of the original's `contraseña cambiada a <pass>`, because a NOTICE is kept by
  the client and by any relay.
- **S14** — `INFO <#channel>` shows whichever of description, url, email and
  entry message are set.
- **S15** — Settings live in SQLite only. UDB 4 has no record for them: a channel
  key is native `+k` inside `C::<channel>::modes`, so nothing is published for
  these fields.
- **S16** — The level table is the SitioChat reference table decided on
  2026-10-25: the ChatHispano values, plus `AUTOHALFOP 200` and
  `HALFOPDEHALFOP 200`, and no `KICK`.
- **S17** — Thresholds run from -1 (never) to 500 (founder or IRCop). There is no
  `DIS` sentinel: the reference expresses "disabled" as 500.
- **S18** — `LEVELS <#channel> LIST` prints every key with its current value.
- **S19** — `LEVELS <#channel> SET <key> <level>` changes one threshold;
  `RESET` returns every key to its reference default. An unknown key answers
  `Opción <key> desconocida.`, a value outside -1..500 answers
  `El Nivel debe de estar entre -1 y 500.`
- **S20** — Only a founder-level user changes the table: the reference sets the
  `SET` key itself to 500, and the handler answers
  `Solo el fundador puede hacer eso.` otherwise.
- **S21** — `KICK` is dropped from the equation, by explicit decision: the
  reference has no `KICK` key and the network kicks with native `+o`.
- **S22** — A user's level in a channel is 500 for an IRCop, the founder by
  account, or a session identified with the channel password; otherwise the
  access entry, defaulting to 0.
- **S23** — `ACCESS LIST` requires `ACC-LIST` and `ACCESS ADD|DEL` requires
  `ACC-CHANGE`, both read from the channel table. Denial is `Permiso denegado.`
- **S24** — `ACCESS ADD <nick> <level>` takes -1..499, because 500 belongs to the
  founder. Non-numeric answers `El nivel del registro debe de ser un número.`,
  out of range answers `El nivel de registro debe estar comprendido entre -1 y 499.`
- **S25a** — Access ranks never leave SQLite. UDB reads `C::<channel>::access` as
  a **join allowlist**: once any entry exists, every other nick is refused with
  `448 You must be authorized and identified to join this channel`. Publishing
  ranks there would turn a level list into a private channel.
- **S25b** — Every registered channel publishes `C::<channel>::options` with the
  `PERSISTENT` bit (0x08), so the IRCd keeps it alive with no members. That also
  makes joins into a registered channel observable: an existing channel sends
  `SJOIN`, a channel created from a profile sends nothing.

- **S26** — A rank command takes `<<#channel>> <nick>`, requires the target to be
  in the channel, and reads its threshold from the table: `OPDEOP` for
  `OP`/`DEOP`, `HALFOPDEHALFOP` for the halfop pair, `VOICEDEVOICE` for the voice
  pair.
- **S27** — Removing a rank also needs the sender to stand above the holder; an
  IRCop counts as 500, so nobody deops an oper.
- **S28** — After a rank change or an invite, ChanServ says it in the channel:
  `<sender> hace <COMMAND> a <nick>.` and `<sender> se invita al canal.`
- **S50** — `SET <#channel> <OPTION> ON|OFF` stores the switch and answers with
  that option's own sentence, which differs per option
  (`Opción de Secure Ops activada.`, `El canal <channel> será auto-limitado.`,
  `Protección de bans activada para el canal <channel>`).
- **S51** — `SET <#channel> MLOCK <modes>` keeps only the letters `ch.mrc`
  accepts, reports unknown and duplicated ones, applies the surviving set at
  once, and answers `Candado de modo de canal <channel> cambiado a <modes>.` With
  no value it clears the lock.
- **S52** — `C::<channel>::options` carries `PERSISTENT` always and `SECURE_OPS`
  when `SET SECUREOPS ON`, so the IRCd enforces that only `+q` grants ranks. The
  rest of the switches are services-only until the reactor reads them.
- **S53** — The switches do not appear in `INFO`: fourteen lines would bury the
  fields that people actually read.
- **S46** — `LIST <pattern>` is oper-only and answers
  `Lista de Canales que satisfacen <pattern>:`, one
  `<channel> - ACTIVO|SUSPENDIDO (BDD)` per match, capped at 50, then
  `Fin de la lista, <shown>/<total> entradas mostradas.`
- **S47** — `GETPASS <#channel>` is oper-only and cannot return the secret,
  because it is hashed: it answers `La contraseña de <channel> está cifrada y no
  se puede recuperar. Usa SET PASSWORD para cambiarla.` This is a deliberate,
  approved deviation from `ch.mrc`, which prints the password in clear.
- **S48** — `BFOUNDER <#channel>` is the repair tool: it removes the founder
  record. Our profile is the source, so it removes the profile (channel, access,
  akicks, levels) and publishes the record deletion, answering
  `Borrada la entrada FOUNDER del canal <channel> en el UDB.`
- **S49** — `ESPECIAL` is out of scope: it manages numbered entry bots.
- **S42** — `FORBID <#channel> <reason>` is oper-only, requires a reason and a `#`
  name, refuses a duplicate, and answers `El canal <channel> ha sido prohibido.`
- **S43** — Forbidding a registered channel drops the profile first (channel,
  access, akicks and level overrides), because a forbidden channel cannot stay
  registered.
- **S44** — The forbid reason is published as `C::<channel>::forbid`, and
  `UNFORBID` removes the record and answers `El canal <channel> ha sido liberado.`
- **S45** — `LISTFORBID` prints `Listado de canales prohibidos -> <n>` and
  `<channel> (<setter>) <reason>` per entry, then `Fin de la lista de canales prohibidos.`
- **S38** — `SUSPEND <#channel> <reason>` is oper-only and publishes
  `C::<channel>::suspend` with the reason, so the IRCd itself drops `+r`, refuses
  `+q` and tells joiners why.
- **S39** — While suspended, every mutating command refuses with
  `El canal <channel> está suspendido.`; `INFO` still works, so staff can see why.
- **S40** — `UNSUSPEND` removes the record and answers `El canal <channel> ha sido liberado.`
- **S41** — `LISTSUSPEND` is oper-only and prints `Listado de canales suspendidos`
  then `<channel> - Motivo: <reason>` per channel.
- **S34** — `AKICK <#channel> ADD <nick> [reason]` only accepts a registered nick,
  refuses a duplicate and stops at 50 entries, with the original wording.
- **S35** — `AKICK DEL <nick>` and `AKICK LIST` follow, the list showing
  `Nick: X - Motivo: (setter) -> reason`.
- **S36** — `AKICK ENFORCE` removes the akicked nicknames we can see in the
  channel, as the server, with `(setter) -> reason` as the kick reason.
- **S37** — Automatic enforcement on join belongs to the reactor (T17): the join
  of a plain user in a channel created from a UDB profile is still invisible.
- **S31** — `CLEAR <#channel> <option>` requires the `CLEAR` key (500 by default)
  and answers with the original wording per option.
- **S32** — `CLEAR OPS|HALFOPS|VOICES` removes the rank from every member we know
  holds it, and `CLEAR USERS` removes every known member as the server
  (`SAKICK`), so it works even when ChanServ has no status left after `CLEAR OPS`.
- **S33** — `CLEAR BANS` lifts every mask seen in the `MODE` stream; `BANS` keep
  themselves in `NetworkState` for that reason only.
- **S30** — Membership comes from three places, because the protocol offers
  three: `SJOIN` (a live join, or the burst), the channel `MODE` stream (a mode
  target is always a member, which is how the founder of an empty registered
  channel becomes visible), and nothing else. A plain user joining a channel the
  IRCd has just created from a UDB profile produces **no event at all**, so a
  rank command only refuses a nick it has never seen; the IRCd decides the rest.
- **S29** — `UNBAN <#channel>` sends `MODE <#channel> -b <nick>`, as the original
  does. That only matches a ban written as the bare nick: a mask needs the ban
  list, which we do not track yet. The limitation is documented, not hidden.
## Tasks (this stage)

- **T9** (S8, S9, S10, S11, S12, S14, S15) — channel settings fields, migration,
  `SET` command, `INFO` output.
- **T10** (S13) — `SET PASSWORD` and its effect on `IDENTIFY`.

## Remaining ch.mrc work (specs not written yet)

- **T11** (S16-S21) — **done**. `ChannelLevel` enum with the reference defaults,
  `SqliteChannelLevelRepository`, `LEVELS LIST|SET|RESET`.
- **T12** (S22-S25) — **done**. Levels on access entries, table-driven gates, and
  the `authority()` calculation later commands reuse.
- **T13** (S26-S29) — **done**. `OP`/`DEOP`/`HALFOP`/`DEHALFOP`/`VOICE`/`DEVOICE`,
  `INVITE`, `UNBAN`.
- **T13b** (S31-S33) — **done**. `CLEAR MODES|BANS|OPS|HALFOPS|VOICES|USERS|TOPIC`.
- **T14** (S34-S37) — **done**. `AKICK ADD|DEL|LIST|ENFORCE`, table-driven by
  `AKICK-CHANGE` (499) and `AKICK-LIST` (300).
- **T15a** (S38-S41) — **done**. `SUSPEND`/`UNSUSPEND`/`LISTSUSPEND` with the UDB
  `C::<channel>::suspend` record.
- **T15b** (S42-S45) — **done**. `FORBID`/`UNFORBID`/`LISTFORBID` with the
  `C::<channel>::forbid` record.
- **T15c** (S46-S49) — **done**. `LIST`, `GETPASS`, `BFOUNDER`.
- **T15d** — `ESPECIAL` is **dropped, not pending**: it manages a pool of
  numbered entry bots (`1..11`) that join a channel on demand, and this daemon
  runs no such bots. Porting the command would mean inventing a registry for
  clients we do not have.
- **T16** (S50-S53) — **done**. `SET` switches, `MLOCK`, and the two UDB option
  bits. `NAME` does not exist in `ch.mrc`'s `SET`; `ESPECIAL` was dropped.
- **T17** — the `ch.mrc` event reactor: `on JOIN` (entry message, `+o` from
  levels), `on MODE` (mlock re-application, secureops, deop outside levels),
  `on TOPIC` (topiclock), `on PART`/`on KICK` bookkeeping.

## Log

- **L8** — Picked `SET` first because it is the only part of ch.mrc whose
  behaviour the existing `INFO`, `TOPIC` and founder model already support; the
  rest needs the level table.
- **L9** — Read the module reference before touching the password: UDB 4 has no
  `C::<channel>::P` record, so the channel password stays a services-only secret
  and no block change is needed.
- **L10** — Verified live: `SET DESC/URL/ENTRYMSG` answer with the original
  wording, `INFO` lists them, and `SET PASSWORD` makes `IDENTIFY` accept only the
  new secret. The four columns were added in place to the running database.
- **L11** — `all()` never selected `password_hash`; it only mattered once `INFO`
  and the C block iterate channels. Fixed while touching the same query.
- **L23** — Catches on T16: `NAME` is not a `SET` option in `ch.mrc` (the earlier
  grep list was wrong), and `MLOCK` needs its own validation because the original
  only accepts a fixed letter set and reports the rest instead of applying them.
- **L22** — Two findings while wiring `PERSISTENT`. First, publishing access ranks
  to UDB locked channels to their access list: the module uses
  `C::<channel>::access` as a join allowlist (refusing with 448), not as ranks, so
  the projection no longer touches it and reconcile removes what was already
  there. Second, `PERSISTENT` closes the invisible-join hole: a registered channel
  now exists while empty, so the next visitor's join is a join into an existing
  channel and does send `SJOIN`. Verified live: `JOIN #clavedemo2` now answers with
  the entry message even when nobody was inside.
- **L21** — `ESPECIAL` is the first ch.mrc command we drop on purpose rather than
  defer. It is not a permission or a storage feature: it exists to drive a pool of
  eleven extra client bots that join channels on request, and this daemon has no
  such pool. UBot and CaoS were dropped for the same kind of reason.
- **L20** — Forbid is keyed on the channel name, not on a profile: `ch.mrc` keeps
  it in its own list and drops the registration as a side effect, which is exactly
  what the code does now.
- **L19** — Suspend is the first CHaN feature the IRCd enforces on its own: the
  record is the policy. Our own refusal is belt and braces, and exists so a founder
  understands why their command failed instead of seeing nothing happen.
- **L18** — AKICK is storage plus an explicit `ENFORCE`; the automatic kick on
  join has to wait for T17 for the same reason the entry message did.
- **L17** — `CLEAR` needed two kinds of state we did not keep: who holds which rank
  (already there since the membership fix) and the ban list (added from the `MODE`
  stream). What it still cannot do is reach a member who joined invisibly, because
  there is no event to learn from.
- **L16** — The first join of a registered channel is invisible. The IRCd creates
  the channel from the profile, asks us for the records (`DB ... PUT C::#x::...`)
  and sends the joiner `-o`/`+q`, but no `JOIN` and no `SJOIN`. The `MODE` stream
  rescues the ranked cases, which is why the founder can now be opeed, and a rank
  command no longer refuses a user it knows. A plain user in that situation is
  still invisible: entry messages and future `NOJOIN`/`AUTOOP` cannot reach them
  until we have a second source of membership.
- **L15** — Ranks are applied by ChanServ as a channel member, not as the server:
  `:<chanServUid> MODE #channel +o <uid>`. The server form (`SAMODE`) would work
  too, but then the channel timestamp and the mode ownership belong to us, and
  the original changes modes as a user.
- **L14** — `ACCESS` now scores against the table, so the levels bite. The gate
  moved from "are you the founder" to "is your level enough", which is the whole
  point: a channel can hand `ACC-CHANGE` to a 499 user without making them founder.
  Existing entries migrate with level 0.
- **L13** — Adopted the reference table as option B minus `KICK`: ChatHispano's
  values plus `AUTOHALFOP` and `HALFOPDEHALFOP`, because SitioChat's PREFIX has
  `h` and a halfop with no level would be ungoverned. `KICK` is gone from both
  the table and the command surface.
- **L12** — The entry message now greets on join, replacing `<nick>` as
  `chanserv.c.r` does. Two bugs surfaced on the way. `onJoin`/`onPart` read
  `params[0]`, but `JOIN :#channel` carries the channel in the trailing part, so
  membership was never updated from those events. And Unreal does not send `JOIN`
  to other servers at all: a live join arrives as `SJOIN <ts> #chan :@uid`.
  Greeting therefore sits in the SJOIN handler, gated on `LinkState::Synced` so
  the burst does not greet the whole network.
