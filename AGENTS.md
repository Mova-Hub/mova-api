# Laravel 12

This is Laravel 12 on PHP 8.2+. Check the framework version before reaching for
a pattern from an older release. `bootstrap/app.php` holds middleware aliases
and routing, there is no `Kernel.php`, and column changes work natively without
doctrine/dbal.

# Shipping changes

## When to push

After any significant change, not only a major one. Significant means anything
a reviewer would want to see, or would be surprised to find already on `main`:

- a new endpoint, or a change to an existing route's shape
- a migration, in either direction
- anything touching a gate: middleware, roles, policies, channel authorisation
- a new notification, event, job, or console command
- a change to a Resource, which is a public contract the apps are built on
- a bug fix that changes behaviour rather than wording

Not significant, keep working: typos, comment rewording, formatting, a rename
with no callers outside the file.

Ask before pushing. Pushing is outward facing and awkward to undo, so it is
never automatic, even when the change is clearly significant.

Opening a pull request is where the work stops. Approving and merging belong to
the repository owner alone.

## How

1. Branch. Never commit to `main` directly. Name the branch for the work:
   `feat/field-missions`, `fix/coordinator-notification`, `chore/bump-reverb`,
   `style/remove-em-dashes`.

2. Stage only the files belonging to this change. Never `git add -A`. This
   working tree regularly carries unrelated edits, for example a formatter pass
   that rewrites punctuation across files nobody touched. Sweeping those into a
   feature commit makes the diff unreviewable.

3. Commit with a message that says what changed and why, not what the files are.

4. Push the branch and open a pull request with `gh pr create`.

Never commit `.env`, `firebase-credentials.json`, or anything holding a real
credential. `.env.example` documents keys with empty values and nothing else.

## What a pull request must contain

A reviewer should not have to read the diff to know what happened.

- **What changed**, in plain sentences, grouped by area rather than by file.
- **Why**, including the problem being fixed. If a defect was found while
  working, say what the symptom was and what caused it.
- **How it was verified.** Name the actual commands run and their result.
- **What was not verified.** Anything needing a real database, a live provider,
  or a deploy belongs here. Do not imply coverage that does not exist.
- **Whether it needs a migration or an env change on deploy**, spelled out.
- **Any follow-up left behind**, or say explicitly that there is none.

## Writing rules for commits and pull requests

These are house style. They apply to titles, bodies, headings, and bullet text.

- No em dashes. Use a comma, a colon, or a full stop.
- No emoji and no icons, anywhere.
- No decorative headers, badges, or ASCII art.
- Plain sentences. Say the thing directly.

The same applies to code comments in this repo.

## Before pushing

Run these, and put the results in the pull request.

```
php -l <each changed file>
php artisan route:list --path=<the routes you touched>
```

If a migration is involved, run it against a throwaway SQLite file before it
ever sees MySQL:

```
touch /tmp/check.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/check.sqlite php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE=/tmp/check.sqlite php artisan migrate:rollback --step=1
```

Roll it back as well as forward. A migration that cannot reverse is a migration
that turns a bad deploy into an outage.

Two traps this has already caught:

**SQLite enforces enums with a CHECK constraint.** It does not store them as
free text. Adding a value to an enum column without widening the constraint
fails with `CHECK constraint failed`, and only on SQLite, which is what the
verification databases use. Use `$table->enum(...)->change()`, which Laravel 12
handles natively on both drivers.

**`validate()` returns only the keys that have rules.** A `nullable` rule does
not put an absent field in the result, so `$data['thing']` on an optional field
raises "Undefined array key" the moment somebody leaves it blank. Use
`$data['thing'] ?? null`. This silently dropped waypoint labels for a fortnight.

## Invariants a pull request must not break

These are load bearing. If a change touches one, say so in the pull request and
explain why it is still safe.

- **`auth:sanctum` alone does not mean staff.** `Client` owns tokens too. Every
  back office route needs `staff`; the field app uses `field`, which is a
  deliberately separate and narrower gate. Do not add roles to
  `User::STAFF_ROLES` to make something work, that constant is what gates the
  client list and the payments ledger.
- **Payment amounts come from the payable, never from the request.** A caller
  posting an amount would be naming their own price.
- **Broadcast channel callbacks branch on model type.** Sanctum resolves either
  a `Client` or a `User`, so a callback that assumes one is a hole.
- **Notifications fire after the transaction commits**, never inside it. A
  rollback would otherwise tell somebody to run a trip that does not exist.
- **Location and audit data has a retention job.** If a change stores personal
  data, it needs pruning, and the pull request should say where.

## Deploying

There is no CI. `.github/workflows/deploy.yml` is entirely commented out, so
merging to `main` does not build, test, or deploy anything. Deployment is
`deploy.sh` run by hand on the server.

That script runs `php artisan config:cache`, which means editing `.env` on the
server changes nothing until the cache is rebuilt:

```
php artisan config:clear && php artisan config:cache
```

Getting this wrong is quiet rather than loud. The application keeps using the
previous value and reports no error.

Hosting is shared cPanel. It cannot run a persistent daemon, which is why the
queue runs as `queue:work --stop-when-empty` on a per minute cron rather than a
worker, and why websockets use hosted Pusher rather than a self hosted Reverb
process.
