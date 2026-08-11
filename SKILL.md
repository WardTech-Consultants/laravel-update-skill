---
name: laravel-update
description: Safely apply Laravel core, Composer, and npm dependency updates on any Laravel project (API-only, Blade, Livewire, or Inertia). Use when the user pastes a list of outdated packages, asks to update Laravel / Composer / npm / dependencies, asks whether an update is safe to deploy, or asks for a go-live decision on a dependency bump. Runs a baseline test pass, updates, re-tests, hunts for breaking changes, gives a GO / NO-GO verdict, and commits only when green.
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
| npm deps? | `package.json` → Phase 8 applies, as a second pass with its own commit |

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
- Do **not** touch npm here. If the project has npm dependencies they get their own pass and
  their own commit — Phase 8. Mixing the two makes a failure much harder to bisect, because a
  white-screen deploy could be either the framework or the asset pipeline.

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

## Phase 8 — npm, as a second pass

Only if the project has a `package.json`, and only **after** the Composer commit has landed
green. Same discipline, same evidence, separate commit. Skip entirely if the user asked only
about Composer — but tell them npm is also outdated if it is, rather than silently ignoring it.

**Read the columns properly.** `npm outdated` gives you the triage for free:

```
Package  Current  Wanted  Latest
vite     8.1.4    8.1.9   9.0.1
```

- **Current → Wanted** — allowed by your `package.json` range. Routine; `npm update` takes it.
- **Wanted → Latest** — a major outside the range. Needs a deliberate `npm install pkg@latest`
  and a range edit. Treat exactly like a Red in Phase 2: not part of this pass.

`npm outdated` exits **1** when anything is outdated. That is not an error — don't report it as one.

```
npm outdated            # triage
npm update              # moves to Wanted, within existing ranges
npm audit               # advisories; note that dev-only ones rarely warrant a forced upgrade
```

Never run `npm audit fix --force`. It installs major versions to clear advisories and will
happily break your build to silence a warning in a dev dependency that never reaches production.

**Verification is the build, not a test suite.** There is usually no npm-side unit test in a
Laravel project, so the real checks are:

```
npm run build           # must succeed
```

Then confirm `public/build/manifest.json` regenerated, and re-run the browser/E2E suite from
Phase 1. A stale or malformed manifest produces an unstyled page or a white screen with **no
server-side error** — nothing in the PHP suite will catch it.

**Node's own floor moves.** Vite and Tailwind majors routinely raise the minimum Node version.
Check `engines` in `package.json` and, more importantly, the Node version on the deploy target.
A build that works locally and fails in CI is almost always this.

### Stack-specific traps

- **Inertia** — `@inertiajs/*` and `inertiajs/inertia-laravel` are two halves of one protocol. A
  Composer-only or npm-only pass can desync them. Check both versions agree before shipping
  either; this is the single most common Inertia breakage.
- **Tailwind** — v3 → v4 is a config and directive rewrite, not a version bump. Its own project.
- **Vite** — majors often break `laravel-vite-plugin` compatibility. Check the plugin supports
  the new major *before* updating Vite, not after.
- **Playwright** — bumping `@playwright/test` may need `npx playwright install` for matching
  browsers, or the suite fails in a way that looks like an app regression.

### Commit

Separate from the Composer commit, `package.json` + `package-lock.json` only:

```
Update npm dependencies

Update <N> packages, including <notable>.

Held at current majors: <list, with what each would require>.
Build succeeded and <e2e status>.
```
