<?php

namespace Modules\Project\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectActivityResource extends JsonResource
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
            'project_id' => $this->project_id,
            'action' => $this->action,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
