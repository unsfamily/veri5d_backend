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
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict')->nullable();
            $table->boolean('active')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->onDelete('set null');
            $table->timestamps();
        });
    }

    // $table->json('description')->nullable();
    // $table->string('category_icon')->nullable();
    // $table->string('hover_icon')->nullable();
    // $table->string('banner_image')->nullable();
    // $table->boolean('visible_in_menus')->default(1);
    // $table->boolean('wishlist')->default(1);


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
