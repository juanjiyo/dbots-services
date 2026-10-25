# dbots — ChanFix (candidate official module)

Status: **parked on purpose**. Studied, not scheduled. Revisit when there is
appetite and a clock in the daemon.

## What it is

Automatic recovery of a channel that has lost every operator: score who used to
hold op, then hand op back to the people the scores support. Atheme ships one
(`modules/chanfix`), and SitioChat already runs a simpler take in
`bots/shadow/shadow_bot.py`.

## What the Atheme reference actually does

- `score = age × (1.5 if the user has an account)`, where age counts five-minute
  samples seen holding op, retained for 28 days.
- Acts only under `CHANFIX_OP_THRESHHOLD` (3) ops, and **only on channels that are
  not registered** — the opposite of what one expects.
- Minimum score of 12 before it fixes anything.
- The threshold decays over the fix window: 70% of the best score at the start,
  30% after an hour, then it stops insisting. It would rather leave the channel
  broken than op the wrong person.
- Lowers the channel timestamp so its own op wins against other servers, clears
  bans that block the fix, and refuses channel registration while a fix is running.
- Manual `FIX` first; `AUTOFIX` is a config flag, off by default.

## What porting it needs from us

- **A clock.** The daemon only reacts today; a module needs a periodic tick.
  That is platform work, not module work.
- **Persistence with decay** for the op records: a table, not a JSON file.
- **A rule decision.** Our registered channels already have a founder and `+q`
  from UDB, so ChanFix here is only meaningful for **unregistered** channels that
  lose their ops.

## Log

- **L1** — Reviewed on request after removing `BFOUNDER`. They cover different
  ground: `BFOUNDER` repaired a database contradiction, ChanFix repairs a channel
  with no operators.
- **L2** — Kept as a candidate official module: it is the clearest example of what
  the module system exists for — behaviour that watches the network and acts,
  without owning a nickname or a command surface.
