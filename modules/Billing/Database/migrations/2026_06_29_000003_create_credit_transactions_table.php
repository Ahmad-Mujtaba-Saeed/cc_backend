<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('type'); // grant | debit | refund
            $table->integer('amount'); // signed: grant/refund positive, debit negative
            $table->integer('balance_after');
            $table->string('template_type')->nullable();
            $table->string('reason')->nullable();
            // Idempotency / audit key, e.g. charge:project:42 or refund:project:42
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('project_id');
            $table->index('reference');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
