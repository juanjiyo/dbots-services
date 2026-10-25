# Contributing

Start with an issue. A pull request without one is only acceptable for a typo or
a one-line documentation fix.

## Path

1. **Open an issue** with the problem, the intended behaviour and how we will know
   it works. One issue per feature.
2. **Branch** from `main` with a descriptive name (`feat/chan-akick`,
   `fix/udb-suspend-record`).
3. **One work unit per commit**, with tests and documentation next to the code
   they describe. Commit messages follow Conventional Commits and carry no
   attribution trailers.
4. **Run `composer check`** before opening the pull request. It runs the coding
   standards, PHPStan and the test suite, in that order.
5. **Open the pull request** and fill in the template. If the change touches the
   IRC protocol, UDB records or a command surface, include what you saw on a live
   link, not only green tests.

## What "verified" means here

Green tests are the minimum. This project talks to a real IRCd, and several of its
hardest bugs were invisible to unit tests: joins that never arrive, records that
vanish on reconcile, migrations that never ran. When you touch any of that, say
what you ran against the live network and what came back.

## Versioning and history

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/). Add your
entry under `Unreleased`; releases are cut from `main`.

## Review

`main` is protected by review. Address review comments with new commits; do not
force-push over a review in progress.
