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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            $table->string('status', 20)->default('pending');
            $table->string('payment_status', 10)->default('unpaid');
            $table->decimal('total_amount', 16, 2);
            $table->string('customer_name_snapshot');
            $table->string('customer_email_snapshot');
            $table->string('customer_phone_snapshot', 30);
            $table->text('customer_address_snapshot')->nullable();
            $table->uuid('creation_token')->unique();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at', 'id']);
            $table->index(['payment_status', 'created_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
