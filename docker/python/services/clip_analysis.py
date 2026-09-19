"""
Clip analysis for the Long Video to Shorts editor.

One pass over an already-cut clip answers the questions the layout planner and
the edit director need before a single frame is composed:

  * where the FACES are over time (tracked, not per-frame noise), so a 9:16
    crop can follow a speaker and a two-person podcast can be stacked;
  * WHICH of those faces is the subject — the one the camera should ride —
    and which are bystanders walking through an IRL shot;
  * where the SCENE CUTS are, so a crop never glides across a hard cut;
  * how much MOTION each moment has (gameplay reads as high, a podcast as low);
  * where the AUDIO PEAKS are — the laugh, the scream, the "WHAT" — which is
    where a meme edit wants its punch-in;
  * a handful of downscaled FRAMES on disk for the vision model, which labels
    what the scene actually is (facecam over gameplay, screen share, ...).

DETECTION (rewritten 2026-09-18). The first version detected with Haar cascades
at ONE sample per second and it did not work: on handheld IRL footage Haar
found a face in well under half the sampled frames, every track fell under the
presence gate, and every short in a real run came out `blur_fit` — the video
letterboxed into a thin band inside a blurred void, with the subject never
framed. Measured on a real clip from that run, per frame: YuNet 4-5 faces,
Haar 0-3 (zero twice).

So now:
  * YuNet (`FACE_YUNET_MODEL`, default /app/models/face_detection_yunet_2023mar.onnx)
    is the detector, at 960px when the source is HD so a face that is 3% of the
    frame width is still ~30px to the network. Haar stays only as the fallback
    for an installation with no model file.
  * Sampling is ~3 per second, not 1, because "the camera follows the subject"
    is a statement about motion and one sample per second cannot describe it.
    Frames are SKIPPED WITH grab(), never sought: seeking per sample costs more
    than decoding the whole clip.
  * Tracks carry a `speaking` score from mouth-region movement and a `score`
    that ranks the subject above the crowd, so the planner can follow the
    person the short is about instead of whoever stands furthest left.

Everything is best-effort: a detector failure yields no faces, never an error.
"""

from __future__ import annotations

import math
import os
import subprocess
from typing import Any

import numpy as np

YUNET_PATH = os.environ.get('FACE_YUNET_MODEL', '/app/models/face_detection_yunet_2023mar.onnx')

#: Width the frame is scaled to before detection. HD sources get the larger
#: size: at 640px a face 3.5% of the frame is 22px, which YuNet finds only
#: intermittently, and an intermittent face is a track that gets dropped.
DETECT_WIDTH = 640
DETECT_WIDTH_HD = 960

#: Grayscale patch of the mouth region kept per detection, for the speaking
#: score. Small on purpose — it is a change detector, not a lip reader.
MOUTH_PATCH = (24, 16)

#: How far the camera may pan within one shot, in frame widths per second.
#: A real operator reframing from one speaker to another takes about a third of
#: a second to cross a third of the frame; faster than this reads as a glitch.
MAX_PAN_PER_SECOND = 0.45


