<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A client's Google Business Profile details, prepared in the builder before they create it on Google.
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('primary_category')->nullable();
            $table->string('secondary_categories')->nullable();
            $table->string('keywords')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('service_area')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
            $table->string('booking_url')->nullable();
            $table->text('description')->nullable();
            $table->json('hours')->nullable();
            $table->json('services')->nullable();
            $table->string('place_id')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->unsignedTinyInteger('score');
            $table->json('results');
            $table->timestamps();
        });

        // Opt-in public page on this site listing a client's content, so Google can index it.
        Schema::create('public_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('handle', 40)->unique();
            $table->string('display_name')->nullable();
            $table->string('headline', 160)->nullable();
            $table->text('bio')->nullable();
            $table->string('location')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::table('content_items', function (Blueprint $table) {
            $table->boolean('hidden_from_profile')->default(false)->after('shares');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', fn (Blueprint $table) => $table->dropColumn('hidden_from_profile'));
        Schema::dropIfExists('public_profiles');
        Schema::dropIfExists('seo_audits');
        Schema::dropIfExists('business_profiles');
    }
};
