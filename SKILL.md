---
name: laravel-update
description: Safely apply Laravel core and Composer dependency updates on any Laravel project (API-only, Blade, Livewire, or Inertia). Use when the user pastes a list of outdated packages, asks to update Laravel / Composer / dependencies, asks whether an update is safe to deploy, or asks for a go-live decision on a dependency bump. Runs a baseline test pass, updates, re-tests, hunts for breaking changes, gives a GO / NO-GO verdict, and commits only when green.
---

# Laravel & Composer update

Apply dependency updates with evidence, not hope. The deliverable is a **go-live verdict**
backed by a before/after test comparison — not just a green `composer update`.

Never skip the baseline test run. Without it you cannot tell an update-caused failure from a
pre-existing one, and the whole verdict is worthless.

## Phase 0 — Profile the project

Every project differs. Detect before assuming:

| Question | How to check |
|---|---|
| Runtime wrapper? | `.ddev/` → prefix `ddev `; `docker-compose.yml` + `vendor/bin/sail` → `sail `; else run natively |
| Front-end stack? | `composer.json` for `inertiajs/inertia-laravel`, `livewire/livewire`; `resources/views/*.blade.php`; no views + `routes/api.php` only → API-only |
| PHP test runner? | `vendor/bin/pest` exists → Pest; else PHPUnit. Prefer the `test` script in `composer.json` |
| Browser tests? | `playwright.config.*`, `tests/Browser` (Dusk), `cypress.config.*` |
| Static analysis? | `phpstan.neon*`, `larastan`, `psalm.xml`, `rector.php` |
| Formatter? | `pint.json` or `laravel/pint` in require-dev |
| Assets? | `vite.config.*` → `npm run build` is part of verification |

Record the exact commands you will use. Use them consistently in both test phases — a baseline
run with different flags than the after run proves nothing.

**Hard precondition.** Stop and ask before doing anything if the working tree is dirty
(`git status --short` non-empty) — uncommitted work must not get tangled with a dependency bump.

Do **not** create an update branch. Routine dependency updates commit straight to whatever
branch is checked out, `main` included. The lockfile snapshot below is the rollback path and a
single-file commit is trivial to revert, so a branch only adds a merge step to a change that is
either green or reverted.

Always snapshot the lockfile first: `cp composer.lock composer.lock.bak`. It is the rollback
point and the input to the diff report. Delete it before committing.

## Phase 1 — Baseline (must be green)

Run, in this order, capturing full output:

1. PHP test suite (`composer test`, or `php artisan test`, or `vendor/bin/pest`)
2. Static analysis, if configured
3. Asset build, if there is a bundler
4. Browser/E2E suite, if configured and runnable locally

**If the baseline is red, stop.** Report exactly which tests already fail and ask whether to
(a) fix them first, or (b) proceed with those failures recorded as the known-bad baseline.
Do not silently proceed — a pre-existing failure quietly absorbed into an update commit is how
regressions ship.

If the E2E suite needs a running server or a real database and cannot run locally, say so
explicitly and treat it as **untested surface** in the final verdict. Never report a verdict
that implies coverage you did not actually execute.

## Phase 2 — Triage the outdated list

Do not treat the list as a to-do list. Most entries in a typical `composer outdated` dump are
transitive packages pinned by a parent constraint, and are neither updatable nor a problem.

Read `references/triage.md` for the full classification method. The short version:

- **Green** — patch/minor bump, same major, transitive → `composer update` handles it.
- **Amber** — direct dependency, minor bump → handled, but read its release notes.
- **Red** — major bump (or `0.x` minor, which is a major in semver terms). Never force these in
  the same pass. Run `composer why-not vendor/pkg <version>` to find the blocker, then report it.

Produce a triage table before touching anything. It is what turns "26 packages outdated" into
"22 routine, 4 blocked upstream, 0 requiring code changes."

## Phase 3 — Update

Preview first, then apply:

```
composer update --dry-run          # read this before running it for real
composer update                    # respects composer.json constraints
```

If it dies with `Could not authenticate against github.com`, the token in
`~/.composer/auth.json` is expired or revoked — not a dependency problem. Confirm it, without
echoing the token into the transcript:

```
curl -s -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer $TOKEN" https://api.github.com/user
```

`401` means expired. Public packages install fine anonymously, so finish the run with a throwaway
Composer home rather than editing the user's global config, and tell them to regenerate the token:

```
COMPOSER_HOME=$(mktemp -d) composer install
```

Private VCS repos still resolve if the user's own `git` credentials work — check with
`git ls-remote <url> HEAD`. Whatever happens, the tree is half-updated at this point, so treat
Phase 4 results as invalid until a clean `composer install` has completed.

Rules:
- Do **not** use `-W` / `--with-all-dependencies` unless you are deliberately bumping a direct
  constraint and have said so.
