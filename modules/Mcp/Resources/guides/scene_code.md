# Writing custom animated scenes

A custom scene is ONE React module written for Remotion, rendered frame by frame at 60 fps (or 30). It runs in a sandbox. You write it, `upsert_scene` compiles it, `preview_scene` shows you the frames. This is how the best videos are made: every beat gets a motion graphic designed for its words.

## The contract

- `export default function Scene()` — no props. Everything comes from `useHero()`.
- Import ONLY from `"remotion"` and `"hero-kit"`. No React import needed (JSX works without it), no npm packages, no CSS files, no web fonts, no images from the web.
- From `"remotion"`: `AbsoluteFill, Sequence, useCurrentFrame, useVideoConfig, interpolate, interpolateColors, spring, measureSpring, Easing, random`. `useCurrentFrame()` is the SCENE's frame (0 = first frame of this scene); `useVideoConfig().durationInFrames` is the scene's length.
- From `"react"` only `Fragment` and `useMemo` (rarely needed).
- **Deterministic**: no state between frames — compute everything from `frame`. Randomness = `random("seed")` (stable per frame); never `Math.random()`.
- **Refused** (the sandbox rejects the module and tells you why): `window`, `document`, `fetch`, timers, `Date`, `performance`, `Math.random`, `eval`, `Function`, `Reflect`, `Proxy`, async/await, `import()`; `.constructor`, `__proto__`, `prototype`; names starting with `__`; labelled loops; refs and event handlers (`onClick`…); `dangerouslySetInnerHTML`, `src`, `href`; any string with a URL, `//domain`, `javascript:`, `@import`, or `url(...)` other than `url(#id)`.
- **Allowed elements**: `div span p h1 h2 h3 h4 strong em b i small sup sub br ul ol li svg g path circle ellipse rect line polyline polygon text tspan defs linearGradient radialGradient stop mask clipPath pattern marker filter feGaussianBlur feOffset feBlend feColorMatrix feComposite feMerge feMergeNode feFlood feMorphology feTurbulence feDisplacementMap feDropShadow` — plus the kit components below.
- If your code throws while rendering, the scene silently falls back to a plain title card — `preview_scene` tells you when that happens. Guard every array index and division.

## hero-kit

```ts
const {
  frame, fps, duration, width, height,
  t,          // scene progress 0..1
  u,          // scale unit = min(width, height) / 1080 — size EVERYTHING in multiples of u
  s,          // s(seconds) → frames at this video's fps. TIME EVERYTHING IN SECONDS: s(0.4), never "12 frames"
  portrait,   // true for 9:16
  theme,      // { bg, panel, text, muted, accent, accent2, onAccent } — this video's palette (use only these)
  font,       // { display, body, mono } — this video's typefaces
  safe,       // { top, right, bottom, left } px insets from the frame edges — keep text inside them
  stage,      // { x, y, width, height } — where to draw (whole frame, except beside a presenter in a split)
  overlay,    // true when drawn OVER the presenter's full-frame footage → keep the background transparent
  presenter,  // null, or { layout, box: {x, y, width, height} } — the presenter's window on screen (keep key content clear of it)
  words,      // narration words with frame timings [{ word, start, end }] (scene-local)
  cue,        // cue("phrase", fallback 0..1) → the frame the narrator says the phrase's FIRST word
} = useHero();

cue("phrase", fallback?)                          // also importable on its own
progress(frame, start, durationFrames, easing?)   // 0..1, clamped, eased (default ease-out)
pop(frame, start, fps, { damping?, stiffness?, mass? })  // spring 0 → 1 with natural overshoot
stagger(i, start, step)                           // start frame of item i
mix(a, b, t)                                      // linear interpolation
ease.out | ease.outQuint | ease.outExpo | ease.inOut | ease.inOutQuint | ease.back
noise1(x, seed?) / noise2(x, y, seed?)            // smooth deterministic noise in [-1, 1] for organic drift
spring, interpolate, interpolateColors, Easing    // re-exported from remotion

<FitText text width maxSize minSize? maxLines? font?="display"|"body"|"mono" weight? color? align? lineHeight? letterSpacing? style? />
                                                  // text at the LARGEST size that fits `width` in `maxLines` — use for any text you did not size by hand
<DrawPath d progress stroke strokeWidth ...svgProps />   // inside an <svg>: a path that draws itself on, progress 0 → 1
<Counter to from? start? duration? decimals? prefix? suffix? style? />   // a rolling number (start/duration in frames)
<Asset name style? />                             // a picture from the video's media shelf (add_media / user upload)

<Depth camera={{ x?, y?, z?, rotateX?, rotateY? }} perspective?={1400} focus?={0} aperture?={0}>   // a 3D stage (Flute-style)
  <Layer z={-400}>…far…</Layer>                   // planes; z > 0 is nearer the viewer
  <Layer z={0}>…subject…</Layer>
  <Layer z={220}>…foreground…</Layer>
</Depth>
```

### The depth stage (cinematic camera)

