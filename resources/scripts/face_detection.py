#!/usr/bin/env python3
"""
Face detection and object detection script using MediaPipe and YOLO
"""

import sys
import json
import argparse
from pathlib import Path
import cv2
import mediapipe as mp
from ultralytics import YOLO

class FaceDetector:
    def __init__(self, confidence=0.5):
        self.confidence = confidence
        self.mp_face_detection = mp.solutions.face_detection
        self.mp_drawing = mp.solutions.drawing_utils
        self.face_detection = self.mp_face_detection.FaceDetection(
            model_selection=1, min_detection_confidence=confidence
        )
    
    def detect_faces(self, video_path, max_faces=10, sample_rate=1):
        """Detect faces in video"""
        try:
            cap = cv2.VideoCapture(video_path)
            fps = cap.get(cv2.CAP_PROP_FPS)
            total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
            
            faces_data = {
                'faces': [],
                'frames_processed': 0,
                'video_duration': total_frames / fps,
                'fps': fps
            }
            
            frame_count = 0
            while cap.isOpened():
                ret, frame = cap.read()
                if not ret:
                    break
                
                # Sample frames based on sample_rate
                if frame_count % int(fps * sample_rate) == 0:
                    # Convert BGR to RGB
                    rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                    
                    # Process the frame
                    results = self.face_detection.process(rgb_frame)
                    
                    if results.detections:
                        frame_faces = []
                        for detection in results.detections:
                            if len(frame_faces) >= max_faces:
                                break
                            
                            # Get bounding box
                            bbox = detection.location_data.relative_bounding_box
                            face_info = {
                                'timestamp': frame_count / fps,
                                'frame': frame_count,
                                'x': bbox.xmin,
                                'y': bbox.ymin,
                                'width': bbox.width,
                                'height': bbox.height,
                                'confidence': detection.score[0]
                            }
                            frame_faces.append(face_info)
                        
                        if frame_faces:
                            faces_data['faces'].extend(frame_faces)
                        faces_data['frames_processed'] += 1
                
                frame_count += 1
            
            cap.release()
            return faces_data
            
        except Exception as e:
            print(f"Error detecting faces: {e}", file=sys.stderr)
            return None
    
    def track_faces(self, video_path, max_faces=5, tracking_distance=0.3):
        """Track faces throughout the video"""
        try:
            cap = cv2.VideoCapture(video_path)
            fps = cap.get(cv2.CAP_PROP_FPS)
            total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
            
            tracking_data = {
                'tracks': {},
                'video_duration': total_frames / fps,
                'fps': fps
            }
            
            next_track_id = 0
            frame_count = 0
            
            while cap.isOpened():
                ret, frame = cap.read()
                if not ret:
                    break
                
                # Process every frame for tracking
                if frame_count % 5 == 0:  # Every 5th frame
                    rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                    results = self.face_detection.process(rgb_frame)
                    
                    if results.detections:
                        current_faces = []
                        for detection in results.detections:
                            bbox = detection.location_data.relative_bounding_box
                            face_info = {
                                'timestamp': frame_count / fps,
                                'frame': frame_count,
                                'x': bbox.xmin,
                                'y': bbox.ymin,
                                'width': bbox.width,
                                'height': bbox.height,
                                'confidence': detection.score[0]
                            }
                            current_faces.append(face_info)
                        
                        # Match faces to existing tracks
                        unmatched_faces = current_faces.copy()
                        
                        for track_id, track in tracking_data['tracks'].items():
                            if not unmatched_faces:
                                break
                            
                            # Find closest face
                            best_match = None
                            best_distance = float('inf')
                            
                            for i, face in enumerate(unmatched_faces):
                                # Calculate distance between face centers
                                track_center_x = track['last_x'] + track['last_width'] / 2
                                track_center_y = track['last_y'] + track['last_height'] / 2
                                face_center_x = face['x'] + face['width'] / 2
                                face_center_y = face['y'] + face['height'] / 2
                                
                                distance = ((track_center_x - face_center_x) ** 2 + 
                                          (track_center_y - face_center_y) ** 2) ** 0.5
                                
                                if distance < best_distance and distance < tracking_distance:
                                    best_distance = distance
                                    best_match = i
                            
                            if best_match is not None:
                                # Update track
                                face = unmatched_faces.pop(best_match)
                                track['detections'].append(face)
                                track['last_x'] = face['x']
                                track['last_y'] = face['y']
                                track['last_width'] = face['width']
                                track['last_height'] = face['height']
                        
                        # Create new tracks for unmatched faces
                        for face in unmatched_faces:
                            if len(tracking_data['tracks']) >= max_faces:
                                break
                            
                            tracking_data['tracks'][next_track_id] = {
                                'detections': [face],
                                'last_x': face['x'],
                                'last_y': face['y'],
                                'last_width': face['width'],
                                'last_height': face['height']
                            }
                            next_track_id += 1
                
                frame_count += 1
            
            cap.release()
            return tracking_data
            
        except Exception as e:
            print(f"Error tracking faces: {e}", file=sys.stderr)
            return None

