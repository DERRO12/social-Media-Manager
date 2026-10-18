<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A contact can have an email, a phone number, or both; each channel has its own opt-out.
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('sms_status', 20)->default('subscribed')->after('status');
            $table->unique(['user_id', 'phone']);
        });

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->string('channel', 10)->default('email')->after('user_id');
        });

        Schema::table('email_campaign_sends', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->nullable()->after('error');
        });

        // A client's own EgoSMS account (otherwise the platform's shared account is used).
        Schema::create('sms_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('username');
            $table->text('password');
            $table->string('sender_id', 11);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_accounts');
        Schema::table('email_campaign_sends', fn (Blueprint $table) => $table->dropColumn('cost'));
        Schema::table('email_campaigns', fn (Blueprint $table) => $table->dropColumn('channel'));
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'phone']);
            $table->dropColumn(['phone', 'sms_status']);
        });
    }
};
