<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('mime', 40)->nullable();
            $table->string('title', 120);
            $table->string('alt', 160)->nullable();
            $table->text('caption')->nullable();
            $table->string('keywords')->nullable();
            $table->string('location', 120)->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_public']);
        });

        Schema::table('public_profiles', function (Blueprint $table) {
            $table->string('seo_title', 70)->nullable()->after('website');
            $table->string('seo_description', 170)->nullable()->after('seo_title');
            $table->string('avatar_path')->nullable()->after('seo_description');
            $table->boolean('show_business')->default(false)->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('public_profiles', fn (Blueprint $table) => $table->dropColumn(['seo_title', 'seo_description', 'avatar_path', 'show_business']));
        Schema::dropIfExists('profile_photos');
    }
};
