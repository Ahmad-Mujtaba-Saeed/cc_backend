# Background music — how it works, and how to make it work on a new server

> TL;DR for a VPS with no music: `php artisan music:doctor --fix`
> (and if that reports no credential, add a Pixabay or Jamendo key in
> **Admin → API keys** first, or copy the library across — see §3).

## 1. Why music can work locally and not on the server

Nothing about the renderer differs between the two boxes. Two pieces of state do,
and neither travels with a `git push`:

| What | Where it lives | Does a deploy carry it? |
|---|---|---|
| The music files | `storage/app/public/audio/**` | **No** — `storage/app/public/.gitignore` is `*` |
| The provider API key | `api_credentials` table | **No** — it is database rows |

A developer machine accumulates downloaded tracks over months, so the library is
full and every render finds a bed. A freshly deployed VPS starts with an **empty
library and no credential**, so nothing can be picked — and the video renders
silent. The render still succeeds, which is why this shows up as "the audio is
broken" rather than as an error.

## 2. What the renderer actually does

`RemotionRenderService::resolveMusic()` walks these sources in order and takes
the first one whose file is **verified on disk**:

1. `settings['music_track']` — a path pinned by an earlier pick.
2. The admin-selected provider (Pixabay or Jamendo) — searches, downloads once
   into `audio/{provider}/{category}/`, returns the local path.
3. The *other* provider — **only** when the selected one has no credential at
   all. (Failing over between two working providers is deliberately not done: it
   would make the admin's setting a lie. Falling back when the setting cannot be
   honoured in any form is different, and beats silence.)
4. The legacy mood folders, `audio/{mood}/` — explainer moods only, so
   `audio/horror/` never leaks into an explainer.
5. **Anything playable anywhere in the library**, minus `audio/horror` and
   `audio/user`. Once a single track exists on the box, no explainer is silent
   just because one category happened to be empty.

Only if all five come back empty does the video render without a bed — and that
is logged as a warning with the full list of what was tried, and stamped on the
project as `settings['music_last_miss']`.

Two rules that are load-bearing and easy to undo by accident:

- **A candidate that fails the health check falls through to the next source.**
  It must never short-circuit to silence — that single behaviour was the
  original bug.
- **A path is only ever turned into a URL after its bytes are confirmed on
  disk.** Remotion plays music with an `<Audio>` tag, so a source that 404s
  fails the **entire render**, not just the bed.

## 3. Setting up a new server

Pick either route. (A) is better long-term; (B) works with no API key at all.

### A. Give it a provider key

1. **Admin → API keys** → add a **Pixabay** or **Jamendo** credential.
   Pixabay needs *audio* API access, which is granted separately from the
   regular image key — a key without it returns 403 and finds nothing.
2. Fill the library ahead of time so renders never wait on the API:

   ```bash
   docker compose exec app php artisan music:cache --per-category=3
   ```

3. Confirm:

   ```bash
   docker compose exec app php artisan music:doctor
   ```

### B. Copy the library from a machine that already has one

```bash
# from the dev box (189 MB for everything; a few categories is plenty)
rsync -av storage/app/public/audio/ USER@VPS:/path/to/app/storage/app/public/audio/

# on the VPS
chmod -R a+rX storage/app/public/audio
php artisan music:doctor
```

### Either way

Restart the queue worker afterwards — **queued jobs run the code and config that
were loaded when the worker booted**:

```bash
docker compose restart worker
```

## 4. Diagnosing

```bash
php artisan music:doctor            # credentials, library, asset URL
php artisan music:doctor 42         # …plus exactly what project 42 would render
php artisan music:doctor --fix      # …and repair permissions, then fill the library
```

It survives an unreachable database (it reports that as a finding and still
checks the disk), and its final line says whether music is serviceable on that
host. Exit code is non-zero when it is not, so it is usable in a deploy check.

### The two false alarms it exists to rule out

- **The MP4 predates the music choice.** Changing music does not mark a render
  stale — the stale badge hashes things that change a *frame*, and music cannot.
  Nothing re-renders on its own. Re-render, then listen.
- **The bed is present but inaudible.** The default mix is `0.09`, and
  `musicVolumeCurve` ducks it to `0.35×` whenever words are active — about
  −30 dBFS under narration, and an explainer is narrated almost end to end.
  `music:doctor <id>` prints both levels in dB. Raise `music_volume` in the
  storyboard's music panel before concluding the bed was dropped.

Definitive check on a finished file:

```bash
ffmpeg -i storage/app/public/<output>.mp4 -af volumedetect -f null - 2>&1 | tail -5
```

## 5. Where the decisions are logged

All of it happens in the **Laravel worker**, never in the render service:

```bash
docker compose logs -f worker
tail -f storage/logs/laravel.log | grep -iE 'music|remotion'
```

Look for `rendering WITHOUT a music bed` (with a `tried` map explaining every
rung) and `music resolved from a fallback source`.
