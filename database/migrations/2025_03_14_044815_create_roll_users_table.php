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
        Schema::create('roll_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_roll');
            $table->foreign('id_roll')->references('id')->on('rolls')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('add_by');
            $table->foreign('add_by')->references('id')->on('users')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('under_user')->nullable();
            $table->foreign('under_user')->references('id')->on('users')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('sub_under_user')->nullable();
            $table->foreign('sub_under_user')->references('id')->on('users')->onDelete('restrict')->nullable();
            $table->boolean('active')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roll_users');
    }
};
