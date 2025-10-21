<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\RestApiTemplate;
use App\Models\RestApiCredential;
use App\Models\RestApiDeviceTemplate;
use App\RestApi\Services\MapperSelectionService;
use Illuminate\Http\Request;

class DeviceRestApiController extends Controller
{
    public function edit(Device $device)
    {
        $deviceTemplate = $device->restApiTemplate;

        return view('devices.rest-api-settings', [
            'device' => $device,
            'deviceTemplate' => $deviceTemplate,
            'templates' => RestApiTemplate::all(),
            'credentials' => RestApiCredential::where('device_id', $device->device_id)->get(),
            'availableMappers' => MapperSelectionService::getAvailableMappers(),
        ]);
    }

    public function update(Device $device, Request $request)
    {
        $validated = $request->validate([
            'template_id' => 'required|exists:rest_api_templates,id',
            'mapper_name' => 'nullable|string',
            'credential_id' => 'nullable|exists:rest_api_credentials,id',
        ]);

        $deviceTemplate = $device->restApiTemplate ?? new RestApiDeviceTemplate();
        $deviceTemplate->device_id = $device->device_id;
        $deviceTemplate->template_id = $validated['template_id'];
        $deviceTemplate->mapper_name = $validated['mapper_name'];
        
        // Set mapper source
        if ($validated['mapper_name']) {
            $deviceTemplate->mapper_source = 'user_selected';
        } else {
            $deviceTemplate->mapper_source = 'auto_detected';
        }

        $deviceTemplate->save();

        return redirect()
            ->route('devices.show', $device)
            ->with('success', 'REST API configuration saved!');
    }

    public function test(Device $device, Request $request)
    {
        $validated = $request->validate([
            'mapper_name' => 'nullable|string',
            'credential_id' => 'nullable|exists:rest_api_credentials,id',
            'template_id' => 'required|exists:rest_api_templates,id',
        ]);

        try {
            $template = RestApiTemplate::find($validated['template_id']);
            $credential = RestApiCredential::find($validated['credential_id']);

            if (!$template || !$credential) {
                return response()->json(['message' => 'Invalid template or credential'], 400);
            }

            // Get first endpoint from template
            $endpoints = $template->endpoints()->limit(1)->get();
            if ($endpoints->isEmpty()) {
                return response()->json(['message' => 'No endpoints in template'], 400);
            }

            $endpoint = $endpoints->first();
            
            return response()->json([
                'success' => true,
                'message' => 'Connection successful',
                'endpoint' => $endpoint->path,
                'mapper' => $validated['mapper_name'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
