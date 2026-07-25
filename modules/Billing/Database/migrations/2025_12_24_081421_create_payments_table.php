<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_type_id')->nullable();
            $table->text('note')->nullable();
            $table->string('payment_status')->default('pending');
            $table->integer('payment_amount');
            $table->string('payment_currency', 3)->default('USD');
            $table->string('payment_gateway');
            $table->string('payment_transaction_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['related_type', 'related_type_id']);
            $table->index('payment_status');
            $table->index('payment_transaction_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
    }
};