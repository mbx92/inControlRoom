<?php

namespace App\Http\Controllers;

use App\Services\MaintenanceMode;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        if (! MaintenanceMode::enabled()) {
            return redirect()->route('dashboard');
        }

        if (request()->user()?->isAdmin()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Maintenance/Index', [
            'maintenance' => MaintenanceMode::publicPayload(),
        ]);
    }
}
