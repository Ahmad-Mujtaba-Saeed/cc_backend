<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('template_type'); // simple_video, advanced_video, template_a, etc.
            $table->string('video_path')->nullable();
            $table->string('output_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('status')->default('draft'); // draft, processing, completed, failed, published
            $table->integer('progress')->default(0); // 0-100
            $table->json('settings')->nullable();
            $table->text('error_message')->nullable();
            
            // File information
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->decimal('duration', 10, 2)->nullable(); // Video duration in seconds
            
            // Visibility and timestamps
            $table->boolean('is_public')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('is_public');
            $table->index('template_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
