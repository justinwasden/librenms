<?php
/**
 * REST API Routes Configuration
 * 
 * Add these routes to routes/web.php
 * Location: Add within your authenticated route group
 */

// ============================================
// REST API Endpoint Management Routes
// ============================================

Route::middleware(['auth', 'verified'])->group(function () {
    
    // Endpoint CRUD operations
    Route::resource('rest-api/endpoints', 'RestApiEndpointController', [
        'names' => [
            'index' => 'rest-api.endpoints.index',
            'create' => 'rest-api.endpoints.create',
            'store' => 'rest-api.endpoints.store',
            'show' => 'rest-api.endpoints.show',
            'edit' => 'rest-api.endpoints.edit',
            'update' => 'rest-api.endpoints.update',
            'destroy' => 'rest-api.endpoints.destroy',
        ]
    ]);

    // Mapping-specific routes
    Route::post('rest-api/endpoints/{endpoint}/mappings', 'RestApiEndpointController@storeMappings')
        ->name('rest-api.mappings.store');
    
    Route::get('rest-api/endpoints/{endpoint}/mapping', 'RestApiEndpointController@showMapping')
        ->name('rest-api.mappings.show');

    // Test/preview endpoint
    Route::post('rest-api/endpoints/{endpoint}/test', 'RestApiEndpointController@testEndpoint')
        ->name('rest-api.endpoints.test');
});

// ============================================
// REST API Mapping API Routes (JSON responses)
// ============================================

Route::middleware(['auth', 'verified', 'json.response'])->group(function () {
    
    /**
     * Get compatible database fields for a table and data type
     * Used by field-mapper.blade.php
     * 
     * GET /api/rest-api/compatible-fields
     * Query params:
     *   - table: 'ports', 'storage', 'sensors', 'devices', 'entPhysical'
     *   - type: 'string', 'integer', 'float', 'boolean', 'array'
     *   - device_id: (optional) for device-specific suggestions
     */
    Route::get('api/rest-api/compatible-fields', 'RestApiEndpointController@getCompatibleFields')
        ->name('api.rest-api.compatible-fields');

    /**
     * Check if a mapping is compatible
     * Used by field-mapper.blade.php real-time validation
     * 
     * GET /api/rest-api/check-compatibility
     * Query params:
     *   - api_field: API field name (e.g., 'provisioned')
     *   - table: Target table (e.g., 'storage')
     *   - field: Target field (e.g., 'storage_size')
     *   - endpoint_id: Endpoint ID for vendor mapper selection
     *   - api_type: (optional) API data type
     */
    Route::get('api/rest-api/check-compatibility', 'RestApiEndpointController@checkCompatibility')
        ->name('api.rest-api.check-compatibility');

    /**
     * Get recommended mappings for an endpoint
     * Used by recommended-mappings.blade.php
     * 
     * GET /api/rest-api/recommendations
     * Query params:
     *   - endpoint_id: Endpoint ID
     */
    Route::get('api/rest-api/recommendations', 'RestApiEndpointController@getRecommendations')
        ->name('api.rest-api.recommendations');

    /**
     * Fetch API response preview
     * Used to populate preview-api-response.blade.php
     * 
     * GET /api/rest-api/endpoint-preview/{endpoint}
     */
    Route::get('api/rest-api/endpoint-preview/{endpoint}', 'RestApiEndpointController@getApiPreview')
        ->name('api.rest-api.endpoint-preview');
});

// ============================================
// REST API Connection Management Routes
// ============================================

Route::middleware(['auth', 'verified'])->group(function () {
    
    Route::resource('rest-api/connections', 'RestApiConnectionController', [
        'names' => [
            'index' => 'rest-api.connections.index',
            'create' => 'rest-api.connections.create',
            'store' => 'rest-api.connections.store',
            'show' => 'rest-api.connections.show',
            'edit' => 'rest-api.connections.edit',
            'update' => 'rest-api.connections.update',
            'destroy' => 'rest-api.connections.destroy',
        ]
    ]);

    // Test connection
    Route::post('rest-api/connections/{connection}/test', 'RestApiConnectionController@testConnection')
        ->name('rest-api.connections.test');
});

// ============================================
// REST API Credential Management Routes
// ============================================

Route::middleware(['auth', 'verified'])->group(function () {
    
    Route::resource('rest-api/credentials', 'RestApiCredentialController', [
        'names' => [
            'index' => 'rest-api.credentials.index',
            'create' => 'rest-api.credentials.create',
            'store' => 'rest-api.credentials.store',
            'show' => 'rest-api.credentials.show',
            'edit' => 'rest-api.credentials.edit',
            'update' => 'rest-api.credentials.update',
            'destroy' => 'rest-api.credentials.destroy',
        ]
    ]);
});

// ============================================
// REST API Mapping Field Reference Routes
// ============================================

Route::middleware(['auth', 'verified'])->group(function () {
    
    /**
     * Get all available database tables and their fields
     * For reference/documentation purposes
     * 
     * GET /rest-api/field-reference
     */
    Route::get('rest-api/field-reference', 'RestApiFieldReferenceController@index')
        ->name('rest-api.field-reference.index');

    /**
     * Get detailed info about a specific table and its fields
     * 
     * GET /rest-api/field-reference/{table}
     */
    Route::get('rest-api/field-reference/{table}', 'RestApiFieldReferenceController@show')
        ->name('rest-api.field-reference.show');
});

// ============================================
// REST API Vendor Mapper Documentation Routes
// ============================================

Route::middleware(['auth', 'verified'])->group(function () {
    
    /**
     * List all available vendor mappers
     * For documentation/reference
     * 
     * GET /rest-api/vendor-mappers
     */
    Route::get('rest-api/vendor-mappers', 'RestApiVendorMapperController@index')
        ->name('rest-api.vendor-mappers.index');

    /**
     * Get details about a specific vendor mapper
     * 
     * GET /rest-api/vendor-mappers/{vendor}
     */
    Route::get('rest-api/vendor-mappers/{vendor}', 'RestApiVendorMapperController@show')
        ->name('rest-api.vendor-mappers.show');

    /**
     * Get mapping instructions for a vendor
     * 
     * GET /rest-api/vendor-mappers/{vendor}/instructions
     */
    Route::get('rest-api/vendor-mappers/{vendor}/instructions', 'RestApiVendorMapperController@getInstructions')
        ->name('rest-api.vendor-mappers.instructions');
});

?>
