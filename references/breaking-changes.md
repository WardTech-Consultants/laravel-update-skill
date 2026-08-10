# Hunting breaking changes a green test suite will miss

A typical Laravel app tests its own controllers and a few units. It does not test the seams
where framework and Symfony changes actually land. Check the seams that apply to the stack you
detected in Phase 0 — skip the rest rather than padding the report.

## Always check

**Config files vs. their upstream defaults.** Laravel ships config changes in minor releases;
your published `config/*.php` files do not update themselves. New keys silently fall back to
`null` rather than the intended default.

```
php artisan about                        # boots the app, surfaces driver misconfiguration
git diff --stat vendor/laravel/framework/config/   # if vendor is tracked; otherwise compare by hand
```

**Deprecations.** Turn them into visible signal for one test run:

```
php artisan test --display-deprecations
```

A deprecation today is a break at the next major. Note them; they are rarely blocking.

**Security.** `composer audit` after every update. An open advisory is an automatic NO-GO
regardless of test colour.

## Symfony component bumps

Laravel sits on Symfony's HttpFoundation/HttpKernel/Console/Mailer. Even patch bumps here can
change behaviour that tests do not cover:

- `symfony/http-foundation` — cookie `SameSite` defaults, trusted proxy/header handling, request
  URI normalisation. Affects anything behind a load balancer or CDN. Check `TrustProxies`.
- `symfony/mailer` + `symfony/mime` — DSN parsing, header encoding, attachment MIME detection.
  If mail is tested with `Mail::fake()`, real SMTP delivery is **untested surface**. Say so.
- `symfony/console` — signal handling and exit codes; affects scheduled commands and queue workers.
- `symfony/routing` — trailing-slash and encoding behaviour in generated URLs.

## Stack-specific seams

**Blade / server-rendered**
- Compiled views cached against old Blade internals → `view:clear` is mandatory, not hygiene.
- Component attribute-bag and slot rendering changes show up as subtle markup diffs, not errors.
- Verify the rendered pages, not just HTTP 200s.

**Inertia**
- `inertiajs/inertia-laravel` version must match the client `@inertiajs/*` package's expectations.
  A Composer-only update can desync the two.
- Shared-data and partial-reload behaviour changes are invisible to PHP feature tests.
- Asset versioning / cache-busting: a stale manifest yields a white screen with no server error.

**API-only**
- Resource/serialization changes alter response shape — check `JsonResource` wrapping, date
  serialization format (`Carbon` bumps have changed default `toJSON` output historically), and
  numeric precision (`brick/math` sits under money/decimal handling).
- Validation error response shape and status codes.
- Sanctum/Passport token behaviour after auth-adjacent bumps.

**Livewire**
- Component hydration/dehydration is version-sensitive; the JS asset and PHP package must match.
- `php artisan livewire:publish --assets` if assets are published rather than served.

## Queues, schedule, and cache

Serialized payloads already sitting in the queue were written by the **old** code. After a bump
to `laravel/serializable-closure`, `nesbot/carbon`, or the framework's queue internals, in-flight
jobs can fail to unserialize on the new code.

- If the deploy target has a non-empty queue, drain it before deploying, or accept failed jobs.
- Cached objects in Redis/Memcached have the same problem. Plan a cache flush if serialization
  formats moved.
- This never shows up in tests (which use the `sync`/`array` drivers) and is the most common
  real-world post-update incident. Call it out in the verdict when relevant.

## Asset pipeline

If `vite` or its Laravel plugin moved, a passing PHP suite says nothing about the front end:

```
npm run build          # must succeed and produce a fresh manifest
```

Check `public/build/manifest.json` regenerated. Confirm the deploy pipeline runs a build — a
`vite` major bump that changes output paths breaks production while local dev works fine.

## The honesty rule

The report must distinguish three states for every area:

1. **Verified** — a test or command you actually ran covers it and passed.
2. **Reviewed** — you read the change and reasoned it is safe, but nothing executed it.
3. **Untested** — no coverage. Mail delivery, payment webhooks, queue workers, and third-party
   integrations usually land here.

Never let (2) or (3) be presented as (1). A GO verdict with an explicit untested list is useful;
a GO verdict that overstates coverage is worse than no verdict.
