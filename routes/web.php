<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertRuleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HeadscaleController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\InventoryAssetController;
use App\Http\Controllers\LabelPrinterController;
use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TopologyController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VaultEntryController;
use Illuminate\Support\Facades\Route;

// Neutralize rogue service worker requests from browser extensions (local dev only).
if (app()->environment('local')) {
    Route::get('/sw.js', function () {
        return response(
            'self.addEventListener("install",e=>e.waitUntil(self.skipWaiting()));self.addEventListener("activate",e=>e.waitUntil(self.registration.unregister()));',
            200,
            ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-store'],
        );
    });
}

Route::get('/proxy/proxmox-console/{token}', [IntegrationController::class, 'guestConsoleProxyPayload'])
    ->middleware('signed')
    ->name('integrations.guests.console.proxy-payload');

Route::get('/proxy/headscale-terminal/{token}', [HeadscaleController::class, 'terminalProxyPayload'])
    ->middleware('signed')
    ->name('headscale.terminal.proxy-payload');

Route::get('/proxy/headscale-vnc/{token}', [HeadscaleController::class, 'vncProxyPayload'])
    ->middleware('signed')
    ->name('headscale.vnc.proxy-payload');

Route::get('/inventory/{asset}/scan', [InventoryAssetController::class, 'scan'])
    ->middleware('signed')
    ->name('inventory.scan');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'site-scope'])->group(function () {
    Route::pattern('asset', '[0-9a-fA-F-]{36}');
    Route::pattern('integration', '[0-9a-fA-F-]{36}');
    Route::pattern('vault', '[0-9a-fA-F-]{36}');
    Route::pattern('site', '[0-9a-fA-F-]{36}');
    Route::pattern('alertRule', '[0-9]+');
    Route::pattern('notificationChannel', '[0-9]+');
    Route::pattern('event', '[0-9]+');
    Route::pattern('printer', '[0-9]+');
    Route::pattern('user', '[0-9]+');

    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::get('/alerts/{event}', [AlertController::class, 'show'])->name('alerts.show');
    Route::get('/topology', [TopologyController::class, 'index'])->name('topology.index');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

    // Read-only routes (all roles)
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [InventoryAssetController::class, 'index'])->name('index');
        Route::get('/{asset}', [InventoryAssetController::class, 'show'])->name('show');
    });

    Route::prefix('settings/integrations')->name('integrations.')->group(function () {
        Route::get('/', [IntegrationController::class, 'index'])->name('index');
        Route::get('/{integration}', [IntegrationController::class, 'show'])->name('show');
        Route::get('/{integration}/guests/{guestType}/{node}/{vmid}', [IntegrationController::class, 'guestShow'])
            ->name('guests.show');
    });

    Route::prefix('headscale')->name('headscale.')->group(function () {
        Route::get('/', [HeadscaleController::class, 'index'])->name('index');
        Route::get('/{integration}', [HeadscaleController::class, 'show'])->name('show');
        Route::get('/{integration}/terminal', [HeadscaleController::class, 'terminalPage'])->name('terminal.page');
        Route::get('/{integration}/vnc', [HeadscaleController::class, 'vncPage'])->name('vnc.page');
    });

    Route::prefix('settings/vault')->name('vault.')->group(function () {
        Route::get('/', [VaultEntryController::class, 'index'])->name('index');
        Route::get('/{vault}', [VaultEntryController::class, 'show'])->name('show');
    });

    Route::prefix('settings/print-smb')->name('print-smb.')->group(function () {
        Route::get('/', [LabelPrinterController::class, 'index'])->name('index');
    });

    // Execute routes (admin + operator)
    Route::middleware('role:admin,operator')->group(function () {
        Route::put('/alerts/{event}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');

        Route::post('/{asset}/print-label', [InventoryAssetController::class, 'printLabel'])
            ->prefix('inventory')->name('inventory.print-label');

        Route::prefix('settings/integrations')->name('integrations.')->group(function () {
            Route::post('/{integration}/test', [IntegrationController::class, 'test'])->name('test');
            Route::get('/{integration}/guests/{guestType}/{node}/{vmid}/console', [IntegrationController::class, 'guestConsole'])
                ->name('guests.console');
        });

        Route::prefix('headscale')->name('headscale.')->group(function () {
            Route::post('/{integration}/terminal', [HeadscaleController::class, 'createTerminalSession'])->name('terminal.create');
            Route::post('/{integration}/vnc', [HeadscaleController::class, 'createVncSession'])->name('vnc.create');
            Route::post('/{integration}/nodes/{nodeId}/tags', [HeadscaleController::class, 'updateNodeTags'])->name('nodes.tags');
            Route::post('/{integration}/nodes/{nodeId}/routes', [HeadscaleController::class, 'updateNodeRoutes'])->name('nodes.routes');
            Route::post('/{integration}/nodes/{nodeId}/rename', [HeadscaleController::class, 'renameNode'])->name('nodes.rename');
            Route::post('/{integration}/nodes/{nodeId}/expire', [HeadscaleController::class, 'expireNode'])->name('nodes.expire');
            Route::post('/{integration}/preauthkeys', [HeadscaleController::class, 'createPreAuthKey'])->name('preauth-keys.create');
            Route::post('/{integration}/preauthkeys/{keyId}/expire', [HeadscaleController::class, 'expirePreAuthKey'])->name('preauth-keys.expire');
        });

        Route::prefix('settings/vault')->name('vault.')->group(function () {
            Route::post('/{vault}/reveal', [VaultEntryController::class, 'reveal'])->name('reveal');
        });

        Route::prefix('settings/print-smb')->name('print-smb.')->group(function () {
            Route::post('/{printer}/test', [LabelPrinterController::class, 'test'])->name('test');
        });
    });

    // Admin-only routes
    Route::middleware('role:admin')->group(function () {
        Route::put('/topology/layout', [TopologyController::class, 'updateLayout'])->name('topology.layout.update');
        Route::delete('/topology/layout', [TopologyController::class, 'destroyLayout'])->name('topology.layout.destroy');

        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/create', [InventoryAssetController::class, 'create'])->name('create');
            Route::post('/', [InventoryAssetController::class, 'store'])->name('store');
            Route::get('/{asset}/edit', [InventoryAssetController::class, 'edit'])->name('edit');
            Route::put('/{asset}', [InventoryAssetController::class, 'update'])->name('update');
        });

        Route::prefix('settings/integrations')->name('integrations.')->group(function () {
            Route::get('/create', [IntegrationController::class, 'create'])->name('create');
            Route::post('/', [IntegrationController::class, 'store'])->name('store');
            Route::get('/{integration}/edit', [IntegrationController::class, 'edit'])->name('edit');
            Route::put('/{integration}', [IntegrationController::class, 'update'])->name('update');
            Route::delete('/{integration}', [IntegrationController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('settings/vault')->name('vault.')->group(function () {
            Route::get('/create', [VaultEntryController::class, 'create'])->name('create');
            Route::post('/', [VaultEntryController::class, 'store'])->name('store');
            Route::get('/{vault}/edit', [VaultEntryController::class, 'edit'])->name('edit');
            Route::put('/{vault}', [VaultEntryController::class, 'update'])->name('update');
        });

        Route::prefix('headscale')->name('headscale.')->group(function () {
            Route::put('/{integration}/policy', [HeadscaleController::class, 'updatePolicy'])->name('policy.update');
            Route::post('/{integration}/apikeys', [HeadscaleController::class, 'createApiKey'])->name('api-keys.create');
            Route::delete('/{integration}/apikeys/{prefix}', [HeadscaleController::class, 'deleteApiKey'])->name('api-keys.delete');
            Route::post('/{integration}/users', [HeadscaleController::class, 'createUser'])->name('users.create');
            Route::post('/{integration}/users/{userId}/rename', [HeadscaleController::class, 'renameUser'])->name('users.rename');
            Route::delete('/{integration}/users/{userId}', [HeadscaleController::class, 'deleteUser'])->name('users.delete');
        });

        Route::prefix('settings/sites')->name('sites.')->group(function () {
            Route::get('/', [SiteController::class, 'index'])->name('index');
            Route::get('/create', [SiteController::class, 'create'])->name('create');
            Route::post('/', [SiteController::class, 'store'])->name('store');
            Route::get('/{site}/edit', [SiteController::class, 'edit'])->name('edit');
            Route::put('/{site}', [SiteController::class, 'update'])->name('update');
        });

        Route::prefix('settings/runtime-services')->name('settings.runtime-services.')->group(function () {
            Route::get('/{service}', [SettingsController::class, 'runtimeServiceStatus'])->name('status');
            Route::post('/{service}/start', [SettingsController::class, 'startRuntimeService'])->name('start');
            Route::post('/{service}/stop', [SettingsController::class, 'stopRuntimeService'])->name('stop');
            Route::post('/{service}/restart', [SettingsController::class, 'restartRuntimeService'])->name('restart');
        });

        Route::prefix('settings/print-smb')->name('print-smb.')->group(function () {
            Route::get('/create', [LabelPrinterController::class, 'create'])->name('create');
            Route::post('/', [LabelPrinterController::class, 'store'])->name('store');
            Route::get('/{printer}/edit', [LabelPrinterController::class, 'edit'])->name('edit');
            Route::put('/{printer}', [LabelPrinterController::class, 'update'])->name('update');
            Route::delete('/{printer}', [LabelPrinterController::class, 'destroy'])->name('destroy');
            Route::put('/{printer}/default', [LabelPrinterController::class, 'setDefault'])->name('set-default');
        });

        Route::prefix('settings/notification-channels')->name('notification-channels.')->group(function () {
            Route::get('/', [NotificationChannelController::class, 'index'])->name('index');
            Route::get('/create', [NotificationChannelController::class, 'create'])->name('create');
            Route::post('/', [NotificationChannelController::class, 'store'])->name('store');
            Route::get('/{notificationChannel}/edit', [NotificationChannelController::class, 'edit'])->name('edit');
            Route::put('/{notificationChannel}', [NotificationChannelController::class, 'update'])->name('update');
        });

        Route::prefix('settings/alert-rules')->name('alert-rules.')->group(function () {
            Route::get('/', [AlertRuleController::class, 'index'])->name('index');
            Route::get('/create', [AlertRuleController::class, 'create'])->name('create');
            Route::post('/', [AlertRuleController::class, 'store'])->name('store');
            Route::get('/{alertRule}/edit', [AlertRuleController::class, 'edit'])->name('edit');
            Route::put('/{alertRule}', [AlertRuleController::class, 'update'])->name('update');
        });

        // User management
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::get('/create', [UserController::class, 'create'])->name('create');
            Route::post('/', [UserController::class, 'store'])->name('store');
            Route::put('/{user}/role', [UserController::class, 'updateRole'])->name('update-role');
            Route::put('/{user}/sites', [UserController::class, 'updateSites'])->name('update-sites');
            Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
        });
    });

    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
});
