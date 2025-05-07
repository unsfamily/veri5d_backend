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
        Schema::create('user_payment_lists', function (Blueprint $table) {
            $table->id();
            $table->string('Share');
            $table->decimal('profit_share', 10, 2);
            $table->unsignedBigInteger('order_id');
            $table->foreign('order_id')->references('id')->on('order_trackings')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('id_roll');
            $table->foreign('id_roll')->references('id')->on('rolls')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict')->nullable();
            $table->string('payment_mode')->nullable();
            $table->string('payment_id')->nullable();
            $table->boolean('active')->default(1);
            $table->timestamps();
        });
    }



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_payment_lists');
    }
};
