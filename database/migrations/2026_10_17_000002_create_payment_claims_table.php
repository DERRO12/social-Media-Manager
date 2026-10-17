<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payment links per plan (PayPal, Yo! Payments Uganda), set by the admin.
        Schema::table('plans', function (Blueprint $table) {
            $table->text('paypal_url')->nullable()->after('stripe_price_id');
            $table->text('yo_url')->nullable()->after('paypal_url');
        });

        // "I've paid" notices from clients, which the admin approves to activate the plan.
        Schema::create('payment_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20);
            $table->string('reference', 120);
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('admin_note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_claims');
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['paypal_url', 'yo_url']));
    }
};
