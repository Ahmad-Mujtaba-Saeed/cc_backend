import { AbsoluteFill, interpolate } from "remotion";
import { useHero, Depth, Layer, FitText, DrawPath, pop, progress, ease, mix, noise1, stagger } from "hero-kit";

// Narration: "Your phone's battery doesn't die all at once. Heat is what ages
// it — every summer afternoon on a car dashboard costs a little capacity, for good."
//
// Idea: three planes in depth — a heat haze far back, the battery in the
// middle, the capacity readout in front. The camera dollies in while the
// FOCUS racks from the battery to the readout exactly when "capacity" is said.
export default function Scene() {
  const { frame, fps, width, height, u, s, theme, font, safe, cue, portrait } = useHero();

  const tHeat = cue("Heat", 0.25);
  const tDash = cue("dashboard", 0.55);
  const tCap = cue("capacity", 0.7);

  const dolly = progress(frame, 0, s(8), ease.inOut);
  const rack = progress(frame, tCap - s(0.15), s(0.7), ease.inOut);

  // Battery geometry (centre plane), sized from the frame.
  const bw = (portrait ? width * 0.62 : height * 0.62) * 1;
  const bh = bw * 0.48;
  const bx = width / 2 - bw / 2 + (portrait ? 0 : width * 0.08);
  const by = height / 2 - bh / 2 + (portrait ? height * 0.04 : 0);

  // Charge drains in steps on "Heat" and "dashboard".
  const drain1 = progress(frame, tHeat, s(0.6), ease.outQuint);
  const drain2 = progress(frame, tDash, s(0.6), ease.outQuint);
  const level = 1 - 0.12 * drain1 - 0.08 * drain2;

  const readIn = pop(frame, tCap - s(0.05), fps, { damping: 15 });
  const titleIn = progress(frame, s(0.05), s(0.45), ease.outQuint);

  return (
    <AbsoluteFill style={{ background: `radial-gradient(110% 80% at 50% 40%, ${theme.panel}, ${theme.bg})` }}>
      <Depth
        camera={{ z: mix(0, 180, dolly), x: mix(-30, 0, dolly), rotateY: mix(-4, 0, dolly) }}
        focus={mix(0, 240, rack)}
        aperture={1}
      >
        <Layer z={-480}>
          <svg width={width} height={height}>
            {Array.from({ length: 14 }).map((_, i) => {
              const x = ((i + 0.5) / 14) * width;
              const rise = (frame / s(6) + i * 0.17) % 1;
              const y = height * (1.05 - rise * 0.9);
              const sway = noise1(frame / s(1.5) + i, i) * 30 * u;
              return (
                <path
                  key={i}
                  d={`M ${x + sway} ${y} q ${20 * u} ${-40 * u} 0 ${-80 * u} q ${-20 * u} ${-40 * u} 0 ${-80 * u}`}
                  stroke={theme.accent2}
                  strokeWidth={4 * u}
                  fill="none"
                  opacity={0.25 * drain1 * (1 - rise)}
                />
              );
            })}
          </svg>
        </Layer>

        <Layer z={0}>
          <svg width={width} height={height}>
            <rect x={bx} y={by} width={bw} height={bh} rx={28 * u} fill="none" stroke={theme.text} strokeWidth={8 * u} />
            <rect x={bx + bw} y={by + bh * 0.3} width={22 * u} height={bh * 0.4} rx={6 * u} fill={theme.text} />
            <rect
              x={bx + 18 * u}
              y={by + 18 * u}
              width={Math.max(0, (bw - 36 * u) * level)}
              height={bh - 36 * u}
              rx={16 * u}
              fill={theme.accent}
            />
            {[0, 1].map((k) => (
              <DrawPath
                key={k}
                d={`M ${bx + (bw - 36 * u) * (k === 0 ? 0.88 : 0.8) + 18 * u} ${by + 10 * u} l 0 ${bh - 20 * u}`}
                progress={progress(frame, k === 0 ? tHeat : tDash, s(0.35))}
                stroke={theme.bg}
                strokeWidth={6 * u}
              />
            ))}
          </svg>
        </Layer>

        <Layer z={240}>
          <div
            style={{
              position: "absolute",
              left: portrait ? width / 2 - 260 * u : bx + bw * 0.55,
              top: portrait ? by + bh + 70 * u : by - 150 * u,
              padding: `${22 * u}px ${34 * u}px`,
              borderRadius: 22 * u,
              background: theme.panel,
              border: `${3 * u}px solid ${theme.accent}`,
              transform: `scale(${0.85 + 0.15 * readIn})`,
              opacity: Math.min(1, readIn * 1.5),
              fontFamily: font.display,
              fontWeight: 800,
              fontSize: 92 * u,
              color: theme.text,
              whiteSpace: "nowrap",
            }}
          >
            {Math.round(level * 100)}%
            <span style={{ fontFamily: font.mono, fontSize: 28 * u, color: theme.muted, marginLeft: 18 * u, letterSpacing: 3 * u }}>
              CAPACITY
            </span>
          </div>
        </Layer>
      </Depth>

      {/* Headline in screen space: the dolly never crops it. */}
      <div style={{ position: "absolute", left: safe.left, top: safe.top, opacity: titleIn, transform: `translateY(${(1 - titleIn) * 20 * u}px)` }}>
        <div style={{ fontFamily: font.mono, fontSize: 26 * u, letterSpacing: 4 * u, color: theme.muted, marginBottom: 14 * u }}>
          WHY BATTERIES AGE
        </div>
        <FitText text="Heat steals capacity" width={portrait ? width - safe.left - safe.right : width * 0.42} maxSize={84 * u} maxLines={2} color={theme.text} />
      </div>

      <div
        style={{
          position: "absolute",
          left: safe.left,
          bottom: safe.bottom,
          fontFamily: font.body,
          fontSize: 34 * u,
          color: theme.muted,
          opacity: interpolate(frame, [tDash, tDash + s(0.4)], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" }),
        }}
      >
        {["summer", "dashboard", "for good"].map((w, i) => (
          <span key={w} style={{ marginRight: 26 * u, opacity: progress(frame, stagger(i, tDash, s(0.12)), s(0.3)) }}>
            · {w}
          </span>
        ))}
      </div>
    </AbsoluteFill>
  );
}
