#!/usr/bin/env python3
"""
Video editing and processing script
"""

import sys
import json
import argparse
from pathlib import Path
import cv2
import numpy as np
import ffmpeg


def _load_moviepy():
    try:
        from moviepy.editor import (
            VideoFileClip,
            AudioFileClip,
            CompositeVideoClip,
            concatenate_videoclips,
            concatenate_audioclips,
            vfx
        )
        return VideoFileClip, AudioFileClip, CompositeVideoClip, concatenate_videoclips, concatenate_audioclips, vfx
    except (ImportError, ModuleNotFoundError):
        try:
            import moviepy
            from moviepy.video.io.VideoFileClip import VideoFileClip
            from moviepy.audio.io.AudioFileClip import AudioFileClip
            from moviepy.video.compositing.CompositeVideoClip import CompositeVideoClip

            concatenate_videoclips = getattr(moviepy, 'concatenate_videoclips', None)
            if concatenate_videoclips is None:
                def concatenate_videoclips(clips, method='compose'):
                    return CompositeVideoClip(clips)

            concatenate_audioclips = getattr(moviepy, 'concatenate_audioclips', None)
            if concatenate_audioclips is None:
                def concatenate_audioclips(clips):
                    return clips[0] if len(clips) == 1 else clips[0]

            from moviepy.video.fx.MultiplySpeed import MultiplySpeed

            def speedx(clip, factor=1.0):
                return clip.fx(MultiplySpeed, factor=factor)

            def colorx(clip, factor=1.0):
                return clip.image_transform(lambda frame: np.clip(frame * factor, 0, 255).astype('uint8'))

            class _VFX:
                pass

            vfx = _VFX()
            vfx.speedx = speedx
            vfx.colorx = colorx

            return VideoFileClip, AudioFileClip, CompositeVideoClip, concatenate_videoclips, concatenate_audioclips, vfx
        except Exception as e:
            raise ImportError(
                "moviepy is required for video editing. Install moviepy in the Python environment."
            ) from e

