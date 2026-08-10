# Go-live report format

Lead with the verdict. The user's first question is "can I deploy this?" — answer it in the
first line, then support it. Keep the whole thing skimmable; detail goes in collapsed sections
or below the fold, never in front of the verdict.

```markdown
## Verdict: GO / GO WITH CONDITIONS / NO-GO

<One or two sentences. What changed, and why that verdict.>

### What updated
<N> packages. laravel/framework <old> → <new>.

| Package | From | To | Type |
|---|---|---|---|
<only direct deps and anything with a minor+ bump — do not list 20 transitive patch bumps>

Plus <N> transitive patch/minor bumps (Symfony components, polyfills).

### Held back (expected, not actionable)
| Package | Installed | Latest | Blocked by |
|---|---|---|---|
<the Red rows, each with the output of `composer why-not`>

### Tests
| Check | Before | After |
|---|---|---|
| Unit + Feature | <n> passed | <n> passed |
| Static analysis | <result> | <result> |
| Asset build | <result> | <result> |
| E2E | <result or "not run — reason"> | <result or "not run — reason"> |

`composer audit`: <no advisories / list them>

### Risk review
- **Verified** — <areas an executed test actually covered>
- **Reviewed** — <changes read and judged safe, but not executed>
- **Untested** — <areas with no coverage: mail delivery, queues, integrations>

### Before you deploy
<Only if GO WITH CONDITIONS. Concrete steps: clear caches, drain queue, rebuild assets,
smoke-check a named page. Omit this section entirely for a clean GO.>
```

## If NO-GO

Replace the tail with a remediation plan. Be specific enough to act on:

```markdown
### What broke
<Test name, error, and the package change that caused it.>

### Plan
1. **<Fix>** — <what to change, in which file>. Effort: <minutes/hours>. Blocking: yes/no.
2. ...

### Fallback
Roll back with `cp composer.lock.bak composer.lock && composer install`
<or: revert the commit, if already committed>
```

## Tone rules

- No hedging in the verdict line. If evidence is insufficient for GO, that is a NO-GO or a
  conditioned GO with the gap named — not a vague "should be fine".
- Do not pad. 22 transitive patch bumps are one line, not 22 rows.
- Name the untested surface even when everything passed. That is the sentence that earns trust
  in the GO verdicts.
