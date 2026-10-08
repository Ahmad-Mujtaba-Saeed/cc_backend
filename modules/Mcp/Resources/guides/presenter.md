# Presenter mode — motion graphics over the user's own recording

The user records themselves speaking their script (webcam, phone, camera — up to 15 minutes). You turn it into an edited video: sometimes they fill the screen with a title or keyword popping beside them, sometimes they shrink into a corner window while a diagram takes the frame, sometimes they sit on one side with graphics on the other, sometimes only the graphics show while their voice carries on. The footage window animates smoothly between those places at every cut.

## Steps

1. `create_video` with `mode: "presenter"` (pick `aspect_ratio` to match how they recorded or where it will be posted; a 16:9 recording works in 9:16 too — it is cropped to fit each window).
2. Give the user the `upload_link` from the response (or `create_upload_link purpose=presenter`). They upload from any device; large files are fine (chunked).
3. Poll `get_video` about once a minute. `presenter.status` goes `processing` → `ready` (the recording is normalised and transcribed with word timings; a 10-minute recording takes several minutes). If it is `failed`, tell the user the error.
4. Read `get_transcript` (`segments` for the overview, `words` for exact timing of a passage).
5. Plan: split the recording into windows (scenes) by idea, and give each window a layout and a visual idea.
6. For each window, `upsert_scene` with `start_seconds`, `end_seconds`, `presenter_layout` and `code` (or `card`), then `preview_scene`. You do not write narration — the scene's words come from the recording.
7. `render_video`, poll `get_render_status`, give the user the link.

## Layouts

| layout | what shows | what you write |
|---|---|---|
| `full` | the presenter fills the frame | nothing (omit code/card) — or `code` drawn as a **transparent overlay** (`overlay` is true): lower thirds, a keyword popping beside their head, an arrow, a number. Never paint a background. |
| `pip` | your graphics fill the frame; the presenter is a corner window (`pip_corner`: bottom_right / bottom_left / top_right / top_left; `pip_shape`: rounded / circle) | `code` or `card`. Keep key content clear of `presenter.box`. |
| `split` | presenter on one side (`split_side`: left/right; in 9:16 left = top), graphics on the other | `code` only. Lay out inside `stage` (and `safe`, which already accounts for it). |
| `hidden` | graphics only; their voice continues | `code` or `card`. |

Gaps between your scenes automatically show the presenter full-frame, so you only write scenes where something should happen. Scenes may not overlap.

## Editing judgement

- **Open on the person** (full, 3–8 s) so viewers meet the speaker, then cut to graphics on the first concrete idea.
- Use `pip` when the graphic explains what they are saying right now (their face keeps the human connection); use `hidden` for dense diagrams that need the whole frame; use `split` for lists/steps that build while they talk; return to `full` for opinions, stories, jokes, calls to action — moments where the face IS the content.
- Change layout on sentence boundaries (segment ends in the transcript), never mid-word. Windows of 4–20 s feel natural; very short flips (< 2 s) feel nervous.
- Overlays on `full`: place text in the open space beside or above the head (usually the side away from where they sit in frame, top 35–40% is often free), add a soft dark gradient behind white text for readability, keep it short (≤ 6 words), and make it land on the word that says it (`cue`).
- If captions are on, they sit at the bottom centre; put the pip window in a TOP corner or keep overlays above the bottom 25%.
- Don't contradict the speaker: on-screen numbers and names must match what they say.

## Notes

- `words` / `cue()` inside a presenter scene are the speaker's real words in that window (scene-local frames) — sync to them exactly as in narrated mode.
- The transcript writes spoken numbers as digits ("20", "2024", "3"): cue the digits — `cue("20")`, not `cue("twenty")`.
- Transcription is tuned for English.
- The recording's own audio is the soundtrack; music (if any) ducks under the voice automatically — keep `music_volume` low (0.04–0.07) for speech recorded in a room.
