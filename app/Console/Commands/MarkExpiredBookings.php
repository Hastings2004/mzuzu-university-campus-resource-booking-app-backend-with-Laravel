<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BookingService; // Import your BookingService

class MarkExpiredBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:mark-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marks bookings as expired if their end time has passed.';

    /**
     * The BookingService instance.
     *
     * @var BookingService
     */
    protected $bookingService;

    /**
     * Create a new command instance.
     *
     * @param BookingService $bookingService
     * @return void
     */
    public function __construct(BookingService $bookingService)
    {
        parent::__construct();
        $this->bookingService = $bookingService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $count = $this->bookingService->markExpiredBookings();
        $this->info("Successfully marked {$count} bookings as expired.");
        return 0; // 0 indicates success
    }
}