<?php
// app/Http/Controllers/RestApiCredentialController.php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\RestApiCredential;
use App\Models\RestApiTemplate;
use App\Models\RestApiDeviceTemplate;
use App\Models\RestApiDeviceMapping;
use App\Models\RestApiConnection; // ADDED
use App\Services\RestApi\Auth\AuthManager; // ADDED
use App\Services\RestApi\RestApiClient; // Note: This class is likely obsolete, but kept the use statement
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str; // ADDED

class RestApiCredentialController extends Controller
{
    public function index()
    {
        $credentials = RestApiCredential::with('authenticationType', 'params')->get();
        $authTypes = RestApiAuthenticationType::all();
        return view('settings.rest-api.credentials.index', compact('credentials', 'authTypes'));
    }

    public function create()
    {
        $credential = new RestApiCredential();
        $authTypes = RestApiAuthenticationType::all();
        return view('settings.rest-api.credentials.create', compact('credential', 'authTypes'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:rest_api_credentials,name|max:255',
            'authentication_type_id' => 'required|exists:rest_api_authentication_types,id',
            'params' => 'required|array',
        ]);

        $credential = RestApiCredential::create($validated);

        foreach ($validated['params'] as $key => $value) {
            if ($value !== null) {
                $credential->params()->create(['key' => $key, 'value' => $value]);
            }
        }

        return redirect()->route('settings.rest-api.credentials.index')->with('success', 'Credential created successfully.');
    }

    public function edit(RestApiCredential $credential)
    {
        $authTypes = RestApiAuthenticationType::all();
        $credential->load('params');
        return view('settings.rest-api.credentials.edit', compact('credential', 'authTypes'));
    }

    public function update(Request $request, RestApiCredential $credential)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:rest_api_credentials,name,' . $credential->id,
            'authentication_type_id' => 'required|exists:rest_api_authentication_types,id',
            'params' => 'required|array',
        ]);

        $credential->update($validated);
        $credential->params()->delete(); // Easiest way to handle updates is to delete and re-create

        foreach ($validated['params'] as $key => $value) {
            if ($value !== null) {
                $credential->params()->create(['key' => $key, 'value' => $value]);
            }
        }

        return redirect()->route('settings.rest-api.credentials.index')->with('success', 'Credential updated successfully.');
    }

    public function destroy(RestApiCredential $credential)
    {
        $credential->delete();
        return redirect()->route('settings.rest-api.credentials.index')->with('success', 'Credential deleted successfully.');
    }

    public function getAuthTypeParams(Request $request, $typeId)
    {
        $type = RestApiAuthenticationType::findOrFail($typeId);

        if ($request->has('credential_id')) {
            $credential = RestApiCredential::with('params')->findOrFail($request->credential_id);
        } else {
            $credential = new RestApiCredential();
        }

        $viewName = 'settings.rest-api.credentials.partials.' . str_replace(' ', '-', strtolower($type->name));

        if (view()->exists($viewName)) {
            return view($viewName, compact('credential'));
        }

        return response()->json(['error' => 'No parameters form found for this authentication type.'], 404);
    }

    /**
 * Set the REST API field mapping (vendor or custom) for a specific device.
 * * POST /rest-api/credentials/{device}/set-mapping
		 */
		public function setMapping(Request $request, Device $device): RedirectResponse
		{
		    // 1. Validate the incoming request data
		    $validated = $request->validate([
		        'mapping_type' => 'required|in:vendor,custom', // e.g., 'vendor' or 'custom'
		        'mapping_name' => 'required|string', // e.g., 'purestorage' or 'my-custom-map'
		    ]);

		    // 2. Use the RestApiDeviceMapping model to update or create the record
		    \App\Models\RestApiDeviceMapping::updateOrCreate(
		        // Search condition: Link this mapping choice to the specific device
		        ['device_id' => $device->device_id],
		        // Values to update/create: The user's chosen mapping details
		        [
		            'mapping_type' => $validated['mapping_type'],
		            'mapping_name' => $validated['mapping_name'],
		        ]
		    );

		    // 3. Redirect back to the edit page with a success message
		    return redirect()->route('rest-api.credentials.edit', $device)
		        ->with('success', 'Field mapping updated successfully.');
		}

    public function test(Device $device): JsonResponse
    {
        $credential = RestApiCredential::where('device_id', $device->device_id)->first();
        $deviceTemplate = RestApiDeviceTemplate::where('device_id', $device->device_id)->first();

        if (!$credential || !$deviceTemplate) {
            return response()->json(['success' => false, 'error' => 'No REST API configuration found']);
        }

        $template = $deviceTemplate->template;
        $templateData = $template->template_data;
        $connData = $templateData['connections'][0] ?? null;

        if (!$connData || empty($connData['base_url'])) {
            return response()->json(['success' => false, 'error' => 'No connection configured or Base URL is empty']);
        }

        $baseUri = str_replace('{device_hostname}', $device->hostname, $connData['base_url']);
        $endpoint = $template->endpoints()->first();

        if (!$endpoint) {
            return response()->json(['success' => false, 'error' => 'No endpoints configured']);
        }

        // 1. Create mock connection model
        $connection = new RestApiConnection([
            'base_url' => $baseUri,
            'disable_ssl_verify' => $connData['disable_ssl_verify'] ?? false,
        ]);

        // 2. Get client via AuthManager
        $authManager = new AuthManager();
        $client = $authManager->getRequest($connection, $credential, $endpoint->method ?? 'GET');

        // 3. Make request
        $url = rtrim($baseUri, '/') . '/' . ltrim($endpoint->path, '/');
        $method = $endpoint->method ?? 'GET';

        try {
            $response = $client->{$method}($url);

            if (!$response->successful()) {
                 $errorBody = json_decode($response->body(), true) ?? $response->body();
                 $errorMsg = is_array($errorBody) ? ($errorBody['error'] ?? $response->reason()) : $errorBody;

                 return response()->json([
                    'success' => false,
                    'error' => "HTTP {$response->status()} Error: {$errorMsg}"
                 ]);
            }

            // Success response
            return response()->json([
                'success' => true,
                'status_code' => $response->status(),
                'response_preview' => Str::limit($response->body(), 500, '...'),
                'message' => 'Connection test successful'
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Connection failed: ' . $e->getMessage()
            ]);
        }
    }
}