<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\RestApiCredential;
use App\Models\RestApiTemplate;
use App\Models\RestApiDeviceTemplate;
use App\Models\RestApiDeviceMapping;
use App\Services\RestApi\RestApiClient;
use App\Services\RestApi\Vendors\VendorMappingRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RestApiCredentialController extends Controller
{
    public function create(Device $device): View
    {
        $templates = RestApiTemplate::all();
        $authTypes = [
            'bearer_token' => 'Bearer Token',
            'api_token' => 'API Token',
            'oauth2' => 'OAuth2',
            'basic_auth' => 'Basic Auth',
        ];
        return view('rest-api.credentials.create', compact('device', 'templates', 'authTypes'));
    }

    public function store(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate([
            'template_id' => 'required|exists:rest_api_templates,id',
            'auth_type' => 'required|in:bearer_token,api_token,oauth2,basic_auth',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'auth_token' => 'nullable|string',
        ]);

        RestApiCredential::updateOrCreate(
            ['device_id' => $device->device_id],
            [
                'auth_type' => $validated['auth_type'],
                'username' => $validated['username'],
                'password' => $validated['password'],
                'auth_token' => $validated['auth_token'],
            ]
        );

        RestApiDeviceTemplate::updateOrCreate(
            ['device_id' => $device->device_id],
            ['template_id' => $validated['template_id']]
        );

        return redirect()->route('rest-api.credentials.edit', $device)->with('success', 'REST API credentials configured');
    }

    public function edit(Device $device): View
    {
        $credential = RestApiCredential::where('device_id', $device->device_id)->first();
        $deviceTemplate = RestApiDeviceTemplate::where('device_id', $device->device_id)->first();
        $templates = RestApiTemplate::all();
        $authTypes = [
            'bearer_token' => 'Bearer Token',
            'api_token' => 'API Token',
            'oauth2' => 'OAuth2',
            'basic_auth' => 'Basic Auth',
        ];

        VendorMappingRegistry::initialize();
        $vendorMappings = VendorMappingRegistry::getOptions();
        $customMappings = VendorMappingRegistry::getCustomMappings();
        $currentMapping = RestApiDeviceMapping::where('device_id', $device->device_id)->first();

        return view('rest-api.credentials.edit', compact(
            'device',
            'credential',
            'deviceTemplate',
            'templates',
            'authTypes',
            'vendorMappings',
            'customMappings',
            'currentMapping'
        ));
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate([
            'template_id' => 'required|exists:rest_api_templates,id',
            'auth_type' => 'required|in:bearer_token,api_token,oauth2,basic_auth',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'auth_token' => 'nullable|string',
        ]);

        RestApiCredential::where('device_id', $device->device_id)->update([
            'auth_type' => $validated['auth_type'],
            'username' => $validated['username'],
            'password' => $validated['password'],
            'auth_token' => $validated['auth_token'],
        ]);

        RestApiDeviceTemplate::where('device_id', $device->device_id)->update([
            'template_id' => $validated['template_id']
        ]);

        return redirect()->route('rest-api.credentials.edit', $device)->with('success', 'REST API credentials updated');
    }

    public function setMapping(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate([
            'mapping_type' => 'required|in:vendor,custom',
            'mapping_name' => 'required|string',
        ]);

        RestApiDeviceMapping::updateOrCreate(
            ['device_id' => $device->device_id],
            [
                'mapping_type' => $validated['mapping_type'],
                'mapping_name' => $validated['mapping_name'],
            ]
        );

        return redirect()->route('rest-api.credentials.edit', $device)->with('success', 'Field mapping updated');
    }

    public function destroy(Device $device): RedirectResponse
    {
        RestApiCredential::where('device_id', $device->device_id)->delete();
        RestApiDeviceTemplate::where('device_id', $device->device_id)->delete();
        RestApiDeviceMapping::where('device_id', $device->device_id)->delete();

        return redirect()->route('devices.show', $device)->with('success', 'REST API configuration removed');
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
        $connection = $templateData['connections'][0] ?? null;

        if (!$connection) {
            return response()->json(['success' => false, 'error' => 'No connection configured']);
        }

        $baseUrl = str_replace('{device_hostname}', $device->hostname, $connection['base_url']);
        $endpoint = $template->endpoints()->first();

        if (!$endpoint) {
            return response()->json(['success' => false, 'error' => 'No endpoints configured']);
        }

        $client = new RestApiClient();
        $url = $endpoint->getUrl($baseUrl);
        $headers = $credential->getAuthHeader();

        $response = $client->get($url, $headers);

        return response()->json($response);
    }
}
