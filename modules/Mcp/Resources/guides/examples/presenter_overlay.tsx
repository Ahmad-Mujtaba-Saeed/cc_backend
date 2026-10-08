import { AbsoluteFill, interpolate } from "remotion";
import { useHero, FitText, DrawPath, pop, progress, ease } from "hero-kit";

// Presenter mode, layout "full": the speaker fills the frame and this scene
// is drawn ON TOP of their footage — so the background stays transparent.
// Speaker says: "Most people think you need talent. You don't. You need about
// twenty minutes a day."
//
// Idea: a lower third introduces the claim, then the key number pops in the
// open space beside the speaker, underlined on the word "day".
export default function Scene() {
  const { frame, fps, width, height, u, s, theme, font, safe, cue, portrait, overlay } = useHero();

  const tTalent = cue("talent", 0.15);
  // Whisper writes spoken numbers as digits: cue the "20", not "twenty".
  const tTwenty = cue("20", 0.6);
  const tDay = cue("day", 0.85);

  // Lower third: slides in early, leaves when the number arrives.
  const ltIn = progress(frame, tTalent - s(0.1), s(0.4), ease.outQuint);
  const ltOut = progress(frame, tTwenty - s(0.3), s(0.3), ease.inOut);
  const lt = ltIn * (1 - ltOut);

  const numIn = pop(frame, tTwenty - s(0.05), fps, { damping: 12 });
  const under = progress(frame, tDay - s(0.05), s(0.45), ease.outQuint);

  // Open space: the speaker is usually centred, so use the right third in
  // landscape and the top third in portrait.
  const boxW = portrait ? width - safe.left - safe.right : width * 0.34;
  const boxX = portrait ? safe.left : width - safe.right - boxW;
  const boxY = portrait ? safe.top + 40 * u : height * 0.2;

  return (
    <AbsoluteFill style={{ background: overlay ? "transparent" : theme.bg }}>
      {/* Readability: a soft gradient only behind the text, never a full card. */}
      <AbsoluteFill
        style={{
          background: portrait
            ? `linear-gradient(180deg, rgba(0,0,0,${0.55 * numIn}) 0%, rgba(0,0,0,0) 45%)`
            : `linear-gradient(270deg, rgba(0,0,0,${0.5 * numIn}) 0%, rgba(0,0,0,0) 45%)`,
        }}
      />

      <div
        style={{
          position: "absolute",
          left: safe.left,
          bottom: safe.bottom + 20 * u,
          transform: `translateX(${(1 - lt) * -60 * u}px)`,
          opacity: lt,
          display: "flex",
          alignItems: "stretch",
        }}
      >
        <div style={{ width: 10 * u, background: theme.accent, borderRadius: 4 * u }} />
        <div style={{ background: "rgba(0,0,0,0.6)", padding: `${16 * u}px ${28 * u}px`, borderRadius: `0 ${12 * u}px ${12 * u}px 0` }}>
          <div style={{ fontFamily: font.mono, fontSize: 24 * u, letterSpacing: 3 * u, color: theme.accent }}>MYTH</div>
          <div style={{ fontFamily: font.display, fontWeight: 700, fontSize: 46 * u, color: "#fff" }}>You need talent</div>
        </div>
      </div>

      <div style={{ position: "absolute", left: boxX, top: boxY, width: boxW, opacity: Math.min(1, numIn * 1.4) }}>
        <div
          style={{
            fontFamily: font.display,
            fontWeight: 800,
            fontSize: 200 * u,
            lineHeight: 1.05,
            marginBottom: 10 * u,
            color: "#fff",
            transform: `scale(${0.7 + 0.3 * numIn})`,
            transformOrigin: portrait ? "left top" : "right top",
            textAlign: portrait ? "left" : "right",
          }}
        >
          20
        </div>
        <FitText
          text="minutes a day"
          width={boxW}
          maxSize={64 * u}
          maxLines={1}
          align={portrait ? "left" : "right"}
          color="#fff"
          style={{ opacity: interpolate(frame, [tTwenty + s(0.2), tTwenty + s(0.5)], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" }) }}
        />
        <svg width={boxW} height={30 * u} style={{ display: "block", marginTop: 6 * u }}>
          <DrawPath d={`M ${boxW * 0.12} ${18 * u} Q ${boxW * 0.55} ${4 * u} ${boxW} ${16 * u}`} progress={under} stroke={theme.accent} strokeWidth={9 * u} />
        </svg>
      </div>
    </AbsoluteFill>
  );
}
