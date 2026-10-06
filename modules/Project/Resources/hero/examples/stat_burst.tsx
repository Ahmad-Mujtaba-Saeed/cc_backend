// NARRATION: "Every year, eight million tons of plastic wash into the ocean."
// BEAT: a single shocking number — make the number the event.
import { AbsoluteFill, interpolate, random } from 'remotion';
import { useHero, progress, pop, ease, noise1, FitText, Counter, DrawPath } from 'hero-kit';

export default function Scene() {
  const { frame, fps, duration, width, height, u, portrait, theme, font, safe, cue } = useHero();

  // Every beat lands on the voice.
  const tNumber = cue('eight', 0.08);
  const tUnit = cue('million', 0.22);
  const tPlace = cue('ocean', 0.6);

  const cx = width / 2;
  const cy = portrait ? height * 0.38 : height * 0.42;
  const R = (portrait ? 300 : 240) * u;

  // The stage never stands still: a slow push for the whole scene.
  const push = interpolate(frame, [0, duration], [1, 1.07]);

  const ring = progress(frame, tNumber, 48, ease.outQuint);
  const burst = pop(frame, tNumber, fps, { damping: 10, stiffness: 140 });
  const unitIn = progress(frame, tUnit, 16, ease.out);
  const placeIn = progress(frame, tPlace, 20, ease.out);

  // Rays fire outward when the number lands, then breathe.
  const rays = Array.from({ length: 32 }, (_, i) => {
    const a = (i / 32) * Math.PI * 2;
    const len = (0.18 + random(`ray-${i}`) * 0.32) * R;
    const breathe = 1 + 0.08 * noise1(frame / 30 + i);
    const r0 = R * 1.12;
    const r1 = r0 + len * burst * breathe;
    return { x0: cx + Math.cos(a) * r0, y0: cy + Math.sin(a) * r0, x1: cx + Math.cos(a) * r1, y1: cy + Math.sin(a) * r1 };
  });

  // Drifting motes for depth (deterministic).
  const motes = Array.from({ length: 46 }, (_, i) => {
    const x = random(`mx-${i}`) * width;
    const y = random(`my-${i}`) * height;
    const drift = noise1(frame / 90 + i * 3.1) * 24 * u;
    return { x: x + drift, y: y - (frame * (0.3 + random(`ms-${i}`) * 0.6) * u) % height, r: (1.5 + random(`mr-${i}`) * 2.5) * u, o: 0.15 + random(`mo-${i}`) * 0.35 };
  });

  const arc = `M ${cx} ${cy - R} A ${R} ${R} 0 1 1 ${cx - 0.01} ${cy - R}`;

  return (
    <AbsoluteFill style={{ background: `radial-gradient(110% 85% at 50% 42%, ${theme.panel} 0%, ${theme.bg} 72%)` }}>
      <AbsoluteFill style={{ transform: `scale(${push})` }}>
        <svg width={width} height={height}>
          <defs>
            <radialGradient id="core" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stopColor={theme.accent} stopOpacity={0.32} />
              <stop offset="100%" stopColor={theme.accent} stopOpacity={0} />
            </radialGradient>
            <filter id="glow" x="-50%" y="-50%" width="200%" height="200%">
              <feGaussianBlur stdDeviation={9 * u} result="b" />
              <feMerge>
                <feMergeNode in="b" />
                <feMergeNode in="SourceGraphic" />
              </feMerge>
            </filter>
          </defs>
          {motes.map((m, i) => (
            <circle key={i} cx={m.x} cy={(m.y + height) % height} r={m.r} fill={theme.muted} opacity={m.o} />
          ))}
          <circle cx={cx} cy={cy} r={R * 1.9} fill="url(#core)" opacity={ring} />
          {rays.map((r, i) => (
            <line key={i} x1={r.x0} y1={r.y0} x2={r.x1} y2={r.y1} stroke={i % 4 === 0 ? theme.accent2 : theme.accent}
              strokeWidth={3 * u} strokeLinecap="round" opacity={0.75 * burst} />
          ))}
          <circle cx={cx} cy={cy} r={R} fill="none" stroke={theme.muted} strokeOpacity={0.22} strokeWidth={14 * u} />
          <DrawPath d={arc} progress={ring} stroke={theme.accent} strokeWidth={14 * u} filter="url(#glow)" />
        </svg>

        {/* The number sits INSIDE the ring; nothing appears before its cue. */}
        <div style={{ position: 'absolute', left: 0, right: 0, top: cy - 130 * u, textAlign: 'center',
          opacity: progress(frame, tNumber - 2, 6), transform: `scale(${0.6 + 0.4 * burst})` }}>
          <Counter to={8} start={tNumber} duration={34}
            style={{ fontFamily: font.display, fontWeight: 800, fontSize: 210 * u, lineHeight: 1, color: theme.text }} />
        </div>
        {/* The unit rides the lower chord of the ring, sized to fit inside it. */}
        <div style={{
          position: 'absolute', left: 0, right: 0, top: cy + 78 * u, textAlign: 'center',
          opacity: unitIn, transform: `translateY(${(1 - unitIn) * 18 * u}px)`,
          fontFamily: font.mono, fontSize: 24 * u, letterSpacing: 4 * u, color: theme.accent, textTransform: 'uppercase',
        }}>
          million tons a year
        </div>

        <div style={{
          position: 'absolute', left: safe.left, right: safe.right,
          bottom: portrait ? safe.bottom + 40 * u : safe.bottom + 24 * u,
          display: 'flex', justifyContent: 'center',
          opacity: placeIn, transform: `translateY(${(1 - placeIn) * 30 * u}px)`,
        }}>
          <FitText text="washes into the ocean" width={width - safe.left - safe.right} maxSize={78 * u}
            maxLines={2} align="center" color={theme.text} />
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
}