class _FaceDetector:
    """YuNet when its model is on disk, Haar cascades otherwise."""

    def __init__(self, source_width: int = 0) -> None:
        import cv2

        self.cv2 = cv2
        self.kind = 'haar'
        self.yunet = None
        self.width = DETECT_WIDTH_HD if source_width >= 1280 else DETECT_WIDTH
        if os.path.exists(YUNET_PATH) and hasattr(cv2, 'FaceDetectorYN'):
            try:
                self.yunet = cv2.FaceDetectorYN.create(YUNET_PATH, '', (320, 320), 0.55, 0.3, 5000)
                self.kind = 'yunet'
            except Exception:
                self.yunet = None
        if self.yunet is None:
            self.width = DETECT_WIDTH
            base = cv2.data.haarcascades
            self.frontal = cv2.CascadeClassifier(base + 'haarcascade_frontalface_alt2.xml')
            self.profile = cv2.CascadeClassifier(base + 'haarcascade_profileface.xml')

    def detect(self, frame: np.ndarray) -> tuple[list[dict], np.ndarray]:
        """Faces in the frame, plus the downscaled gray image they were found in.

        The gray image is returned so the caller can cut mouth patches out of it
        without scaling the frame a second time.
        """
        cv2 = self.cv2
        h, w = frame.shape[:2]
        scale = self.width / float(max(1, w))
        small = cv2.resize(frame, (int(w * scale), int(h * scale)))
        sh, sw = small.shape[:2]
        gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
        boxes: list[tuple[float, float, float, float, float]] = []

        if self.yunet is not None:
            self.yunet.setInputSize((sw, sh))
            _, faces = self.yunet.detect(small)
            if faces is not None:
                for f in faces:
                    boxes.append((float(f[0]), float(f[1]), float(f[2]), float(f[3]), float(f[14])))
        else:
            eq = cv2.equalizeHist(gray)
            min_size = (max(20, sw // 28), max(20, sw // 28))
            for (x, y, fw, fh) in self.frontal.detectMultiScale(eq, 1.1, 5, minSize=min_size):
                boxes.append((x, y, fw, fh, 1.0))
            for flipped in (False, True):
                img = cv2.flip(eq, 1) if flipped else eq
                for (x, y, fw, fh) in self.profile.detectMultiScale(img, 1.1, 6, minSize=min_size):
                    if flipped:
                        x = sw - x - fw
                    boxes.append((x, y, fw, fh, 0.7))

        # Normalise, drop specks, and merge overlapping hits so one person is
        # one box. 2.2% of the width is about a 24px face on a 1080p source —
        # below that it is a passer-by in the background, not a subject.
        norm = []
        for (x, y, fw, fh, score) in boxes:
            if fw / sw < 0.022 or fw <= 0 or fh <= 0:
                continue
            norm.append({
                'x': max(0.0, x / sw), 'y': max(0.0, y / sh),
                'w': min(1.0, fw / sw), 'h': min(1.0, fh / sh),
                'score': round(score, 3),
                '_px': (x, y, fw, fh),
            })
        norm.sort(key=lambda b: b['w'] * b['h'], reverse=True)
        merged: list[dict] = []
        for b in norm:
            if all(_iou(b, m) < 0.3 for m in merged):
                merged.append(b)

        out = []
        for b in merged[:8]:
            px = b.pop('_px')
            b = {k: round(v, 4) if isinstance(v, float) else v for k, v in b.items()}
            b['_mouth'] = _mouth_patch(cv2, gray, px)
            out.append(b)
        return out, gray


def _mouth_patch(cv2, gray: np.ndarray, px: tuple) -> np.ndarray | None:
    """The lower third of a face box, as a tiny fixed-size grayscale patch.

    Comparing this patch with the same track's previous one is a cheap proxy
    for "is this person talking": a mouth moves, a listening face does not.
    """
    x, y, w, h = (int(round(v)) for v in px)
    gh, gw = gray.shape[:2]
    y0 = max(0, y + int(h * 0.55))
    y1 = min(gh, y + int(h * 1.05))
    x0 = max(0, x + int(w * 0.15))
    x1 = min(gw, x + int(w * 0.85))
    if y1 - y0 < 4 or x1 - x0 < 4:
        return None
    try:
        patch = cv2.resize(gray[y0:y1, x0:x1], MOUTH_PATCH).astype(np.float32)
    except Exception:
        return None
    # Normalise brightness so a lighting change is not read as a moving mouth.
    return patch - float(patch.mean())


def _iou(a: dict, b: dict) -> float:
    ax2, ay2 = a['x'] + a['w'], a['y'] + a['h']
    bx2, by2 = b['x'] + b['w'], b['y'] + b['h']
    iw = max(0.0, min(ax2, bx2) - max(a['x'], b['x']))
    ih = max(0.0, min(ay2, by2) - max(a['y'], b['y']))
    inter = iw * ih
    union = a['w'] * a['h'] + b['w'] * b['h'] - inter
    return inter / union if union > 0 else 0.0


def _centre(b: dict) -> tuple[float, float]:
    return b['x'] + b['w'] / 2.0, b['y'] + b['h'] / 2.0


def _spread(points: list[dict]) -> float:
    """10th-90th percentile range of a track's centre, the larger of x and y."""
    if len(points) < 4:
        return 1.0
    xs = sorted(p['x'] + p['w'] / 2.0 for p in points)
    ys = sorted(p['y'] + p['h'] / 2.0 for p in points)
    lo, hi = int((len(xs) - 1) * 0.1), int((len(xs) - 1) * 0.9)
    return max(xs[hi] - xs[lo], ys[hi] - ys[lo])


def _build_tracks(samples: list[dict], sample_count: int) -> list[dict]:
    """Greedy nearest-centre association of per-frame faces into tracks.

    A track that shows up in under a fifth of the frames is a false positive
    (a poster, a pattern in the wallpaper) or an extra; it is dropped.

    Each surviving track is then SCORED, because on IRL footage the frame is
    full of faces and only one of them is the short's subject. The score favours
    a face that is big (close to camera), present, near the middle, and moving
    its mouth — which is as close to "the person talking" as this gets without
    audio-visual sync. The planner reads `primary` / `rank`; `id` stays
    left-to-right so labels still read naturally.
    """
    tracks: list[dict] = []
    for s in samples:
        used: set[int] = set()
        for face in s['faces']:
            cx, cy = _centre(face)
            best, best_d = None, 0.14
            for ti, tr in enumerate(tracks):
                if ti in used:
                    continue
                lx, ly = _centre(tr['points'][-1]['box'])
                d = math.hypot(cx - lx, (cy - ly) * 0.6)
                if d < best_d:
                    best, best_d = ti, d
            if best is None:
                tracks.append({'points': []})
                best = len(tracks) - 1
            used.add(best)
            tracks[best]['points'].append({'t': s['t'], 'box': face, 'motion': s['motion']})

    out = []
    for tr in tracks:
        pts = tr['points']
        presence = len(pts) / max(1, sample_count)
        if presence < 0.2 or len(pts) < 2:
            continue
        xs = sorted(_centre(p['box'])[0] for p in pts)
        ys = sorted(_centre(p['box'])[1] for p in pts)
        sizes = sorted(p['box']['w'] for p in pts)
        out.append({
            'presence': round(presence, 3),
            'cx': round(xs[len(xs) // 2], 4),
            'cy': round(ys[len(ys) // 2], 4),
            'size': round(sizes[len(sizes) // 2], 4),
            'speaking': _speaking_score(pts),
            'points': [{'t': p['t'], **{k: v for k, v in p['box'].items() if k != '_mouth'}} for p in pts],
        })

    for t in out:
        # Size dominates: the subject of a handheld vlog is the person nearest
        # the lens. Centrality breaks ties between two equally close faces.
        centrality = 1.0 - min(1.0, abs(t['cx'] - 0.5) * 1.6)
        t['score'] = round(
            min(1.0, t['size'] / 0.18) * 1.0
            + t['presence'] * 0.7
            + centrality * 0.5
            + t['speaking'] * 0.6,
            4,
        )

    out.sort(key=lambda t: t['score'], reverse=True)
    top = out[:4]
    # A streamer's webcam face is small, off-centre and scores below big game
    # characters, so the top-4 cut dropped it (project 195, clip 2) — and it is
    # the one face the facecam layout needs. Keep the steadiest small face too.
    steady = [t for t in out[4:] if t['size'] <= 0.2 and _spread(t['points']) <= 0.04]
    if steady:
        top.append(max(steady, key=lambda t: t['presence']))
    for i, t in enumerate(top):
        t['rank'] = i
        t['primary'] = i == 0
    # Left-to-right labels read naturally in the layout ("face_1" = left).
    top.sort(key=lambda t: t['cx'])
    for i, t in enumerate(top):
        t['id'] = f'face_{i + 1}'
    return top


def _speaking_score(points: list[dict]) -> float:
    """0-1: how much this track's mouth region changes between samples.

    Normalised against whole-frame motion, so a handheld camera swinging past a
    silent bystander does not read as speech.
    """
    diffs = []
    prev = None
    for p in points:
        patch = p['box'].get('_mouth')
        if patch is not None and prev is not None and patch.shape == prev.shape:
            d = float(np.mean(np.abs(patch - prev))) / 255.0
            # Subtract the camera's own movement; what is left is the face.
            diffs.append(max(0.0, d - float(p.get('motion', 0.0)) * 0.5))
        if patch is not None:
            prev = patch
    if not diffs:
        return 0.0
    return round(min(1.0, float(np.mean(diffs)) / 0.05), 4)


def _camera_path(samples: list[dict], cuts: list[float]) -> dict | None:
    """Where the 9:16 crop should look, sample by sample.

    WHY THIS EXISTS SEPARATELY FROM THE TRACKS. A track is one person followed
    across the clip, and on handheld IRL footage tracks FRAGMENT: the camera
    whips, the subject turns away, the detector misses three samples, and what
    was one person becomes four short tracks, each below the presence gate and
    each thrown away. Measured on a real clip: 320 detections shattered into 15
    tracks, the longest covering 27% of the frames. A camera driven by "the
    best track" is then a camera driven by nothing.

    So the camera is driven by the DETECTIONS instead. At each sample we pick
    the best face available right now, and the score is dominated by coherence
    with where the camera already is — which keeps the shot on one person
    without needing that person's identity to survive a dropout. When there is
    no face at all the camera holds its last position rather than snapping to
    the middle of the frame. A scene cut clears the coherence bonus, because
    after a cut the previous position means nothing.

    Returns a track-shaped dict so the PHP planner can feed it to followKeys()
    exactly like a real face track.
    """
    cam: tuple[float, float] | None = None
    size = 0.1
    raw: list[dict] = []
    prev_t = -1.0
    for s in samples:
        t = float(s['t'])
        cut = any(prev_t < c <= t for c in cuts)
        prev_t = t
        if cut:
            cam = None
        best, best_score = None, -1.0
        for f in s['faces']:
            cx, cy = _centre(f)
            centrality = 1.0 - min(1.0, abs(cx - 0.5) * 1.4)
            if cam is None:
                coherence = 0.5
            else:
                d = math.hypot(cx - cam[0], (cy - cam[1]) * 0.6)
                coherence = max(0.0, 1.0 - d / 0.45)
            score = (min(1.0, f['w'] / 0.15) * 1.0
                     + centrality * 0.35
                     + coherence * 0.9
                     + float(f.get('score', 1.0)) * 0.2)
            if score > best_score:
                best, best_score = f, score
        if best is not None:
            cam = _centre(best)
            size = float(best['w'])
            conf = 1.0
        elif cam is None:
            continue
        else:
            conf = 0.0
        raw.append({'t': round(t, 3), 'cx': cam[0], 'cy': cam[1], 'size': size, 'conf': conf})

    if len(raw) < 2:
        return None

    # Median-smooth the path so detector jitter (a box that breathes a few
    # pixels every frame) does not become camera shake. The window is short:
    # this removes noise, it does not remove the move.
    win = 3
    sm: list[dict] = []
    last: tuple[float, float] | None = None
    last_t = raw[0]['t']
    for i, p in enumerate(raw):
        lo, hi = max(0, i - win), min(len(raw), i + win + 1)
        near = [q for q in raw[lo:hi] if not _crossed(raw[i]['t'], q['t'], cuts)]
        xs = sorted(q['cx'] for q in near)
        ys = sorted(q['cy'] for q in near)
        ws = sorted(q['size'] for q in near)
        cx, cy, w = xs[len(xs) // 2], ys[len(ys) // 2], ws[len(ws) // 2]

        # Rate limit. The coherence term keeps the camera on one person, but
        # when the subject genuinely changes — a second speaker, the detector
        # finally finding the face that was turned away — the target can move
        # half the frame in one sample. Unclamped that renders as a teleport.
        # Across a cut it SHOULD be instant; within a shot it glides.
        if last is not None and not _crossed(last_t, p['t'], cuts):
            span = max(1e-3, p['t'] - last_t)
            budget = MAX_PAN_PER_SECOND * span
            dx, dy = cx - last[0], cy - last[1]
            dist = math.hypot(dx, dy)
            if dist > budget:
                k = budget / dist
                cx, cy = last[0] + dx * k, last[1] + dy * k
        last, last_t = (cx, cy), p['t']

        sm.append({
            't': p['t'],
            'x': round(cx - w / 2, 4),
            'y': round(cy - w / 2, 4),
            'w': round(w, 4),
            'h': round(w * 1.25, 4),
        })

    held = sum(1 for p in raw if p['conf'] < 0.5)
    sizes = sorted(p['size'] for p in raw)
    cxs = sorted(p['cx'] for p in raw)
    cys = sorted(p['cy'] for p in raw)
    return {
        'id': 'camera',
        'presence': round(1.0 - held / max(1, len(raw)), 3),
        'coverage': round(len(raw) / max(1, len(samples)), 3),
        'cx': round(cxs[len(cxs) // 2], 4),
        'cy': round(cys[len(cys) // 2], 4),
        'size': round(sizes[len(sizes) // 2], 4),
        'points': sm,
    }


EDGE_W = 320          # width the edge-persistence maps are accumulated at
MOTION_W, MOTION_H = 160, 90


def _find_webcam(tracks: list[dict], v_persist: np.ndarray | None,
                 h_persist: np.ndarray | None) -> dict | None:
    """The streamer's webcam rectangle, found from pixels rather than asked of
    a vision model.

    WHY. On project 195 gpt-4o-mini was asked for the facecam box on 8 clips of
    a Twitch stream and answered [0.05, 0.05, 0.2, 0.2] — the top-left corner —
    on 7 of them, and the webcam was nowhere near it. The model can SAY there is
    a webcam; it cannot say where. Every "facecam on top" short showed a health
    bar.

    HOW. Two facts about a webcam overlay: the face inside it barely moves, and
    its border is an edge in nearly every frame (webcam footage on one side,
    game on the other — or a drawn frame). So: anchor on the steadiest small
    face, then walk outward in each direction looking for the line that is an
    edge in the most frames. A side with no such line runs to the frame edge
    (a webcam flush with the corner). The result is sanity-checked and dropped
    rather than guessed when it does not look like an inset.
    """
    anchor = None
    for t in tracks:
        if t['presence'] < 0.3 or t['size'] > 0.2 or _spread(t['points']) > 0.04:
            continue
        if anchor is None or t['presence'] > anchor['presence']:
            anchor = t
    if anchor is None or v_persist is None or h_persist is None:
        return None

    ph, pw = v_persist.shape
    hs = sorted(p['h'] for p in anchor['points'])
    fw, fh = anchor['size'], hs[len(hs) // 2]
    cx, cy = anchor['cx'], anchor['cy']

    def col_score(x: int, y0: float, y1: float) -> float:
        a, b = max(0, int(y0 * ph)), min(ph, int(y1 * ph) + 1)
        return float(v_persist[a:b, x].mean()) if b > a else 0.0

    def row_score(y: int, x0: float, x1: float) -> float:
        a, b = max(0, int(x0 * pw)), min(pw, int(x1 * pw) + 1)
        return float(h_persist[y, a:b].mean()) if b > a else 0.0

    def best(scores: list[tuple[float, float]], border: float) -> float | None:
        # Walk outward from the face. Inside the webcam, camera footage keeps
        # the persistence moderate and bumpy (the ring light behind the head on
        # project 195 was the STRONGEST line, 0.92, and not the border); past
        # the border it is game footage, persistently low for a long stretch.
        # So the border is the last strong line before the first long low run.
        # Taking the strongest line picked the ring light; taking the outermost
        # one walked on into the HUD.
        if not scores:
            return border
        step = abs(scores[1][0] - scores[0][0]) if len(scores) > 1 else 0.01
        need = max(2, int(round(0.035 / max(step, 1e-4))))
        last_peak, low_run = None, 0
        for pos, sc in scores:
            if sc >= 0.45:
                last_peak, low_run = pos, 0
            elif sc < 0.3:
                low_run += 1
                # A smooth wall next to the face is low too; only a low run
                # AFTER a candidate line means we have left the webcam.
                if low_run >= need and last_peak is not None:
                    return last_peak
            else:
                low_run = 0
        if abs(scores[-1][0] - border) < 0.02:
            return last_peak if last_peak is not None and abs(last_peak - border) < 0.04 else border
        return last_peak

    band_y = (cy - fh * 1.2, cy + fh * 1.2)
    lefts = [(x / pw, col_score(x, *band_y)) for x in range(int((cx - fw * 0.9) * pw), max(-1, int((cx - 0.4) * pw)), -1) if x >= 0]
    rights = [(x / pw, col_score(x, *band_y)) for x in range(int((cx + fw * 0.9) * pw), min(pw, int((cx + 0.4) * pw)))]
    left = best(lefts, 0.0)
    right = best(rights, 1.0)
    if left is None or right is None:
        return None
    band_x = (max(left, cx - fw * 2), min(right, cx + fw * 2))
    tops = [(y / ph, row_score(y, *band_x)) for y in range(int((cy - fh * 0.9) * ph), max(-1, int((cy - 0.45) * ph)), -1) if y >= 0]
    bottoms = [(y / ph, row_score(y, *band_x)) for y in range(int((cy + fh * 0.9) * ph), min(ph, int((cy + 0.45) * ph)))]
    top = best(tops, 0.0)
    bottom = best(bottoms, 1.0)
    if top is None or bottom is None:
        return None

    w, h = right - left, bottom - top
    # An inset: bigger than the face, clearly smaller than the frame.
    if w < fw * 2.0 or h < fh * 1.6 or w > 0.6 or h > 0.7 or w * h > 0.25:
        return None
    return {'box': [round(left, 4), round(top, 4), round(w, 4), round(h, 4)],
            'face': anchor.get('id'), 'method': 'edge_persistence'}


def _action_path(diffs: list[tuple[float, np.ndarray]], exclude: list | None,
                 cuts: list[float]) -> dict | None:
    """Where the game action is, sample by sample: the centroid of what changed
    since the previous sample, with the webcam masked out (its motion is the
    streamer, not the game). Shaped like a face track so the planner's
    followKeys() can drive the gameplay panel with it."""
    if len(diffs) < 4:
        return None
    mask = np.ones((MOTION_H, MOTION_W), dtype=np.float32)
    if exclude:
        x, y, w, h = exclude
        mask[int(y * MOTION_H):int((y + h) * MOTION_H) + 1, int(x * MOTION_W):int((x + w) * MOTION_W) + 1] = 0
    xs_grid = (np.arange(MOTION_W, dtype=np.float32) + 0.5) / MOTION_W
    ys_grid = (np.arange(MOTION_H, dtype=np.float32) + 0.5) / MOTION_H
    raw: list[dict] = []
    last = (0.5, 0.5)
    for t, d in diffs:
        m = d.astype(np.float32) * mask
        m[m < 18] = 0          # sensor noise / compression shimmer
        total = float(m.sum())
        if total > MOTION_W * MOTION_H * 0.4:
            cx = float((m.sum(axis=0) * xs_grid).sum() / total)
            cy = float((m.sum(axis=1) * ys_grid).sum() / total)
            # Pull toward the middle: a centroid is noisy, and the middle of
            # the game is where the player's character usually is.
            last = (0.5 + (cx - 0.5) * 0.8, 0.5 + (cy - 0.5) * 0.5)
        raw.append({'t': t, 'cx': last[0], 'cy': last[1]})

    sm: list[dict] = []
    prev = None
    prev_t = raw[0]['t']
    win = 4
    for i, p in enumerate(raw):
        near = [q for q in raw[max(0, i - win):i + win + 1] if not _crossed(p['t'], q['t'], cuts)]
        cx = sorted(q['cx'] for q in near)[len(near) // 2]
        cy = sorted(q['cy'] for q in near)[len(near) // 2]
        if prev is not None and not _crossed(prev_t, p['t'], cuts):
            budget = 0.3 * max(1e-3, p['t'] - prev_t)   # slower than a face: it is a scene
            dx, dy = cx - prev[0], cy - prev[1]
            dist = math.hypot(dx, dy)
            if dist > budget:
                cx, cy = prev[0] + dx * budget / dist, prev[1] + dy * budget / dist
        prev, prev_t = (cx, cy), p['t']
        sm.append({'t': p['t'], 'x': round(cx - 0.05, 4), 'y': round(cy - 0.05, 4), 'w': 0.1, 'h': 0.1})
    return {'id': 'action', 'presence': 1.0, 'coverage': 1.0,
            'cx': 0.5, 'cy': 0.5, 'size': 0.1, 'points': sm}


def _crossed(a: float, b: float, cuts: list[float]) -> bool:
    lo, hi = (a, b) if a <= b else (b, a)
    return any(lo < c <= hi for c in cuts)


def _audio_peaks(video_path: str, duration: float, max_peaks: int = 8) -> dict:
    """Loud moments (laughs, shouts, impacts) from a mono 8 kHz decode."""
    try:
        proc = subprocess.run(
            ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-i', video_path,
             '-vn', '-ac', '1', '-ar', '8000', '-f', 's16le', '-'],
            capture_output=True, timeout=120,
        )
        pcm = np.frombuffer(proc.stdout, dtype=np.int16).astype(np.float32) / 32768.0
    except Exception:
        return {'peaks': [], 'energy': []}
    if pcm.size < 8000:
        return {'peaks': [], 'energy': []}

    win = 2000  # 0.25 s
    n = pcm.size // win
    frames = pcm[: n * win].reshape(n, win)
    rms = np.sqrt(np.mean(frames ** 2, axis=1)) + 1e-6
    db = 20 * np.log10(rms)
    speech = db[db > -45]
    median = float(np.median(speech)) if speech.size else float(np.median(db))

    peaks = []
    min_gap = 6  # windows (1.5 s)
    order = np.argsort(-db)
    taken: list[int] = []
    for i in order:
        if db[i] < median + 7.0 or len(peaks) >= max_peaks:
            break
        if any(abs(int(i) - j) < min_gap for j in taken):
            continue
        # Must be a rise, not the middle of a long loud stretch.
        prev = db[max(0, i - 4):i]
        if prev.size and float(np.mean(prev)) > db[i] - 3.0:
            continue
        taken.append(int(i))
        peaks.append({'t': round(i * 0.25 + 0.125, 3), 'db': round(float(db[i]), 1),
                      'over_median': round(float(db[i]) - median, 1)})
    peaks.sort(key=lambda p: p['t'])

    # 1-second energy envelope, normalised 0..1, for pacing decisions.
    per_sec = [float(np.mean(db[k:k + 4])) for k in range(0, n, 4)]
    lo, hi = (min(per_sec), max(per_sec)) if per_sec else (0.0, 1.0)
    energy = [round((v - lo) / (hi - lo), 3) if hi > lo else 0.0 for v in per_sec]
    return {'peaks': peaks, 'energy': energy, 'median_db': round(median, 1)}


def analyze_clip(video_path: str, frames_dir: str, interval: float = 0.35,
                 max_samples: int = 400, vlm_frames: int = 8, vlm_width: int = 640) -> dict[str, Any]:
    import cv2

    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        raise RuntimeError(f'Cannot open video: {video_path}')

    try:
        fps = float(cap.get(cv2.CAP_PROP_FPS) or 30.0) or 30.0
        total = int(cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
        width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH) or 0)
        height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT) or 0)
        duration = total / fps if total else 0.0
        if duration <= 0:
            raise RuntimeError('Video has no frames')

        step = max(interval, duration / max_samples)
        sample_every = max(1, int(round(step * fps)))
        want = max(1, int(duration / step))

        detector = _FaceDetector(width)
        os.makedirs(frames_dir, exist_ok=True)
        vlm_pick = {int(round(i * (want - 1) / max(1, min(vlm_frames, want) - 1)))
                    for i in range(min(vlm_frames, want))}

        samples: list[dict] = []
        v_acc = h_acc = None
        edge_n = 0
        diffs: list[tuple[float, np.ndarray]] = []
        prev_hist = None
        prev_small = None
        cuts: list[float] = []
        pos = 0
        idx = 0
        # Walk the clip once, decoding only the frames we sample. grab() skips a
        # frame without decoding it, which is far cheaper than seeking to each
        # sample (a seek flushes and re-primes the decoder every time).
        while idx < want:
            if pos % sample_every != 0:
                if not cap.grab():
                    break
                pos += 1
                continue
            ok, frame = cap.read()
            if not ok or frame is None:
                break
            t = round(pos / fps, 3)
            pos += 1

            faces: list[dict] = []
            try:
                faces, _ = detector.detect(frame)
            except Exception:
                faces = []

            # Edge persistence (for the webcam border) at a fixed small width.
            eh = max(2, int(round(frame.shape[0] * EDGE_W / float(max(1, frame.shape[1])))))
            eg = cv2.cvtColor(cv2.resize(frame, (EDGE_W, eh), interpolation=cv2.INTER_AREA), cv2.COLOR_BGR2GRAY)
            gx = np.abs(cv2.Sobel(eg, cv2.CV_16S, 1, 0, ksize=3)) > 60
            gy = np.abs(cv2.Sobel(eg, cv2.CV_16S, 0, 1, ksize=3)) > 60
            if v_acc is None:
                v_acc = np.zeros(gx.shape, dtype=np.float32)
                h_acc = np.zeros(gy.shape, dtype=np.float32)
            v_acc += gx
            h_acc += gy
            edge_n += 1

            small = cv2.resize(frame, (MOTION_W, MOTION_H))
            hsv = cv2.cvtColor(small, cv2.COLOR_BGR2HSV)
            hist = cv2.calcHist([hsv], [0, 1], None, [16, 8], [0, 180, 0, 256])
            cv2.normalize(hist, hist)
            gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
            motion = 0.0
            if prev_small is not None:
                diff = cv2.absdiff(gray, prev_small)
                motion = float(np.mean(diff)) / 255.0
                diffs.append((t, diff))
            if prev_hist is not None:
                corr = cv2.compareHist(prev_hist, hist, cv2.HISTCMP_CORREL)
                if corr < 0.55:
                    cuts.append(round((samples[-1]['t'] + t) / 2.0, 3) if samples else t)
            prev_hist, prev_small = hist, gray

            entry = {
                't': t,
                'faces': faces,
                'motion': round(motion, 4),
                'brightness': round(float(np.mean(gray)) / 255.0, 3),
            }
            if idx in vlm_pick:
                scale = vlm_width / float(max(1, frame.shape[1]))
                out = cv2.resize(frame, (vlm_width, int(frame.shape[0] * scale)))
                path = os.path.join(frames_dir, f'frame_{idx:03d}.jpg')
                cv2.imwrite(path, out, [cv2.IMWRITE_JPEG_QUALITY, 82])
                entry['image_path'] = path
            samples.append(entry)
            idx += 1
    finally:
        cap.release()

    tracks = _build_tracks(samples, len(samples))
    camera = _camera_path(samples, cuts)
    webcam = _find_webcam(tracks, v_acc / edge_n if edge_n else None, h_acc / edge_n if edge_n else None)
    action = _action_path(diffs, webcam['box'] if webcam else None, cuts)
    audio = _audio_peaks(video_path, duration)
    motions = [s['motion'] for s in samples[1:]]
    face_counts = [len(s['faces']) for s in samples]

    return {
        'duration': round(duration, 3),
        'width': width,
        'height': height,
        'fps': round(fps, 3),
        'detector': detector.kind,
        'sample_interval': round(step, 3),
        # Compact: the per-frame face boxes live in the tracks now, and a dense
        # sampling would otherwise push a few hundred KB through the HTTP hop
        # for a field whose only consumer is a log line.
        'samples': [{'t': s['t'], 'motion': s['motion'], 'brightness': s['brightness'],
                     'faces': len(s['faces'])} for s in samples],
        'face_tracks': tracks,
        'camera_track': camera,
        'webcam': webcam,
        'action_track': action,
        'scene_cuts': cuts,
        'audio_peaks': audio.get('peaks', []),
        'energy': audio.get('energy', []),
        'motion_mean': round(float(np.mean(motions)), 4) if motions else 0.0,
        'faces_per_frame': round(float(np.mean(face_counts)), 3) if face_counts else 0.0,
        'faces_typical': int(np.median(face_counts)) if face_counts else 0,
        'vlm_frames': [{'t': s['t'], 'path': s['image_path']} for s in samples if 'image_path' in s],
    }
