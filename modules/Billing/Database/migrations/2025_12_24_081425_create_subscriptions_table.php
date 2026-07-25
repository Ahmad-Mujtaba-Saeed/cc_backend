<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('name');
            $table->string('type');
            $table->string('type_id');
            $table->string('sub_id')->nullable();
            $table->string('cus_id')->nullable();
            $table->string('status');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamps();

            // Add indexes
            $table->index('user_id');
            $table->index('payment_id');
        });

        // Add foreign key constraints
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('payment_id')
                  ->references('id')
                  ->on('payments')
                  ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscriptions');
    }
};