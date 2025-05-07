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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('data');
            $table->decimal('total_price', 10, 2);
            $table->decimal('profit', 10, 2);
            $table->unsignedBigInteger('brand_id');
            $table->foreign('brand_id')->references('id')->on('product_attributes')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->foreign('category_id')->references('id')->on('product_categories')->onDelete('restrict')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('products')->onDelete('set null');
            $table->boolean('type')->default(0);
            $table->unsignedBigInteger('shop_id');
            $table->foreign('shop_id')->references('id')->on('vendors')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict')->nullable();
            $table->unsignedBigInteger('add_by');
            $table->foreign('add_by')->references('id')->on('users')->onDelete('restrict')->nullable();
            $table->boolean('active')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
