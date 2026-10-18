<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Whether an email went out through the admin's shared mailbox (counted against the shared daily limits). */
    public function up(): void
    {
        Schema::table('email_campaign_sends', function (Blueprint $table) {
            $table->boolean('shared')->default(false)->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_sends', fn (Blueprint $table) => $table->dropColumn('shared'));
    }
};
