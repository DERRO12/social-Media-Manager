<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The client's own mailbox (e.g. Gmail + app password) that their newsletters are sent from.
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('gmail');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('username');
            $table->text('password');
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->unsignedInteger('daily_limit')->default(400);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('tags')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('subscribed');
            $table->string('token', 40)->unique();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject')->default('');
            $table->string('preheader')->nullable();
            $table->longText('body')->nullable();
            $table->string('tag')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('email_campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->unique(['email_campaign_id', 'contact_id']);
            $table->index(['email_campaign_id', 'status']);
            $table->index('sent_at');
        });

        Schema::table('public_profiles', function (Blueprint $table) {
            $table->boolean('newsletter_enabled')->default(false)->after('show_business');
            $table->string('newsletter_text', 200)->nullable()->after('newsletter_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('public_profiles', fn (Blueprint $table) => $table->dropColumn(['newsletter_enabled', 'newsletter_text']));
        Schema::dropIfExists('email_campaign_sends');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('mailboxes');
    }
};