class ObjectDetector:
    def __init__(self, model='yolov8n', confidence=0.5):
        self.model = YOLO(model)
        self.confidence = confidence
    
    def detect_objects(self, video_path, target_classes=None, sample_rate=1):
        """Detect objects in video"""
        try:
            cap = cv2.VideoCapture(video_path)
            fps = cap.get(cv2.CAP_PROP_FPS)
            total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
            
            objects_data = {
                'objects': [],
                'frames_processed': 0,
                'video_duration': total_frames / fps,
                'fps': fps,
                'target_classes': target_classes or []
            }
            
            frame_count = 0
            while cap.isOpened():
                ret, frame = cap.read()
                if not ret:
                    break
                
                # Sample frames based on sample_rate
                if frame_count % int(fps * sample_rate) == 0:
                    # Run YOLO detection
                    results = self.model(frame, conf=self.confidence)
                    
                    frame_objects = []
                    for result in results:
                        boxes = result.boxes
                        for box in boxes:
                            class_name = self.model.names[int(box.cls)]
                            
                            # Filter by target classes if specified
                            if target_classes and class_name not in target_classes:
                                continue
                            
                            # Get bounding box coordinates
                            x1, y1, x2, y2 = box.xyxy[0]
                            confidence = box.conf[0]
                            
                            object_info = {
                                'timestamp': frame_count / fps,
                                'frame': frame_count,
                                'class': class_name,
                                'x': float(x1) / frame.shape[1],
                                'y': float(y1) / frame.shape[0],
                                'width': float(x2 - x1) / frame.shape[1],
                                'height': float(y2 - y1) / frame.shape[0],
                                'confidence': float(confidence)
                            }
                            frame_objects.append(object_info)
                    
                    if frame_objects:
                        objects_data['objects'].extend(frame_objects)
                    objects_data['frames_processed'] += 1
                
                frame_count += 1
            
            cap.release()
            return objects_data
            
        except Exception as e:
            print(f"Error detecting objects: {e}", file=sys.stderr)
            return None

def main():
    parser = argparse.ArgumentParser(description="Face and object detection")
    parser.add_argument("command", choices=['detect', 'detect-objects', 'track'], help="Command to run")
    parser.add_argument("video_path", help="Path to the video file")
    parser.add_argument("--confidence", type=float, default=0.5, help="Detection confidence")
    parser.add_argument("--max-faces", type=int, default=10, help="Maximum faces to detect")
    parser.add_argument("--sample-rate", type=float, default=1, help="Sample rate in seconds")
    parser.add_argument("--model", default="yolov8n", help="YOLO model for object detection")
    parser.add_argument("--target-classes", help="Comma-separated list of target classes")
    parser.add_argument("--tracking-distance", type=float, default=0.3, help="Distance threshold for face tracking")
    
    args = parser.parse_args()
    
    # Check if video file exists
    if not Path(args.video_path).exists():
        print(f"Video file not found: {args.video_path}", file=sys.stderr)
        sys.exit(1)
    
    # Parse target classes
    target_classes = None
    if args.target_classes:
        target_classes = args.target_classes.split(',')
    
    if args.command == 'detect':
        detector = FaceDetector(args.confidence)
        result = detector.detect_faces(args.video_path, args.max_faces, args.sample_rate)
    elif args.command == 'detect-objects':
        detector = ObjectDetector(args.model, args.confidence)
        result = detector.detect_objects(args.video_path, target_classes, args.sample_rate)
    elif args.command == 'track':
        detector = FaceDetector(args.confidence)
        result = detector.track_faces(args.video_path, args.max_faces, args.tracking_distance)
    
    if result:
        print(json.dumps(result, indent=2))
    else:
        print("Detection failed", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
