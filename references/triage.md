# Triaging an outdated-package list

The single most common mistake when handed a `composer outdated` dump is treating every row as
work. Most rows are not actionable and not problems.

## The two questions that classify every row

### 1. Is it direct or transitive?

A **direct** dependency appears in your `composer.json` `require` / `require-dev`. You control
its constraint. A **transitive** dependency is pulled in by something else; its version is
decided by whatever constraint its parents agree on.

```
composer outdated --direct     # only the ones you actually control
```

Check the same thing precisely with:

```
composer depends vendor/package        # who requires this, and with what constraint
```

Transitive packages showing a newer "Latest" are usually **working as intended**. `guzzlehttp/guzzle`
sitting at 7.x while 8.x exists is not neglect — it is `laravel/framework` requiring `^7.9`.

### 2. Is the gap major, minor, or patch?

Standard semver, with one trap: for `0.x` releases, the **minor** position is the breaking
position. `brick/math 0.18.0 -> 0.19.1` is a major bump, not a minor one. Composer's `^0.18`
caret correctly refuses to cross it.

## The classification

| Class | Pattern | Action |
|---|---|---|
| **Green** | Transitive, same major | Nothing. `composer update` takes it. Do not list individually in the report; count them. |
| **Amber** | Direct, minor bump within the allowed constraint | `composer update` takes it. Read release notes for the framework and any package touching auth, serialization, or HTTP. |
| **Red** | Any major bump (incl. `0.x` minor) | **Not** part of this pass. Diagnose the blocker, report it, move on. |
| **Blocked** | A Red whose blocker is a package you don't control | Report as expected. Revisit when the parent releases a version allowing it. |

## Diagnosing a Red

This is the key command — it tells you exactly why a version cannot be installed:

```
composer why-not guzzlehttp/guzzle 8.0.2
```

Typical output names the constraint holding it back, e.g. `laravel/framework v13.19.0 requires
guzzlehttp/guzzle ^7.9`. That is a complete, correct answer: the package is held back by the
framework, and forcing it would mean an unsupported combination.

Report it as **expected and not actionable**, not as a failure. Phrase it as: "held back by
laravel/framework's `^7.9` constraint — will move when Laravel adopts Guzzle 8."

## When a Red *is* actionable

Only when the package is direct **and** the user asked for that major bump. In that case it is
its own task, not part of a routine update pass:

1. Read the package's UPGRADE guide, all of it.
2. Bump the constraint in `composer.json` by hand.
3. `composer update vendor/package -W`
4. Grep the codebase for every removed/renamed API the guide lists.
5. Full test pass, plus manual verification of the feature area it touches.
6. Its own commit.

Never bundle a major bump into a routine-update commit. If it breaks production, the routine
updates get reverted with it.

## Laravel major/LTS moves

A `laravel/framework` major bump (e.g. 12.x → 13.x) is always its own project:
- Read `https://laravel.com/docs/<version>/upgrade` end to end.
- Check every first-party package (`laravel/sanctum`, `horizon`, `nova`, `telescope`, `pulse`,
  `scout`) for a compatible release **first** — a first-party package lagging one release is the
  usual blocker.
- Check the PHP version floor. Laravel majors routinely raise it, and the deploy target may not
  have it.
- Check community packages with `composer why-not laravel/framework <version>` before starting.
