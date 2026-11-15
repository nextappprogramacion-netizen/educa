<?php

use App\Http\Controllers\Attendance\CredentialController;
use App\Http\Controllers\Attendance\HistoryController;
use App\Http\Controllers\Attendance\IncidentController;
use App\Http\Controllers\Attendance\OverviewController;
use App\Http\Controllers\Attendance\ReportController;
use App\Http\Controllers\Attendance\ScannerController;
use App\Http\Controllers\Attendance\SettingsController;
use App\Http\Controllers\Attendance\StudentController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    // Apartado: Asistencia (Control mediante QR)
    Route::prefix('attendance')->name('attendance.')->group(function () {
        // 1. Panel general
        Route::get('overview', [OverviewController::class, 'index'])->name('overview');

        // 2. Registro de acceso
        Route::get('scanner', [ScannerController::class, 'index'])->name('scanner');
        Route::post('scanner/scan', [ScannerController::class, 'scan'])->name('scanner.scan');
        Route::post('scanner/entry', [ScannerController::class, 'registerEntry'])->name('scanner.entry');
        Route::post('scanner/exit', [ScannerController::class, 'registerExit'])->name('scanner.exit');

        // 3. Estudiantes
        Route::get('students', [StudentController::class, 'index'])->name('students');
        Route::post('students', [StudentController::class, 'store'])->name('students.store');
        Route::put('students/{student}', [StudentController::class, 'update'])->name('students.update');
        Route::patch('students/{student}/toggle-status', [StudentController::class, 'toggleStatus'])->name('students.toggle-status');

        // 4. Credenciales QR
        Route::get('credentials', [CredentialController::class, 'index'])->name('credentials');
        Route::post('credentials/{student}/generate', [CredentialController::class, 'generate'])->name('credentials.generate');
        Route::post('credentials/{student}/renew', [CredentialController::class, 'renew'])->name('credentials.renew');
        Route::post('credentials/{student}/block', [CredentialController::class, 'block'])->name('credentials.block');
        Route::post('credentials/{student}/unblock', [CredentialController::class, 'unblock'])->name('credentials.unblock');
        Route::post('credentials/generate-batch', [CredentialController::class, 'generateBatch'])->name('credentials.generate-batch');
        Route::patch('credentials/{student}/toggle-status', [CredentialController::class, 'toggleStatus'])->name('credentials.toggle-status');

        // 5. Historial de asistencia
        Route::get('history', [HistoryController::class, 'index'])->name('history');
        Route::post('history/classify-absent', [HistoryController::class, 'classifyAbsent'])->name('history.classify-absent');

        // 6. Incidencias
        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents');
        Route::post('incidents', [IncidentController::class, 'store'])->name('incidents.store');
        Route::patch('incidents/{incident}/justify', [IncidentController::class, 'justify'])->name('incidents.justify');

        // 7. Reportes
        Route::get('reports', [ReportController::class, 'index'])->name('reports');
        Route::get('reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');
        Route::get('reports/export/excel', [ReportController::class, 'exportExcel'])->name('reports.export.excel');

        // 8. Configuración
        Route::get('settings', [SettingsController::class, 'index'])->name('settings');
        Route::put('settings/schedules', [SettingsController::class, 'updateSchedules'])->name('settings.schedules.update');
        Route::post('settings/calendar-days', [SettingsController::class, 'storeCalendarDay'])->name('settings.calendar-days.store');
        Route::delete('settings/calendar-days/{calendarDay}', [SettingsController::class, 'deleteCalendarDay'])->name('settings.calendar-days.destroy');
        Route::post('settings/terminals', [SettingsController::class, 'storeTerminal'])->name('settings.terminals.store');
        Route::put('settings/terminals/{terminal}', [SettingsController::class, 'updateTerminal'])->name('settings.terminals.update');
        Route::patch('settings/terminals/{terminal}/toggle-status', [SettingsController::class, 'toggleTerminalStatus'])->name('settings.terminals.toggle-status');
        Route::delete('settings/terminals/{terminal}', [SettingsController::class, 'deleteTerminal'])->name('settings.terminals.destroy');
    });
});

require __DIR__.'/settings.php';
