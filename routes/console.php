<?php

use Illuminate\Support\Facades\Schedule;

// Needs schedule:work for sub-minute frequencies. The explicit expiry matters:
// the default is 1440 minutes and the mutex outlives a killed scheduler.
Schedule::command('webhooks:process-deliveries')
    ->everyTenSeconds()
    ->withoutOverlapping(expiresAt: 2);
