<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('generations:sync')->everyThirtySeconds()->withoutOverlapping();