- Do **not** hand-edit `composer.json` constraints to chase a Red package in this pass.
- If the project has npm dependencies, keep them in a **separate** step and a separate commit
  unless the user asked for both. Mixing them makes bisecting a failure much harder.

Then clear stale state — cached config/routes/views compiled against the old code cause
failures that look like real breakage:

```
php artisan config:clear && php artisan cache:clear
php artisan view:clear && php artisan route:clear
composer dump-autoload
```

## Phase 4 — Re-test

Re-run **exactly** the Phase 1 command set. Then add:

- `composer audit` — known CVEs in the new tree
- `php artisan about` — confirms the app boots at all
- `php artisan route:list` — catches container/provider breakage a unit test may miss
- `php -l` is not needed; the test suite covers syntax

Any test that passed in Phase 1 and fails now is **suspected** to be caused by this update.
Confirm it before reporting it — see below.

### Confirming a regression is real

A red test after an update is not proof the update caused it. Two things routinely fake a
regression, and both will burn an hour if you trust the first red run:

**An interrupted install.** If `composer update` died partway (network, auth, disk), the
lockfile is rewritten but `vendor/` is a mix of old and new. Every test result from that tree is
meaningless. Always confirm the install actually completed before believing any failure:

```
composer install    # must exit clean
php artisan optimize:clear
```

`optimize:clear`, not individual `*:clear` calls — it also drops the **event** and **compiled**
caches, which the individual commands leave behind.

**Non-idempotent tests.** Suites that mutate shared server state fail on the second run
regardless of dependencies. The usual culprits:
- Rate limiters (`throttle:n,m` on a route). A browser suite running N tests across 3 browser
  projects fires 3N requests at a limit sized for a human. Failures then move around between
  runs and spread across browsers — that shifting pattern is the tell, and it means *flaky*,
  not *regressed*.
- Anything counting in the cache, DB rows without a transaction rollback, or files in `storage/`.
- Your own manual `curl` probes share the same limiter bucket as the tests. Clear it after
  probing: `php artisan cache:clear`.

**The control experiment settles it.** Never report a regression on a single-tree observation.
Restore the old lockfile, reinstall, and run the *same command in the same state*:

```
cp composer.lock.bak composer.lock && composer install && php artisan optimize:clear
<the exact failing test command>
```

- Fails on the old tree too → pre-existing or flaky. The update is innocent.
- Passes on the old tree, fails on the new → real regression. Now bisect with
  `composer update <package>` one at a time to name the culprit.

Then restore the new lockfile before continuing. Keep a copy at `composer.lock.new` so you can
flip between the two trees cheaply.

If the suite is rate-limit-bound, get a clean signal by running one browser project at a time
with the limiter cleared between each, so each run stays under the cap:

```
for p in chromium firefox webkit; do php artisan cache:clear -q; npx playwright test --project=$p --workers=1; done
```

## Phase 5 — Hunt for breaking changes

Green tests are necessary, not sufficient — most Laravel apps have thin coverage over the exact
seams that framework upgrades move. Read `references/breaking-changes.md` and check the seams
that actually apply to this project's stack.

Generate the real diff of what changed (not the user's pasted list, which may be stale):

```
php <skill>/scripts/lock-diff.php composer.lock.bak composer.lock
```

For every package that moved a **minor** version or more, skim its release notes. Laravel itself:
compare `https://github.com/laravel/framework/blob/13.x/CHANGELOG.md` between the two versions.

## Phase 6 — Verdict

Give one of three, and commit to it. Hedging is not a verdict.

- **GO** — baseline green, after green, no unreviewed major bumps, no CVEs, no risky seams touched.
- **GO WITH CONDITIONS** — safe to deploy but something needs watching or a manual smoke check
  post-deploy. Name the specific thing and who checks it.
- **NO-GO** — a test regressed, a CVE is open, or a change lands on a seam this project's tests
  do not cover. Say what breaks and what it costs to fix.

Use the structure in `references/report.md`. Always state what was **not** tested.

If NO-GO: give a numbered remediation plan — cause, fix, effort, and whether it can be deferred.
Then fix it (with the user's go-ahead), and re-run Phase 4 before revisiting the verdict.

## Phase 7 — Commit

Only after a GO or GO WITH CONDITIONS. Remove `composer.lock.bak` and `composer.lock.new` first,
then commit `composer.json` + `composer.lock` (and `package-lock.json` only if npm was in scope)
**on the current branch** — no update branch, no `git checkout -b`.

```
Update Composer dependencies

Update <N> packages, including laravel/framework <old> -> <new>.

<one line per notable package>

Held back by upstream constraints: <list, with the blocking package>.
Test suite: <X> passed before and after; <e2e status>.
```

Do not add "Generated with Claude Code" trailers unless the project's other commits use them.
Never `git push` unless asked.