class VideoEditor:
    def __init__(self):
        pass
    
    def _slice_clip(self, clip, start, end):
        """Slice a clip using the proper moviepy API."""
        try:
            # moviepy uses subclip() method, not subclipped()
            if hasattr(clip, 'subclip'):
                return clip.subclip(start, end)
            else:
                raise AttributeError(f"Clip object has no subclip method. Available methods: {dir(clip)}")
        except Exception as e:
            print(f"DEBUG _slice_clip: Failed to slice clip from {start} to {end}: {e}", file=sys.stderr)
            raise
    
    def crop_to_faces(self, video_path, face_data, output_path, padding=0.1, smooth_transitions=True, focus_main_speaker=True):
        """Crop video to focus on detected faces"""
        print(f"DEBUG crop_to_faces: Starting with video_path={video_path}, output_path={output_path}", file=sys.stderr)
        print(f"DEBUG crop_to_faces: face_data keys={list(face_data.keys()) if face_data else None}", file=sys.stderr)
        
        try:
            # Ensure face_data has required keys
            if 'faces' not in face_data:
                face_data['faces'] = []
            if 'tracks' not in face_data:
                face_data['tracks'] = None
            if 'objects' not in face_data:
                face_data['objects'] = []
            
            print(f"DEBUG crop_to_faces: face_data now has {len(face_data['faces'])} faces", file=sys.stderr)

            try:
                # Load moviepy modules only when needed
                print(f"DEBUG crop_to_faces: Loading moviepy", file=sys.stderr)
                VideoFileClip, AudioFileClip, CompositeVideoClip, concatenate_videoclips, concatenate_audioclips, vfx = _load_moviepy()
                print(f"DEBUG crop_to_faces: MoviePy loaded successfully", file=sys.stderr)

                # Load video
                print(f"DEBUG crop_to_faces: Loading video from {video_path}", file=sys.stderr)
                video = VideoFileClip(video_path)
                print(f"DEBUG crop_to_faces: Video loaded, duration={video.duration}s", file=sys.stderr)
            except ImportError as e:
                print(f"MoviePy import failed, falling back to ffmpeg: {e}", file=sys.stderr)
                return self._crop_with_ffmpeg(video_path, face_data, output_path, padding, smooth_transitions, focus_main_speaker)
            
            # Sort face data by timestamp
            faces_by_time = {}
            for face in face_data['faces']:
                timestamp = face['timestamp']
                if timestamp not in faces_by_time:
                    faces_by_time[timestamp] = []
                faces_by_time[timestamp].append(face)
            
            print(f"DEBUG crop_to_faces: Organized {len(faces_by_time)} timestamp groups", file=sys.stderr)
            
            # Find main speaker if requested
            main_speaker_track = None
            if focus_main_speaker and 'tracks' in face_data:
                main_speaker_track = self._find_main_speaker(face_data['tracks'])
                print(f"DEBUG crop_to_faces: Found main speaker track: {main_speaker_track is not None}", file=sys.stderr)
            
            # Process each frame/segment
            clips = []
            current_time = 0
            duration = video.duration
            print(f"DEBUG crop_to_faces: Starting segment processing, duration={duration}s", file=sys.stderr)
            
            while current_time < duration:
                # Find faces for current time
                current_faces = []
                for timestamp, faces in faces_by_time.items():
                    if abs(timestamp - current_time) < 0.5:  # Within 0.5 seconds
                        current_faces.extend(faces)
                
                if current_faces:
                    # Determine crop area
                    if main_speaker_track and main_speaker_track:
                        # Focus on main speaker
                        crop_area = self._calculate_crop_area_for_speaker(current_faces, main_speaker_track, padding)
                    else:
                        # Use all faces
                        crop_area = self._calculate_crop_area(current_faces, padding)
                    
                    # Create cropped clip for this segment
                    segment_duration = min(1.0, duration - current_time)
                    print(f"DEBUG: Slicing clip from {current_time} to {current_time + segment_duration}", file=sys.stderr)
                    segment = self._slice_clip(video, current_time, current_time + segment_duration)
                    print(f"DEBUG: Successfully sliced clip", file=sys.stderr)
                    
                    # Apply crop
                    if crop_area:
                        segment = segment.crop(
                            x=crop_area['x'],
                            y=crop_area['y'],
                            width=crop_area['width'],
                            height=crop_area['height']
                        )
                    
                    clips.append(segment)
                else:
                    # No faces detected, use original video
                    segment_duration = min(1.0, duration - current_time)
                    clips.append(self._slice_clip(video, current_time, current_time + segment_duration))
                
                current_time += 1.0
            
            # Combine clips
            print(f"DEBUG crop_to_faces: Total clips to combine: {len(clips)}", file=sys.stderr)
            if clips:
                print(f"DEBUG crop_to_faces: Combining {len(clips)} clips", file=sys.stderr)
                if smooth_transitions:
                    # Add smooth transitions between clips
                    print(f"DEBUG crop_to_faces: Adding smooth transitions", file=sys.stderr)
                    final_video = self._add_transitions(clips)
                else:
                    print(f"DEBUG crop_to_faces: No transitions, concatenating directly", file=sys.stderr)
                    final_video = concatenate_videoclips(clips)
                
                print(f"DEBUG crop_to_faces: Writing output to {output_path}", file=sys.stderr)
                # Write output
                final_video.write_videofile(
                    output_path,
                    codec='libx264',
                    audio_codec='aac',
                    fps=video.fps,
                    preset='medium',
                    verbose=False,
                    logger=None
                )
                
                print(f"DEBUG crop_to_faces: Output written successfully", file=sys.stderr)
                # Cleanup
                for clip in clips:
                    clip.close()
                final_video.close()
                video.close()
                
                print(f"DEBUG crop_to_faces: Returning True", file=sys.stderr)
                return True
            else:
                print(f"DEBUG crop_to_faces: No clips to combine, returning False", file=sys.stderr)
                return False
                
        except Exception as e:
            print(f"Error cropping video: {type(e).__name__}: {e}", file=sys.stderr)
            import traceback
            print(f"Traceback: {traceback.format_exc()}", file=sys.stderr)
            return False
    
    def _calculate_crop_area(self, faces, padding):
        """Calculate crop area to include all faces with padding"""
        if not faces:
            return None
        
        # Find bounding box that includes all faces
        min_x = min(face['x'] for face in faces)
        min_y = min(face['y'] for face in faces)
        max_x = max(face['x'] + face['width'] for face in faces)
        max_y = max(face['y'] + face['height'] for face in faces)
        
        # Add padding
        width = max_x - min_x
        height = max_y - min_y
        padding_x = width * padding
        padding_y = height * padding
        
        crop_x = max(0, min_x - padding_x)
        crop_y = max(0, min_y - padding_y)
        crop_width = min(1.0 - crop_x, width + (2 * padding_x))
        crop_height = min(1.0 - crop_y, height + (2 * padding_y))
        
        # Ensure aspect ratio (16:9 for most videos)
        target_ratio = 16 / 9
        current_ratio = crop_width / crop_height
        
        if current_ratio > target_ratio:
            # Too wide, reduce width
            new_width = crop_height * target_ratio
            crop_x += (crop_width - new_width) / 2
            crop_width = new_width
        else:
            # Too tall, reduce height
            new_height = crop_width / target_ratio
            crop_y += (crop_height - new_height) / 2
            crop_height = new_height
        
        return {
            'x': crop_x,
            'y': crop_y,
            'width': crop_width,
            'height': crop_height
        }
    
    def _calculate_crop_area_for_speaker(self, faces, speaker_track, padding):
        """Calculate crop area focusing on main speaker"""
        # Find the face that belongs to the main speaker
        speaker_face = None
        for face in faces:
            # Simple matching based on position and size
            if 'last_x' in speaker_track:
                distance = abs(face['x'] - speaker_track['last_x']) + abs(face['y'] - speaker_track['last_y'])
                if distance < 0.1:  # Threshold for matching
                    speaker_face = face
                    break
        
        if speaker_face:
            # Crop around speaker face with more padding
            return self._calculate_crop_area([speaker_face], padding * 1.5)
        else:
            return self._calculate_crop_area(faces, padding)
    
    def _find_main_speaker(self, tracks):
        """Find the main speaker track based on face tracking data"""
        if not tracks:
            return None
        
        # Score tracks based on screen time, position, and consistency
        best_track = None
        best_score = 0
        
        for track_id, track in tracks.items():
            score = 0
            
            # Screen time score
            screen_time = len(track['detections'])
            score += screen_time * 10
            
            # Position score (prefer center)
            if 'detections' in track and track['detections']:
                avg_x = sum(d['x'] + d['width']/2 for d in track['detections']) / len(track['detections'])
                center_distance = abs(avg_x - 0.5)
                score += (1 - center_distance) * 50
            
            # Size score (prefer larger faces)
            if 'detections' in track and track['detections']:
                avg_size = sum(d['width'] * d['height'] for d in track['detections']) / len(track['detections'])
                score += avg_size * 100
            
            if score > best_score:
                best_score = score
                best_track = track
        
        return best_track
    
    def _crop_with_ffmpeg(self, video_path, face_data, output_path, padding, smooth_transitions, focus_main_speaker):
        """Fallback face crop using ffmpeg when moviepy is unavailable"""
        try:
            if not face_data or 'faces' not in face_data or not face_data['faces']:
                print('No face data available for ffmpeg fallback crop', file=sys.stderr)
                return False

            crop_area = None
            if focus_main_speaker and 'tracks' in face_data:
                main_speaker_track = self._find_main_speaker(face_data['tracks'])
                if main_speaker_track:
                    crop_area = self._calculate_crop_area_for_speaker(face_data['faces'], main_speaker_track, padding)

            if crop_area is None:
                crop_area = self._calculate_crop_area(face_data['faces'], padding)

            if not crop_area:
                print('Could not determine crop area for ffmpeg fallback', file=sys.stderr)
                return False

            video_capture = cv2.VideoCapture(video_path)
            if not video_capture.isOpened():
                print('Failed to open video for ffmpeg fallback', file=sys.stderr)
                return False

            width = int(video_capture.get(cv2.CAP_PROP_FRAME_WIDTH))
            height = int(video_capture.get(cv2.CAP_PROP_FRAME_HEIGHT))
            video_capture.release()

            x = int(max(0, crop_area['x'] * width))
            y = int(max(0, crop_area['y'] * height))
            w = int(min(width - x, crop_area['width'] * width))
            h = int(min(height - y, crop_area['height'] * height))

            if w <= 0 or h <= 0:
                print('Invalid crop dimensions for ffmpeg fallback', file=sys.stderr)
                return False

            (
                ffmpeg
                .input(video_path)
                .crop(x, y, w, h)
                .output(output_path, vcodec='libx264', acodec='aac', preset='medium')
                .overwrite_output()
                .run(quiet=True)
            )
            return True
        except ffmpeg.Error as e:
            stderr_msg = e.stderr.decode(errors='replace') if hasattr(e, 'stderr') else str(e)
            print(f"FFmpeg crop failed: {stderr_msg}", file=sys.stderr)
            return False
        except Exception as e:
            print(f"Error cropping with ffmpeg fallback: {e}", file=sys.stderr)
            return False

    def _add_transitions(self, clips):
        """Add smooth transitions between clips"""
        _, _, CompositeVideoClip, concatenate_videoclips, _, vfx = _load_moviepy()

        # Simple crossfade transitions
        transition_clips = []
        
        for i, clip in enumerate(clips):
            if i > 0:
                # Add crossfade with previous clip
                transition_duration = 0.5  # 0.5 second transition
                prev_clip = clips[i-1]
                
                # Create crossfade
                crossfade = prev_clip.crossfadeout(transition_duration).set_start(transition_duration)
                current_fadein = clip.crossfadein(transition_duration)
                
                # Composite the clips
                composite = CompositeVideoClip([crossfade, current_fadein])
                transition_clips.append(composite)
            else:
                transition_clips.append(clip)
        
        return concatenate_videoclips(transition_clips)
    
    def auto_edit(self, video_path, audio_path, output_path, target_length=None, pace='medium', style='dynamic'):
        """Automatically edit video based on content analysis"""
        try:
            VideoFileClip, AudioFileClip, CompositeVideoClip, concatenate_videoclips, _, vfx = _load_moviepy()
            video = VideoFileClip(video_path)
            audio = AudioFileClip(audio_path)
            
            # Analyze audio for speech segments
            speech_segments = self._detect_speech_segments(audio)
            
            # Create edited video based on pace and style
            if pace == 'fast':
                # Faster cuts, more dynamic
                segment_duration = 2.0
            elif pace == 'slow':
                # Slower cuts, more contemplative
                segment_duration = 8.0
            else:  # medium
                segment_duration = 4.0
            
            # Create clips based on speech segments
            clips = []
            for segment in speech_segments:
                start_time = max(0, segment['start'] - 1.0)  # Start 1 second before speech
                end_time = min(video.duration, segment['end'] + 1.0)  # End 1 second after speech
                
                if end_time > start_time:
                    clip = self._slice_clip(video, start_time, end_time)
                    
                    # Apply style-specific effects
                    if style == 'dynamic':
                        clip = clip.fx(vfx.speedx, 1.1)  # Slightly speed up
                    elif style == 'cinematic':
                        clip = clip.fx(vfx.colorx, 1.2)  # Increase color saturation
                    
                    clips.append(clip)
            
            if clips:
                # Combine clips
                final_video = concatenate_videoclips(clips, method="compose")
                
                # Apply target length if specified
                if target_length and final_video.duration > target_length:
                    final_video = final_video.fx(vfx.speedx, target_length / final_video.duration)
                
                # Combine with audio
                final_video = final_video.set_audio(audio)
                
                # Write output
                final_video.write_videofile(
                    output_path,
                    codec='libx264',
                    audio_codec='aac',
                    fps=video.fps,
                    preset='medium'
                )
                
                # Cleanup
                final_video.close()
                video.close()
                audio.close()
                
                return True
            else:
                return False
                
        except Exception as e:
            print(f"Error in auto edit: {e}", file=sys.stderr)
            return False
    
    def _detect_speech_segments(self, audio, threshold=0.02):
        """Detect speech segments in audio"""
        try:
            # Simple energy-based speech detection
            # In a real implementation, you'd use a more sophisticated method
            duration = audio.duration
            segment_duration = 1.0  # 1 second segments
            segments = []
            
            for i in range(int(duration / segment_duration)):
                start_time = i * segment_duration
                end_time = min((i + 1) * segment_duration, duration)
                
                # Get audio segment
                segment = self._slice_clip(audio, start_time, end_time)
                
                # Calculate RMS energy
                audio_array = segment.to_soundarray()
                if len(audio_array.shape) > 1:
                    audio_array = audio_array.mean(axis=1)
                
                rms = np.sqrt(np.mean(audio_array ** 2))
                
                # If energy is above threshold, consider it speech
                if rms > threshold:
                    segments.append({
                        'start': start_time,
                        'end': end_time,
                        'energy': rms
                    })
                
                segment.close()
            
            return segments
            
        except Exception as e:
            print(f"Error detecting speech segments: {e}", file=sys.stderr)
            return []
    
    def combine_with_audio(self, video_path, audio_path, output_path, video_volume=0.2, audio_volume=1.0, fade_duration=0.5):
        """Combine video with new audio track"""
        try:
            VideoFileClip, AudioFileClip, CompositeVideoClip, concatenate_videoclips, concatenate_audioclips, vfx = _load_moviepy()
            video = VideoFileClip(video_path)
            audio = AudioFileClip(audio_path)
            
            # Adjust volumes
            video = video.volumex(video_volume)
            audio = audio.volumex(audio_volume)
            
            # Match durations
            if audio.duration > video.duration:
                audio = self._slice_clip(audio, 0, video.duration)
            elif video.duration > audio.duration:
                # Loop audio if it's shorter
                loops_needed = int(video.duration / audio.duration) + 1
                audio_parts = [audio] * loops_needed
                audio = self._slice_clip(concatenate_audioclips(audio_parts), 0, video.duration)
            
            # Add fade in/out
            audio = audio.audio_fadein(fade_duration).audio_fadeout(fade_duration)
            
            # Combine
            final_video = video.set_audio(audio)
            
            # Write output
            final_video.write_videofile(
                output_path,
                codec='libx264',
                audio_codec='aac',
                fps=video.fps,
                preset='medium'
            )
            
            # Cleanup
            final_video.close()
            video.close()
            audio.close()
            
            return True
            
        except Exception as e:
            print(f"Error combining audio: {e}", file=sys.stderr)
            return False

