# The explainer bench

Item 5 of the 2026-08-31 user program: *"you need to perform ultimate testing
atleast 20-30 scripts etc and you compare it with how claude would handle this
script"*.

30 scripts, each with the storyboard a careful human would build from it,
written down **before** any run. The runner puts every script through the real
shipping pipeline and the scorer compares what came back to what should have.

## Running it

```bash
# the whole corpus, ~20 minutes, ~120 LLM calls
docker compose exec -T app php scratchpad/bench.php --tag=baseline

# one script, six times, to measure a stochastic cast
docker compose exec -T app php scratchpad/bench.php --only=boarding-pass --repeat=6 --tag=custom6

# the same corpus on a stronger model (restored on exit)
docker compose exec -T app php scratchpad/bench.php --model=gpt-4.1 --tag=strong

# read a run; re-scores from the stored storyboards, so this is free
docker compose exec -T app php scratchpad/bench-report.php baseline
docker compose exec -T app php scratchpad/bench-report.php strong baseline   # A vs B
docker compose exec -T app php scratchpad/bench-report.php baseline --full   # every finding

# the bench's own tests — no LLM, no cost
docker compose exec -T app php scratchpad/bench-check.php
```

`--resume` continues a run that died; `--no-director` skips the canvas pass
(cheaper, and flights go unscored); `--limit`, `--only`, `--skip` narrow it.

## What it runs

Exactly what `AnalyzeExplainerScriptJob` runs, minus persistence, theming, TTS
and rendering:

`MathTopicService::classify` → `ScriptSkeletonService::plan*` →
`GenericStoryboardComposerService` / `MathStoryboardComposerService` (falling
back to `ScriptAnalysisService`) → `ShotListValidator::validate` →
`CanvasDirectorService::direct` → `SceneBudgetLinter::lint`.

Two deliberate differences: the cold-open lift and the end card are **off**, so
the bench scores the storyboard body rather than packaging the user toggles;
and nothing is written to the database, so no project is touched.

## What it scores — 100 points

| dimension | pts | what it asks |
|---|---|---|
| structure | 15 | the right story spine, the right number of scenes, no phase dropped |
| casting | 25 | is each beat on the card that beat deserves; nothing on a card that contradicts the script; the right pipeline |
| variety | 15 | streaks, single_focus share, one card dominating, per-video caps |
| media | 15 | enough pictures, and briefs a user can act on |
| pacing | 15 | uneven lengths, runtime near target, no unreadable or endless scene |
| lint | 10 | what `SceneBudgetLinter` still finds after the validator has fixed everything it can |
| flights | 5 | inside the flight budget, and not a video that never moves |

The weights say what a bad video feels like: the wrong card for a beat hurts
more than flat pacing, and both hurt more than a lint warning nobody reads.

## The rubrics are opinions

`cases-1.php` and `cases-2.php` hold them, and they are meant to be argued
with — but they are *fixed* opinions, written before any run, which is the only
way a score moving between runs means anything. Each case carries:

- `must` — groups of cards, at least one per group. Each group names a beat
  with a right answer in the registry ("the fare breaks into parts that SUM —
  that is a receipt, never bullets").
- `should` — partial credit; a better answer exists but the plain card is not
  wrong.
- `never` — a card that would contradict this script. `plastic-recycled` says
  never `cycle_diagram`, because the narration says explicitly that plastic
  does not loop.
- `media_min`, `scenes`, `shape`, `math_mode`.

Edit a rubric and re-run `bench-report.php`: it re-scores from the stored
storyboards, so an argument about the rubric costs nothing.

## Four scripts are routing probes

`math-quadratic` and `math-pythagoras` must reach the maths board;
`science-half-life` and `compound-interest` must **not**, while still getting
`function_plot` / `formula_anatomy` (the iter-43 globalisation). A regression in
`MathTopicService` or in the ratio safety net shows up here as a `routing`
error, not as a mysteriously worse video.

## What it cannot tell you

Whether the video is *good to watch*. It measures the storyboard, not the
render: nothing here sees a frame, hears the narration, or knows whether the
camera life on a held frame is pleasant. Question 3 in the report is an
inventory for a human to judge against a real watch, and it says so.
