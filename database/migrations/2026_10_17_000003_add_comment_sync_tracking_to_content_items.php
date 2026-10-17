<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            // The comment count when comments were last downloaded: comments are only re-downloaded
            // when the platform's count differs (or the copy is getting old), which saves API quota.
            $table->unsignedBigInteger('comments_synced_count')->nullable()->after('comments_count');
            $table->timestamp('comments_synced_at')->nullable()->after('comments_synced_count');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn(['comments_synced_count', 'comments_synced_at']);
        });
    }
};
