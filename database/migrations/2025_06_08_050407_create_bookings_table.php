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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference')->unique();       
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->dateTime("start_time");
            $table->dateTime("end_time");
            $table->enum("status", [
                'approved',
                'pending',
                'cancelled',
                'expired',
                'completed'
            ])->default('approved');
            $table->string("purpose");
            $table->enum('booking_type', [
                'university_activity',
                'staff_meeting',
                'student_meeting',
                'class',
                'other',
            ]); // New field for booking type
            $table->integer('priority'); // New field for priority
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();                        
            $table->index(['user_id', 'status']);
            $table->index(['expires_at']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
