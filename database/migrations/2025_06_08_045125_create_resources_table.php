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
         Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description'); 
            $table->string('location'); 
            $table->integer('capacity');
            $table->enum('category', ['classrooms', 'ict_labs', 'science_labs', 'sports','cars', 'auditorium']);
            $table->integer('is_active')->default(1); // 1 for active, 0 for inactive
            $table->string("status")->default("Available");
            $table->string('image')->nullable(); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