def main():
    print(f"DEBUG main: Script started with args: {sys.argv}", file=sys.stderr)
    
    parser = argparse.ArgumentParser(description="Video editing and processing")
    parser.add_argument(
        "command",
        choices=['face-crop', 'face_crop', 'auto-edit', 'auto_edit', 'combine-audio', 'combine_audio'],
        help="Command to run"
    )
    parser.add_argument("video_path", help="Path to the video file")
    parser.add_argument("--output", help="Output file path")
    parser.add_argument("--audio-path", help="Audio file path (for combine-audio)")
    parser.add_argument("--face-data", help="Face detection JSON data for face crop")
    parser.add_argument("--padding", type=float, default=0.1, help="Padding for face crop")
    parser.add_argument("--smooth-transitions", action='store_true', help="Add smooth transitions")
    parser.add_argument("--focus-main-speaker", action='store_true', help="Focus on main speaker")
    parser.add_argument("--target-length", type=float, help="Target video length in seconds")
    parser.add_argument("--pace", choices=['slow', 'medium', 'fast'], default='medium', help="Editing pace")
    parser.add_argument("--style", choices=['dynamic', 'cinematic', 'standard'], default='dynamic', help="Editing style")
    parser.add_argument("--video-volume", type=float, default=0.2, help="Video volume level")
    parser.add_argument("--audio-volume", type=float, default=1.0, help="Audio volume level")
    parser.add_argument("--fade-duration", type=float, default=0.5, help="Fade duration")
    
    args = parser.parse_args()
    args.command = args.command.replace('_', '-')
    
    print(f"DEBUG main: Command={args.command}, video_path={args.video_path}, output={args.output}", file=sys.stderr)
    
    # Check if video file exists
    if not Path(args.video_path).exists():
        print(f"Video file not found: {args.video_path}", file=sys.stderr)
        sys.exit(1)
    
    editor = VideoEditor()
    
    if args.command == 'face-crop':
        print(f"DEBUG main: Executing face-crop command", file=sys.stderr)
        if args.face_data:
            print(f"DEBUG main: face_data provided via argument", file=sys.stderr)
            face_data = json.loads(args.face_data)
        else:
            print(f"DEBUG main: Reading face_data from stdin", file=sys.stderr)
            face_data = json.loads(sys.stdin.read())
        
        print(f"DEBUG main: Parsed face_data: {type(face_data)}, keys={list(face_data.keys()) if isinstance(face_data, dict) else 'N/A'}", file=sys.stderr)
        
        # Handle case where face_data is None (e.g., from "null" JSON)
        if face_data is None:
            print(f"DEBUG main: face_data was None, converting to empty dict", file=sys.stderr)
            face_data = {}
        
        settings = {
            'padding': args.padding,
            'smooth_transitions': args.smooth_transitions,
            'focus_main_speaker': args.focus_main_speaker
        }
        
        print(f"DEBUG main: Calling crop_to_faces with settings={settings}", file=sys.stderr)
        success = editor.crop_to_faces(args.video_path, face_data, args.output, **settings)
        print(f"DEBUG main: crop_to_faces returned success={success}", file=sys.stderr)
    elif args.command == 'auto-edit':
        print(f"DEBUG main: Executing auto-edit command", file=sys.stderr)
        if not args.audio_path or not Path(args.audio_path).exists():
            print("Audio file not found", file=sys.stderr)
            sys.exit(1)
        
        settings = {
            'target_length': args.target_length,
            'pace': args.pace,
            'style': args.style
        }
        
        success = editor.auto_edit(args.video_path, args.audio_path, args.output, **settings)
        print(f"DEBUG main: auto_edit returned success={success}", file=sys.stderr)
    elif args.command == 'combine-audio':
        print(f"DEBUG main: Executing combine-audio command", file=sys.stderr)
        if not args.audio_path or not Path(args.audio_path).exists():
            print("Audio file not found", file=sys.stderr)
            sys.exit(1)
        
        settings = {
            'video_volume': args.video_volume,
            'audio_volume': args.audio_volume,
            'fade_duration': args.fade_duration
        }
        
        success = editor.combine_with_audio(args.video_path, args.audio_path, args.output, **settings)
        print(f"DEBUG main: combine_with_audio returned success={success}", file=sys.stderr)
    
    print(f"DEBUG main: Final success={success}, about to output JSON", file=sys.stderr)
    if success:
        output_json = json.dumps({"success": True, "output": args.output})
        print(f"DEBUG main: Outputting successful JSON: {output_json}", file=sys.stderr)
        print(output_json)
    else:
        error_json = json.dumps({"success": False})
        print(f"DEBUG main: Outputting error JSON to stderr: {error_json}", file=sys.stderr)
        print(error_json, file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
