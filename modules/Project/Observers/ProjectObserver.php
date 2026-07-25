<?php

namespace Modules\Project\Observers;

use Modules\Project\Models\Project;
use Modules\Project\Models\ProjectActivity;

class ProjectObserver
{
    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        ProjectActivity::create([
            'project_id' => $project->id,
            'action' => 'created',
            'description' => "Project '{$project->title}' was created",
            'metadata' => [
                'template_type' => $project->template_type,
                'user_id' => $project->user_id,
            ],
        ]);
    }

    /**
     * Handle the Project "updated" event.
     */
    public function updated(Project $project): void
    {
        $changes = $project->getDirty();
        
        if (empty($changes)) {
            return;
        }

        $description = "Project '{$project->title}' was updated";
        $metadata = ['changes' => $changes];

        // Check for specific important changes
        if (isset($changes['video_path']) && $changes['video_path']) {
            $description = "Video was uploaded to project '{$project->title}'";
        } elseif (isset($changes['status'])) {
            $description = "Project '{$project->title}' status changed to {$project->status}";
        } elseif (isset($changes['progress'])) {
            $description = "Project '{$project->title}' progress updated to {$project->progress}%";
        }

        ProjectActivity::create([
            'project_id' => $project->id,
            'action' => 'updated',
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        ProjectActivity::create([
            'project_id' => $project->id,
            'action' => 'deleted',
            'description' => "Project '{$project->title}' was deleted",
            'metadata' => [
                'title' => $project->title,
                'template_type' => $project->template_type,
            ],
        ]);
    }

    /**
     * Handle the Project "restored" event.
     */
    public function restored(Project $project): void
    {
        ProjectActivity::create([
            'project_id' => $project->id,
            'action' => 'restored',
            'description' => "Project '{$project->title}' was restored",
        ]);
    }

    /**
     * Handle the Project "force deleted" event.
     */
    public function forceDeleted(Project $project): void
    {
        ProjectActivity::create([
            'project_id' => $project->id,
            'action' => 'force_deleted',
            'description' => "Project '{$project->title}' was permanently deleted",
        ]);
    }
}
