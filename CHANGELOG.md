# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

### Added

- CHaN: `LEVELS`, `SET`, `ACCESS` with levels, rank commands, `CLEAR`, `AKICK`,
  `SUSPEND`, `FORBID`, `LIST`, `GETPASS` and `BFOUNDER`.
- CHaN: the join reactor — entry message, akick ban and kick, `NOJOIN` threshold
  and the rank the level table grants.
- HeLP: the global help service, with `AYUDA`, `UMODES`, `CMODES`, `BOTS`, `NICK`,
  `CANAL` and `CREDITOS`.
- CReG: channel passwords, and memos to the founder on approval or denial.
- MeMo: unread notice on identification and `READ LAST`.
- CI: coding standards, PHPStan and tests on every push and pull request.

### Changed

- UDB: registered channels publish `PERSISTENT`, so they survive being empty and
  their joins stay visible to the services.
- UDB: `SECUREOPS` maps to the `SECURE_OPS` option bit, so the IRCd enforces that
  only `+q` grants ranks.

### Fixed

- MeMo `HELP` and `NickServ STATUS` crashed on input they should have answered.
- UDB: channel access ranks are no longer published, where the module reads
  `C::<channel>::access` as a join allowlist.
- Daemon: `JOIN` and `PART` read the channel from the wrong parameter, and Unreal
  announces live joins as `SJOIN`, not `JOIN`.

## [0.1.0] - 2026-10-13

### Added

- S2S link to UnrealIRCd 6 and the UDB 4 authority, verified byte-for-byte
  against the module reference.
- `NiCK`, `CHaN`, `MeMo` and `OPeR` with their first command surfaces.
