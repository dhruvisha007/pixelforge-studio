<?php

use Illuminate\Support\Facades\Schedule;

// Catch up on generations whose webhooks are late or never arrive.
Schedule::command('generations:sync')->everyFifteenSeconds()->withoutOverlapping();
