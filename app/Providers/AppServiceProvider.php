<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Resource;
use App\Models\Booking;
use App\Models\User;
use App\Observers\SearchCacheObserver;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Register observers to clear cache when models change
        Resource::observe(SearchCacheObserver::class);
        Booking::observe(SearchCacheObserver::class);
        User::observe(SearchCacheObserver::class);
    }

    public function register()
    {
        //
    }
}
