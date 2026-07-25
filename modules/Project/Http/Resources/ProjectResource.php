<?php

namespace Modules\Project\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'template_type' => $this->template_type,
            'aspect_ratio' => $this->aspect_ratio,
            'video_path' => $this->video_path ? asset('storage/' . $this->video_path) : null,
            'output_path' => $this->output_path ? asset('storage/' . $this->output_path) : null,
            'output_videos' => $this->outputVideosPayload(),
            'r2_url' => $this->r2_url,
            'r2_key' => $this->r2_key,
            'thumbnail_path' => $this->thumbnail_path ? asset('storage/' . $this->thumbnail_path) : null,
            'status' => $this->status,
            'status_color' => $this->status_color,
            'progress' => $this->progress,
            'settings' => $this->settings,
            'error_message' => $this->error_message,
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'formatted_file_size' => $this->formatted_file_size,
            'duration' => $this->duration,
            'formatted_duration' => $this->formatted_duration,
            'is_public' => $this->is_public,
            'published_at' => $this->published_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Additional computed properties
            'is_ready_for_processing' => $this->isReadyForProcessing(),
            'is_completed' => $this->isCompleted(),
            
            // Relationships
            'user' => $this->when($this->relationLoaded('user'), function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            
            'activities' => $this->when($this->relationLoaded('activities'), function () {
                return ProjectActivityResource::collection($this->activities);
            }),
        ];
    }

    /**
     * All rendered videos for multi-output templates (e.g. YT + Gameplay
     * Short). Empty for single-output templates — output_path stays the
     * canonical single video.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function outputVideosPayload(): array
    {
        $videos = data_get($this->processing_state, 'output_videos');
        if (!is_array($videos)) {
            return [];
        }

        $payload = [];
        foreach ($videos as $video) {
            if (!is_array($video) || empty($video['path'])) {
                continue;
            }
            $payload[] = [
                'url' => asset('storage/' . $video['path']),
                'thumbnail' => !empty($video['thumbnail']) ? asset('storage/' . $video['thumbnail']) : null,
                'duration' => $video['duration'] ?? null,
                'score' => $video['score'] ?? null,
                'subtitle' => $video['subtitle'] ?? null,
            ];
        }

        return $payload;
    }
}
