<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking; // Make sure to import your Booking model
use Carbon\Carbon; // Import Carbon for date/time manipulation
use Illuminate\Support\Facades\Log; // For logging

class MarkBookingsAsCompleted extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:mark-completed'; // How you'll call it: php artisan bookings:mark-completed

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marks bookings as completed if their end time has passed.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting to mark bookings as completed...');

        $now = Carbon::now();

        // Assuming your BookingService constants are defined or you're using plain strings
        $statusApproved = 'approved'; 
        $statusInUse = 'in_use';     
        $statusCompleted = 'completed'; 

        // Find bookings that have ended and are currently 'approved' or 'in_use'
        $updatedCount = Booking::where('end_time', '<', $now)
            ->whereIn('status', [$statusApproved, $statusInUse])
            ->update(['status' => $statusCompleted]);

        if ($updatedCount > 0) {
            $this->info("Successfully marked {$updatedCount} bookings as '{$statusCompleted}'.");
            Log::info("Marked {$updatedCount} bookings as completed.");
        } else {
            $this->info('No bookings found to mark as completed.');
        }

        return Command::SUCCESS; 
    }
}