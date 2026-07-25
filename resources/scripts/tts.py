#!/usr/bin/env python3
"""
TTS generation script using Kokoro
"""

import sys
import json
import argparse
import io
import contextlib
from pathlib import Path
from kokoro import KPipeline

SAMPLE_RATE = 24000  # Kokoro always outputs 24 kHz — KPipeline has no .samplerate attr


def generate_tts(text, voice='am_michael', speed=1.05, lang_code='a', output_path=None):
    """Generate TTS using Kokoro — returns output path or None."""
    try:
        import soundfile as sf
        import numpy as np

        pipeline = KPipeline(lang_code=lang_code)

        # NOTE: split_pattern is NOT a valid KPipeline parameter — removed
        generator = pipeline(text, voice=voice, speed=speed)

        audio_segments = []
        with io.StringIO() as buf, contextlib.redirect_stdout(buf):
            for result in generator:
                audio_segments.append(result.audio)

        if not audio_segments:
            print("Error generating TTS: pipeline produced no audio", file=sys.stderr)
            return None

        combined_audio = np.concatenate(audio_segments)

        if output_path:
            sf.write(output_path, combined_audio, SAMPLE_RATE)
        else:
            import tempfile
            with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as f:
                output_path = f.name
            sf.write(output_path, combined_audio, SAMPLE_RATE)

        return output_path

    except Exception as e:
        print(f"Error generating TTS: {e}", file=sys.stderr)
        return None


def generate_tts_with_timing(text, voice='am_michael', speed=1.05, lang_code='a',
                              output_path=None, timings_path=None):
    """
    Generate TTS with word-level timing.

    Uses result.tokens from Kokoro for accurate per-word timestamps —
    NOT a divided-duration estimate.
    """
    try:
        import soundfile as sf
        import numpy as np

        pipeline = KPipeline(lang_code=lang_code)

        # NOTE: split_pattern is NOT a valid KPipeline parameter — removed
        generator = pipeline(text, voice=voice, speed=speed)

        audio_segments = []
        timings = []
        current_time = 0.0

        with io.StringIO() as buf, contextlib.redirect_stdout(buf):
            for result in generator:
                audio = result.audio
                audio_segments.append(audio)
                chunk_duration = len(audio) / SAMPLE_RATE

                # Use actual token timestamps provided by Kokoro
                # result.tokens gives per-word start_ts / end_ts within this chunk
                if result.tokens:
                    for t in result.tokens:
                        if t.start_ts is not None and t.end_ts is not None:
                            word = t.text.strip()
                            if word:
                                timings.append({
                                    'word':  word,
                                    'start': round(current_time + t.start_ts, 4),
                                    'end':   round(current_time + t.end_ts,   4),
                                })

                current_time += chunk_duration

        if not audio_segments:
            print("Error generating TTS: pipeline produced no audio", file=sys.stderr)
            return None

        combined_audio = np.concatenate(audio_segments)

        if output_path:
            sf.write(output_path, combined_audio, SAMPLE_RATE)
        else:
            import tempfile
            with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as f:
                output_path = f.name
            sf.write(output_path, combined_audio, SAMPLE_RATE)

        if timings_path:
            with open(timings_path, 'w') as f:
                json.dump(timings, f, indent=2)

        return {
            'audio_path': output_path,
            'timings':    timings,
        }

    except Exception as e:
        print(f"Error generating TTS with timing: {e}", file=sys.stderr)
        return None


def generate_scenes_tts(scenes, voice='am_michael', speed=1.05, lang_code='a',
                         output_path=None, timings_path=None):
    """
    Generate TTS for multiple scenes and map word timings back to each scene.
    """
    try:
        # NOTE: do NOT create a KPipeline here — generate_tts_with_timing
        # creates its own internally. Creating one here and ignoring it
        # wastes ~2-3 seconds and memory loading the model twice.

        all_text = ' '.join([scene['line'] for scene in scenes])

        result = generate_tts_with_timing(
            all_text, voice, speed, lang_code, output_path, timings_path
        )

        if not result:
            return None

        if timings_path:
            with open(timings_path, 'r') as f:
                timings = json.load(f)

            # Map flat word timings back to individual scenes
            scene_timings = []
            current_word = 0

            for scene in scenes:
                scene_words = scene['line'].split()
                word_count  = len(scene_words)
                scene_timings.append({
                    'scene_id': scene['id'],
                    'words':    timings[current_word : current_word + word_count],
                })
                current_word += word_count

            with open(timings_path, 'w') as f:
                json.dump({
                    'scene_timings': scene_timings,
                    'total':         len(scene_timings),
                }, f, indent=2)

        return result

    except Exception as e:
        print(f"Error generating scenes TTS: {e}", file=sys.stderr)
        return None


def main():
    parser = argparse.ArgumentParser(description="Generate TTS using Kokoro")
    parser.add_argument("command", choices=['tts', 'tts-timing', 'tts-scenes'])
    parser.add_argument("text_or_file", help="Text to convert or path to scenes JSON")
    parser.add_argument("--voice",     default="am_michael")
    parser.add_argument("--speed",     type=float, default=1.05)
    parser.add_argument("--lang-code", default="a")
    parser.add_argument("--output",    help="Output audio file path (.wav)")
    parser.add_argument("--timings",   help="Output timings file path (.json)")

    args = parser.parse_args()

    if args.command == 'tts':
        result = generate_tts(
            args.text_or_file, args.voice, args.speed, args.lang_code, args.output
        )
    elif args.command == 'tts-timing':
        result = generate_tts_with_timing(
            args.text_or_file, args.voice, args.speed, args.lang_code,
            args.output, args.timings
        )
    elif args.command == 'tts-scenes':
        with open(args.text_or_file, 'r') as f:
            scenes = json.load(f)
        result = generate_scenes_tts(
            scenes, args.voice, args.speed, args.lang_code,
            args.output, args.timings
        )

    if result:
        print(json.dumps(result if isinstance(result, dict) else {"audio_path": result}, indent=2))
    else:
        print("TTS generation failed", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()