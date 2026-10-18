<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trackable short links (/go/{code}) with campaign tags, and clicks per day.
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->text('url');
            $table->string('title')->nullable();
            $table->string('utm_source', 60)->nullable();
            $table->string('utm_medium', 60)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('short_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_link_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('clicks')->default(0);

            $table->unique(['short_link_id', 'date']);
        });

        // Link-in-bio buttons and a WhatsApp chat button on the public page.
        Schema::table('public_profiles', function (Blueprint $table) {
            $table->json('links')->nullable()->after('newsletter_text');
            $table->string('whatsapp', 20)->nullable()->after('links');
        });
    }

    public function down(): void
    {
        Schema::table('public_profiles', fn (Blueprint $table) => $table->dropColumn(['links', 'whatsapp']));
        Schema::dropIfExists('short_link_clicks');
        Schema::dropIfExists('short_links');
    }
};
