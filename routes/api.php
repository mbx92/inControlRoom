<?php

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('agents')->group(function () {
    Route::post('/enroll', [AgentController::class, 'enroll'])->name('api.agents.enroll');
    Route::post('/heartbeat', [AgentController::class, 'heartbeat'])->name('api.agents.heartbeat');
});