`<Depth>` gives you a real 3D camera through layered planes — the same rig as our cinematic cards (after Flute):
- Every `<Layer>` is perspective-compensated: with the camera at rest it looks exactly as laid out. Move the camera (`z` dolly in, `x`/`y` truck, `rotateY`/`rotateX` orbit) and the planes part against each other — real parallax.
- `focus` = the z that is sharp. With `aperture` > 0 (try 0.6–1.2) other planes blur by their distance from it. **Rack focus** by animating `focus` from one layer's z to another's on a cue word.
- Units are 1080p pixels (internally × u). Keep camera moves gentle: z 0→150–250 over the scene, rotate ≤ 8°.
- Put headlines OUTSIDE the `<Depth>` (on top, in screen space) so the push never crops them — or keep them inside `safe` at the move's end.
- Blur is expensive: use it on 1–3 layers, not dozens.

## Timing — the scene moves WITH the voice

- Every reveal lands on a narration cue: `const tX = cue("keyword", 0.3)`. Pick distinctive words actually in the narration (a noun or number, not "the"). `preview_scene` prints the real word timings.
- Lead the voice slightly: start an entrance 2–4 frames (≈ `s(0.05)`) before the cue word.
- Something is visible from the FIRST frame — the scene starts right after a cut. Build the stage in the first `s(0.5)`.
- Entrances: `s(0.25)`–`s(0.45)`, eased out or springy (`pop`). Stagger siblings by `s(0.06)`–`s(0.1)`.
- Never fully static: a slow whole-stage camera move (scale 1 → 1.05–1.1, or a `<Depth>` dolly) plus secondary motion (a breathing glow, drifting particles via `noise1`, a pulse travelling along a line).
- The last ~15% HOLDS the finished picture so it can be read. Do not animate things away at the end — the cut handles leaving.
- Scene length comes from the voice. Time with `cue()` and `t`, never with absolute end frames.

## Composition

- ONE focal point at a time. The viewer should always know where to look.
- On screen: a headline of at most ~8 words, labels of 1–3 words, numbers. Never paragraphs.
- Big type: headlines 64–96×u, hero numbers 160–280×u, labels 32–44×u, eyebrows (small uppercase mono over a headline) 24–28×u. **Nothing below 26×u.**
- Lay out from `width`, `height`, `u`, `safe`, `stage` — never hard-code pixel positions for one frame size. Handle `portrait` (stack vertically, bigger relative type) and landscape (spread horizontally).
- The main visual fills 55–75% of the frame's height in landscape (width in portrait). A small diagram in a big empty frame looks unfinished.
- Text never touches other text, lines or shape edges; 24×u padding inside any box that holds text. If a label could be long, use `<FitText>`.
- Palette: `theme.bg` for the field (a subtle radial/linear gradient between `theme.panel` and `theme.bg` is welcome), `theme.text` for copy, `theme.accent` for the ONE thing that matters now, `theme.accent2` sparingly, `theme.muted` for secondary items. `theme.onAccent` is the readable ink on an accent fill.
- Glows (`feGaussianBlur`), soft shadows, gradients, depth and particles are welcome — tastefully: one glow per focal element, blur radius ≤ 30×u, at most 3–4 SVG filters.
- Make it a MOTION GRAPHIC, not a slide: diagrams drawn with SVG, paths drawing on, numbers rolling, shapes morphing (interpolate attributes), elements travelling along paths, a camera that pushes toward what matters.
- `interpolate()` is for numbers; colours use `interpolateColors(p, [0, 1], [a, b])`. Always clamp: `interpolate(f, [a, b], [x, y], { extrapolateLeft: "clamp", extrapolateRight: "clamp" })`.

## Lessons from real scenes (each of these failed a review)

- **The camera push clips headlines.** Move the ARTWORK layer; keep headlines/labels/numbers on a layer that does not move, or inside `safe` at the end of the push.
- **On-screen facts must match the narration exactly.** "About a fifth" is 20% and a fifth of the bar; "five researchers" is five figures.
- **Two captions that swap must not overlap** — finish one before the next appears, or put them in different places.
- **Show the idea, not a document.** A form or "record card" mock-up only when the narration is about that document; otherwise draw the thing itself.
- **Words are never cut.** Give every label room, or use `<FitText>`.
- **Contrast**: copy is `theme.text` or `theme.accent` on `theme.bg`/`theme.panel`; `theme.muted` only for secondary copy ≥ 26×u.

## Performance (long videos render every frame)

- Keep a module under ~400 lines and under ~600 SVG/DOM elements; at most 3–4 SVG filters; blur on few elements.
- Bound every loop and `Array.from({ length })` by small numbers you chose. No per-frame heavy math over thousands of items.
- Hard limits (the scene falls back to its plain card if it hits one): arrays of at most 200,000 items, and a per-frame budget of 5 million loop iterations + function calls (callbacks and recursion count).
- Give SVG elements explicit `width`/`height` (usually `width={width} height={height}`), unique gradient/filter ids, referenced with `url(#id)`.

## Checklist for every scene

1. A clear visual idea that SHOWS the narration.
2. A background treatment + a slow whole-stage camera move.
3. 2–4 reveals synced to cue words.
4. At least one piece of continuous secondary motion.
5. A finished, readable final state.
6. Previewed, looked at, fixed.

See `get_guide examples` for complete modules at the expected quality.
