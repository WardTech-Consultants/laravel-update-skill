# laravel-update

A [Claude Code](https://claude.com/claude-code) skill that applies Laravel, Composer, and npm
dependency updates and ends with a **go-live verdict** backed by a before/after test comparison —
not just a green `composer update`.

Paste a list of outdated packages (or nothing at all) and it will:

1. Profile the project — DDEV/Sail/native, Pest or PHPUnit, Blade/Inertia/Livewire/API-only
2. Run the test suite **before** touching anything, so failures can be attributed
3. Triage the outdated list into what's updatable, what's pinned upstream, and what needs a
   deliberate major-version project
4. Apply the update and clear the caches that mask real breakage
5. Re-run the identical checks, plus `composer audit`
6. Hunt for breaking changes in the seams that thin test suites miss
7. Report **GO / GO WITH CONDITIONS / NO-GO**, stating explicitly what was *not* tested
8. Commit only when green
9. Then repeat for npm, as a **separate** pass with its own commit

## Install

```bash
git clone https://github.com/WardTech-Consultants/laravel-update-skill.git \
  ~/.claude/skills/laravel-update
```

Restart Claude Code — skills are discovered at session start. That's it; it's now available in
every project on your machine.

To update later: `git -C ~/.claude/skills/laravel-update pull`

## Use

Start Claude Code in any Laravel project and ask in plain language. There is nothing to
configure — Phase 0 detects the runtime, test runner, and stack before it touches anything.

### Composer

Ask for it:

```
update the composer packages
```

Or paste a `composer outdated` table — from the CLI, or from a dashboard that reports it:

```
Name              Installed   Latest    Status
laravel/framework v12.4.0     12.9.1    Outdated
guzzlehttp/guzzle 7.14.0      8.0.2     Outdated
```

Or invoke it by name, with no list at all:

```
/laravel-update
```

It runs `composer outdated` itself when you don't supply one. Either way `composer.lock` is the
source of truth, so a stale or partial list won't mislead it.

### npm

Same skill, Phase 8. Ask for it:

```
update the npm packages
```

Or paste `npm outdated`:

```
Package             Current  Wanted  Latest
vite                8.1.4    8.2.1   8.2.1
tailwindcss         4.3.2    4.3.3   4.3.3
```

`npm outdated` exits **1** whenever anything is outdated — that is not an error, and the skill
won't treat it as one.

### Both

```
update composer and npm
```

Runs Composer through to a commit, then npm through to a **second, separate** commit. The order
matters and the separation matters: a white-screen deploy could be the framework or the asset
pipeline, and two commits let you revert one and know immediately which it was.

You don't need Composer to have run first. Phase 8 stands alone — "after the Composer commit" only
means *if you're doing both, do them in that order*.

### What you get back

A go-live report, not a wall of terminal output:

```
## Verdict: GO

31 packages, zero major bumps, laravel/framework v13.19.0 -> v13.24.0.
Closes 12 security advisories (composer audit: 12 -> 0).

Held back (expected):  guzzle 8.x, brick/math 0.19  -- pinned by laravel/framework
Tests:                 13 passed before and after; E2E identical per browser
Untested surface:      real SMTP delivery, live reCAPTCHA
```

Followed by a commit, if you got a GO. If you got a NO-GO, a numbered remediation plan instead —
cause, fix, effort, and whether it can be deferred.

## Why the verdict, not just the update

`composer update` succeeding tells you the dependency graph resolved. It doesn't tell you whether
the site still works. This skill exists because the gap between those two things is where
production incidents live:

- **A red test after an update isn't proof the update caused it.** The skill requires a control
  experiment — restore the old lockfile, re-run the same command — before reporting any
  regression. Interrupted installs and non-idempotent tests (rate limiters, cache counters) fake
  regressions convincingly.
- **Most of a `composer outdated` dump isn't actionable.** Transitive packages pinned by a parent
  constraint look neglected and aren't. `composer why-not` turns "26 packages outdated" into "22
  routine, 4 blocked upstream, 0 needing code changes."
- **Green tests are necessary, not sufficient.** Typical Laravel suites don't cover the seams
  framework upgrades actually move: cookie/`SameSite` defaults, mail DSN parsing, serialized jobs
  already sitting in a queue, Inertia client/server version drift, asset manifests.
- **Coverage gets stated honestly.** Every area is reported as verified, reviewed, or untested.
  A GO verdict that overstates coverage is worse than no verdict.

## What it adapts to

| Detects | Uses |
|---|---|
| `.ddev/`, Sail | `ddev composer`, `sail artisan` instead of native |
| Pest vs PHPUnit | Whichever is installed, preferring your `composer test` script |
| Inertia / Livewire / Blade / API-only | Different breaking-change checks per stack |
| PHPStan, Larastan, Psalm, Rector, Pint | Added to both test phases |
| Playwright, Dusk, Cypress | Run as E2E, or reported as untested surface if they can't run locally |
| Vite | `npm run build` becomes part of verification |

## Layout

```
SKILL.md                        the workflow
references/triage.md            classifying an outdated list; diagnosing blocked majors
references/breaking-changes.md  seams a green suite misses, per stack
references/report.md            go-live report format
scripts/lock-diff.php           diffs two composer.lock files, classifies each change
```

`references/` loads only when needed, so the workflow stays cheap to read.

`lock-diff.php` is useful standalone:

```bash
php scripts/lock-diff.php composer.lock.bak composer.lock composer.json
```

It marks each change direct or transitive and patch/minor/**MAJOR**, correctly treating a `0.x`
minor bump as a major.

## Requirements

PHP 8.1+, Composer 2, and a Laravel project. No other dependencies.

## Conventions it assumes

Two choices are baked in that you may want to change in `SKILL.md`:

- Updates commit to the **current branch**, no update branch, on the reasoning that a
  lockfile-only commit is trivially revertible.
- npm is a **second pass with its own commit**, run only after the Composer commit is green. A
  white-screen deploy could be the framework or the asset pipeline; separate commits keep that
  bisectable.

## License

MIT
