<?php

// Add these routes to your routes/web.php file in the admin section

use App\Http\Controllers\Admin\MetricFieldMappingController;

// Metric Field Mapping Routes (Admin only)
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    
    // Metric Field Mappings Resource
    Route::resource('metric-field-mappings', MetricFieldMappingController::class);
    
    // Additional routes
    Route::post('metric-field-mappings/{mapping}/toggle', [MetricFieldMappingController::class, 'toggle'])
        ->name('metric-field-mappings.toggle');
    
    Route::post('metric-field-mappings/run-matching', [MetricFieldMappingController::class, 'runMatching'])
        ->name('metric-field-mappings.run-matching');
    
    Route::delete('metric-field-mappings/bulk/unmatched', [MetricFieldMappingController::class, 'bulkDeleteUnmatched'])
        ->name('metric-field-mappings.bulk-delete-unmatched');
    
    Route::get('metric-field-mappings/ajax/table-fields', [MetricFieldMappingController::class, 'getTableFields'])
        ->name('metric-field-mappings.table-fields');
});
