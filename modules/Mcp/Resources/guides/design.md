# Art direction for a whole video

A great explainer is not 40 good slides. It is one film with a visual language the viewer learns in the first 20 seconds and enjoys for the rest. Decide that language before writing scene 1.

## 1. Pick a visual system (write it down, then obey it)

- **Grid & margins**: where headlines live (e.g. top-left inside `safe`), where the main visual lives (centre / right two-thirds). Same in every scene unless a beat needs to break it.
- **One recurring motif** that carries meaning: a dot = one unit of the thing being counted; a line = time; a circle = the system; a highlight colour = "the thing that matters". Reuse it so later scenes need no explanation.
- **Accent discipline**: `theme.accent` marks the ONE thing the narration is about right now. Everything else is `theme.text` or `theme.muted`. A frame with five accents has none.
- **Camera grammar**: e.g. slow push-in on ordinary beats, a `<Depth>` rack-focus on reveals, a pull-back on conclusions. The same move should mean the same thing every time.
- **Cut grammar** (`transition` on each scene): soft cuts (`fade`, `match_dissolve`) inside an idea, directional cuts (`push_left`, `stack_push`, `mask_wipe_diagonal`) when the story moves forward, `split_slide` for contrasts, `zoom_through` into a detail, `line_sweep` for act breaks. `list_styles` explains each. Avoid the same cut more than 3 times in a row.

## 2. Structure

- **Hook (first 5–10 s)**: the most surprising fact, question or image of the whole video, staged big. No logo intros, no "in this video".
- **Context → idea → evidence → consequence**, one beat per scene, each scene answering the question the previous one raised.
- **Act breaks** every 2–4 minutes on long videos: a short silent title beat (`hold_seconds` 1.5–2.5, no narration, big type) re-orients the viewer.
- **Ending**: land the single takeaway visually (callback to the hook's motif), then a calm final hold. A subscribe/outro card only if the user wants one.

## 3. Variety without chaos

Rotate visual devices so no two neighbouring scenes look alike — while keeping the system:
- data reveal (bars, dots, rolling counter), scale comparison (big vs small), process/flow (nodes + travelling pulses), map/geography, before/after, timeline, metaphor illustration (drawn objects in SVG), a quote, a full-frame photo (stock) with one overlay label, a card from the library.
- Alternate dense and calm: after a busy diagram, give a one-number scene.

## 4. Type & readability

- Headlines ≤ 8 words, labels 1–3 words. The voice says the sentence; the screen shows the noun, the number, the relationship.
- Sizes: headline 64–96×u, numbers 160–280×u, labels 32–44×u, nothing under 26×u. On 9:16 everything ~1.25× bigger relative to width and stacked vertically.
- Respect `safe` (and the presenter window in presenter mode). Phones crop and cover edges.

## 5. Motion feel

- Springs for things that arrive (`pop`), eased curves for things that travel (`ease.inOut`), linear only for continuous drift.
- Stagger groups (`s(0.06)`–`s(0.1)` apart). Overlap motions slightly — the next thing starts before the last settles.
- Every reveal lands on its word (`cue`). The viewer should feel the picture is reacting to the voice.
- Hold the finished frame ~15% of the scene so it can be read.

## 6. Cards vs custom code

Use library cards (`get_guide cards`) where they shine — charts with real numbers, timelines, rankings, step flows, versus comparisons, maths working — they are tested and fast to write. Make everything else custom: that is what makes the video unmistakably designed for THIS script. A good mix for a typical explainer is 70–90% custom scenes.

## 7. Sound

Music ducks automatically under the voice. Sound effects (whooshes on cuts, pops on card reveals) are on by default — turn them off (`update_video sound_effects=false`) for calm, serious topics.
