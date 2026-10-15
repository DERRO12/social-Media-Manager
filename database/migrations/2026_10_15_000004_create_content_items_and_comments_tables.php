<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every post/video that exists on a connected account, whether or not it was published from this app.
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_target_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform_content_id');
            $table->string('type', 20)->default('video');
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->text('permalink')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments_count')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'platform_content_id']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->string('platform_comment_id');
            $table->string('parent_platform_comment_id')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_handle')->nullable();
            $table->text('author_avatar_url')->nullable();
            $table->text('body');
            $table->unsignedBigInteger('likes')->default(0);
            $table->boolean('is_own')->default(false);
            $table->timestamp('commented_at')->nullable()->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['content_item_id', 'platform_comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('content_items');
    }
};
