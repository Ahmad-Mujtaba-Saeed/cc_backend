// NARRATION: "Your senses send your brain eleven million bits every second. You are aware of only about fifty."
// BEAT: a contrast of scale — show the flood, then let almost all of it vanish.
import { AbsoluteFill, interpolate, interpolateColors, random } from 'remotion';
import { useHero, progress, ease, noise2, FitText, Counter } from 'hero-kit';

export default function Scene() {
  const { frame, duration, width, height, u, portrait, theme, font, safe, cue } = useHero();

  const tFlood = cue('eleven', 0.12);
  const tOnly = cue('only', 0.55);
  const tFifty = cue('fifty', 0.7);

  // A field of dots stands for the flood. Fifty of them are "the ones you notice".
  const cols = portrait ? 22 : 40;
  const rows = portrait ? 36 : 22;
  const gap = Math.min((width - safe.left - safe.right) / cols, (height * 0.62) / rows);
  const gridW = gap * (cols - 1);
  const gridH = gap * (rows - 1);
  const ox = (width - gridW) / 2;
  const oy = portrait ? height * 0.3 : height * 0.33;

  const chosen = new Set<number>();
  const centre = Math.floor(rows / 2) * cols + Math.floor(cols / 2);
  for (let k = 0; chosen.size < 50 && k < 400; k++) {
    const dr = Math.round((random(`r${k}`) - 0.5) * 7);
    const dc = Math.round((random(`c${k}`) - 0.5) * 9);
    chosen.add(centre + dr * cols + dc);
  }

  const fade = progress(frame, tOnly, 26, ease.inOut);
  // The camera dives into the survivors once everything else is gone.
  const zoom = interpolate(progress(frame, tOnly + 10, 40, ease.inOutQuint), [0, 1], [1, portrait ? 2.1 : 2.6]);
  const drift = interpolate(frame, [0, duration], [0, -18 * u]);

  const dots = [] as Array<{ x: number; y: number; on: boolean; delay: number }>;
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const i = r * cols + c;
      dots.push({ x: ox + c * gap, y: oy + r * gap, on: chosen.has(i), delay: (Math.abs(c - cols / 2) + Math.abs(r - rows / 2)) * 0.9 });
    }
  }

  const bigIn = progress(frame, tFlood, 20, ease.out);
  const smallIn = progress(frame, tFifty, 18, ease.out);
  const fx = ox + gridW / 2;
  const fy = oy + gridH / 2;

  return (
    <AbsoluteFill style={{ background: `radial-gradient(100% 80% at 50% 55%, ${theme.panel} 0%, ${theme.bg} 75%)` }}>
      <AbsoluteFill style={{ transform: `translateY(${drift}px) scale(${zoom})`, transformOrigin: `${fx}px ${fy}px` }}>
        <svg width={width} height={height}>
          {dots.map((d, i) => {
            // The flood builds from the very first frame (a hero never opens on
            // an empty screen); the number lands on its own cue.
            const appear = progress(frame, d.delay * 1.4, 10, ease.out);
            const jitter = noise2(d.x / 120 + frame / 60, d.y / 120) * 2 * u;
            const keep = d.on ? 1 : 1 - fade;
            const color = d.on ? interpolateColors(fade, [0, 1], [theme.muted, theme.accent]) : theme.muted;
            const r = (d.on ? 1 + 0.6 * fade : 1) * gap * 0.16;
            return (
              <circle key={i} cx={d.x + jitter} cy={d.y} r={r * appear} fill={color}
                opacity={(d.on ? 0.9 : 0.55) * keep * appear} />
            );
          })}
        </svg>
      </AbsoluteFill>

      <div style={{ position: 'absolute', left: safe.left, right: safe.right, top: safe.top, textAlign: 'center',
        opacity: bigIn * (1 - smallIn), transform: `translateY(${(1 - bigIn) * 20 * u}px)` }}>
        <Counter to={11000000} start={tFlood} duration={40}
          style={{ fontFamily: font.display, fontWeight: 800, fontSize: 110 * u, color: theme.text }} />
        <div style={{ fontFamily: font.mono, fontSize: 26 * u, letterSpacing: 5 * u, color: theme.muted, textTransform: 'uppercase' }}>
          bits per second
        </div>
      </div>

      <div style={{ position: 'absolute', left: safe.left, right: safe.right, top: safe.top, textAlign: 'center',
        opacity: smallIn, transform: `scale(${0.9 + 0.1 * smallIn})` }}>
        <Counter to={50} start={tFifty} duration={24}
          style={{ fontFamily: font.display, fontWeight: 800, fontSize: 150 * u, color: theme.accent }} />
        <div style={{ display: 'flex', justifyContent: 'center' }}>
          <FitText text="the ones you actually notice" width={width - safe.left - safe.right} maxSize={44 * u}
            maxLines={1} align="center" font="body" weight={600} color={theme.text} />
        </div>
      </div>
    </AbsoluteFill>
  );
}
