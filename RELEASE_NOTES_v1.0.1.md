# Shorts Studio v1.0.1 — Multi-user workspaces

v1.0.0 was a single-owner app. **v1.0.1 lets many people use one installation**, each in a private workspace with their
own YouTube channel, their own Google Cloud project (and therefore their own API quota), videos, audio and schedule.

> v1.0.1 was developed on the `release/1.0.1` branch. The `v1.0.0` tag and `main` were not changed.

## What's new

### Private workspace per user
- Every video, audio track, YouTube connection and setting belongs to one user. Users cannot see, open or change each
  other's data (videos, thumbnails, previews and audio are all access-checked).
- Settings are stored per user: posting times, time zone, frequency, videos per day, privacy, category, tags, pause,
  upload mode and notification preference.
- Generated files are kept in per-user folders (`users/{id}/…`).
- Puzzle fingerprints and publishing slots are now unique **per user**, so two users can post at the same time.
- Deleting a user deletes their data and files.

### Bring your own Google Cloud project
- Each user saves their own OAuth client ID and secret on the **YouTube** page (paste the downloaded JSON or type the
  values). The secret is encrypted at rest and never shown again.
- Because every user has their own project, **upload quota is per user** (the 1,600-unit uploads no longer compete
  across users), and the 100-user cap of an unverified Google app no longer applies to the installation.
- The page contains a step-by-step setup guide and the exact redirect URI to register.
- Switching to a different Google project disconnects the old channel so a stale token is never used.
- The YouTube quota "wait until reset" state is tracked per user.

### Accounts and roles
- **Administrator**: the first user is made the administrator on upgrade. Only administrators see the Deployment page
  and the queue-wide panels.
- **Sign-up** is optional and **off by default** (`REGISTRATION_ENABLED=true` turns it on). A register page was added.
- **Email verification is enforced** again (the user model had lost `MustVerifyEmail`), so unverified accounts cannot
  use the app.
- The "published" email is sent to the account that owns the video (it replaces the single `NOTIFY_EMAIL`); users can
  turn it off.

### Background work runs per user
- Auto-generate, auto-schedule, re-fit, publish and cleanup commands loop over users and use each user's own settings.
- Auto-generation only runs for users with a working YouTube connection, and a user can have at most
  `SHORTS_MAX_PENDING_RENDERS` (default 100) videos waiting to render, so one user cannot starve the others.

### Other
- App version `1.0.1` (`config('app.version')`).
- Factories for videos and audio tracks; new tenancy tests (23 tests) covering isolation, per-user settings, slots,
  credentials, quota and admin access.
- `DatabaseSeeder` creates the administrator from `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`.
- Privacy policy updated to describe per-user credentials.

## Upgrading from v1.0.0

1. Let the queue drain (the dashboard "Queued jobs" should be 0). Queued `GenerateVideosJob` items from v1.0.0 cannot
   run on v1.0.1.
2. **Back up the database.**
3. Deploy `release/1.0.1` (or the `v1.0.1` tag) and run `php artisan migrate --force`. The migrations:
   - add `user_id` to videos and audio tracks and assign **all existing data to the first user**, who becomes admin;
   - move per-user settings into a new `user_settings` table (deployment settings stay installation-wide);
   - replace the global unique indexes on `puzzle_hash` and `scheduled_at` with per-user ones;
   - allow one YouTube connection per user;
   - create `google_credentials` and copy the old `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` to the first user so their
     channel keeps working without reconnecting;
   - drop the old shared quota flag.
4. Restart the queue worker (the deploy pipeline does this).
5. Optional: set `REGISTRATION_ENABLED=true` to let others sign up. Each new user then adds their own Google credentials
   on the YouTube page.

The migrations were tested on SQLite and MySQL 8, including rolling back and upgrading again.

## Behaviour changes to know about
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI` are no longer used at runtime. The first two are
  only read once by the upgrade migration. The redirect URI every user registers is `APP_URL` + `/youtube/callback`.
- `NOTIFY_EMAIL` is removed; emails go to each owner's account address.
- `php artisan shorts:*` commands now act on every user.
- Unverified accounts are redirected to email verification.

## Known limits
- Each user must create a Google Cloud project (about 5 minutes). Until a user publishes their OAuth app, Google expires
  their connection every 7 days; the guide tells them to click "Publish app".
- Disk space, CPU and the render queue are shared; there are no per-user storage quotas yet.
- There is no admin screen for managing users yet.
