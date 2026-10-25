## What this changes

Describe the behaviour in one paragraph. If it fixes a bug, say what the user saw
before and what they see now.

| Area | Files | Verification |
|------|-------|--------------|
| Feature or fix | `src/...` | Command run and observed result |

## How it was verified

- Unit tests: `composer test` — total and result.
- Live check against the IRCd (when it touches the protocol, UDB or IRC output):
  the exact commands and what came back.
- Checks **not** run, or failures that are environmental, with the reason.

## References

- Issue: `#<number>` (open an issue first for anything beyond a trivial fix).
- Closes `#<number>` when this merges.

## Checklist

- [ ] The change is one work unit; unrelated refactors are in another PR.
- [ ] Tests cover the new behaviour and any existing behaviour the change touches.
- [ ] `composer check` passes locally (coding standards, PHPStan, tests).
- [ ] User-facing commands and wording match the original dBoTs behaviour, or the
      deviation is explained in the description and in `docs/`.
- [ ] `CHANGELOG.md` has an entry under `Unreleased` when the change is visible
      to users.
- [ ] No secrets, credentials, API keys or live database files are included.
