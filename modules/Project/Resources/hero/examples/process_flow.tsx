// NARRATION: "Sunlight hits the panel, the inverter turns it into household power, and it flows straight into your home."
// BEAT: a process — each stage appears as the voice names it, and energy visibly travels between them.
import { AbsoluteFill, interpolate } from 'remotion';
import { useHero, progress, pop, ease, noise1, FitText, DrawPath } from 'hero-kit';

const STAGES = [
  { label: 'Sunlight', cue: 'sunlight' },
  { label: 'Panel', cue: 'panel' },
  { label: 'Inverter', cue: 'inverter' },
  { label: 'Home', cue: 'home' },
];

export default function Scene() {
  const { frame, fps, duration, width, height, u, portrait, theme, font, safe, cue } = useHero();

  const n = STAGES.length;
  const lands = STAGES.map((s, i) => cue(s.cue, 0.1 + i * 0.18));
  const titleIn = progress(frame, 0, 18, ease.out);

  // Layout: a row in landscape, a column in portrait — positions computed, never hard-coded pixels.
  const top = safe.top + 170 * u;
  const bottom = height - safe.bottom - 60 * u;
  const pts = STAGES.map((_, i) => {
    const k = i / (n - 1);
    return portrait
      ? { x: width / 2 + (i % 2 === 0 ? -1 : 1) * 150 * u, y: interpolate(k, [0, 1], [top + 80 * u, bottom - 80 * u]) }
      : { x: interpolate(k, [0, 1], [safe.left + 190 * u, width - safe.right - 190 * u]), y: height * 0.58 };
  });
  const R = (portrait ? 84 : 96) * u;

  // The camera leans toward whichever stage is active.
  let active = -1;
  lands.forEach((t, i) => { if (frame >= t) active = i; });
  const focus = active < 0 ? pts[0] : pts[active];
  const lean = progress(frame, lands[Math.max(0, active)], 24, ease.inOut);
  const camX = (width / 2 - focus.x) * 0.06 * lean;
  const camY = (height / 2 - focus.y) * 0.06 * lean;

  // A pool of light follows the active stage across the frame.
  const glowX = interpolate(lean, [0, 1], [active > 0 ? pts[active - 1].x : focus.x, focus.x]);
  const glowY = interpolate(lean, [0, 1], [active > 0 ? pts[active - 1].y : focus.y, focus.y]);

  return (
    <AbsoluteFill style={{ background: `linear-gradient(160deg, ${theme.panel} 0%, ${theme.bg} 60%)` }}>
      <AbsoluteFill style={{
        opacity: active >= 0 ? 0.9 : 0,
        background: `radial-gradient(${520 * u}px ${380 * u}px at ${glowX + camX}px ${glowY + camY}px, ${theme.accent}33 0%, transparent 70%)`,
      }} />
      <div style={{ position: 'absolute', left: safe.left, right: safe.right, top: safe.top, opacity: titleIn,
        transform: `translateY(${(1 - titleIn) * 20 * u}px)` }}>
        <div style={{ fontFamily: font.mono, fontSize: 24 * u, letterSpacing: 5 * u, color: theme.accent, textTransform: 'uppercase' }}>
          From sky to socket
        </div>
        <FitText text="How solar power reaches you" width={width - safe.left - safe.right} maxSize={66 * u} maxLines={2} color={theme.text} />
      </div>

      <AbsoluteFill style={{ transform: `translate(${camX}px, ${camY}px)` }}>
        <svg width={width} height={height}>
          <defs>
            <filter id="soft" x="-50%" y="-50%" width="200%" height="200%">
              <feGaussianBlur stdDeviation={6 * u} />
            </filter>
          </defs>
          {/* Connectors draw on just before the next stage is named; energy then flows along them. */}
          {pts.slice(1).map((p, i) => {
            const a = pts[i];
            const draw = progress(frame, lands[i + 1] - 14, 16, ease.outQuint);
            const d = `M ${a.x} ${a.y} L ${p.x} ${p.y}`;
            const pulses = Array.from({ length: 5 }, (_, k) => {
              const s = ((frame / fps) * 0.55 + k / 5) % 1;
              return { x: a.x + (p.x - a.x) * s, y: a.y + (p.y - a.y) * s, o: Math.sin(s * Math.PI) };
            });
            return (
              <g key={i}>
                <DrawPath d={d} progress={draw} stroke={theme.muted} strokeOpacity={0.45} strokeWidth={5 * u} />
                {draw >= 1 && pulses.map((q, k) => (
                  <circle key={k} cx={q.x} cy={q.y} r={7 * u} fill={theme.accent} opacity={q.o} filter="url(#soft)" />
                ))}
                {draw >= 1 && pulses.map((q, k) => (
                  <circle key={`c${k}`} cx={q.x} cy={q.y} r={3.5 * u} fill={theme.accent} opacity={q.o} />
                ))}
              </g>
            );
          })}
          {pts.map((p, i) => {
            const s = pop(frame, lands[i], fps, { damping: 12 });
            const on = i === active;
            const glow = on ? 0.55 + 0.25 * Math.sin(frame / 8) : 0;
            const bob = noise1(frame / 45 + i * 7) * 4 * u;
            return (
              <g key={i} transform={`translate(${p.x} ${p.y + bob}) scale(${s})`}>
                <circle r={R * 1.45} fill={theme.accent} opacity={glow * 0.25} filter="url(#soft)" />
                <circle r={R} fill={theme.panel} stroke={on ? theme.accent : theme.muted} strokeOpacity={on ? 1 : 0.5} strokeWidth={5 * u} />
                <text textAnchor="middle" dominantBaseline="central" fontFamily={font.display} fontWeight={800}
                  fontSize={64 * u} fill={on ? theme.accent : theme.text}>{i + 1}</text>
              </g>
            );
          })}
        </svg>
        {pts.map((p, i) => {
          const t = progress(frame, lands[i] + 4, 14, ease.out);
          return (
            <div key={i} style={{
              position: 'absolute', width: 260 * u, left: portrait ? (i % 2 === 0 ? p.x + R + 24 * u : p.x - R - 24 * u - 260 * u) : p.x - 130 * u,
              top: portrait ? p.y - 22 * u : p.y + R + 26 * u, opacity: t, transform: `translateY(${(1 - t) * 14 * u}px)`,
              textAlign: portrait ? (i % 2 === 0 ? 'left' : 'right') : 'center',
              fontFamily: font.body, fontWeight: 600, fontSize: 42 * u, color: i === active ? theme.text : theme.muted,
            }}>
              {STAGES[i].label}
            </div>
          );
        })}
      </AbsoluteFill>
    </AbsoluteFill>
  );
}
