<?php

namespace App\Http\Controllers\Device;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RestApiConnection;
use App\Models\RestApiTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class RestApiController extends Controller
{
    public function index(Device $device)
    {
        Gate::authorize('view', $device);
        $device->load('restApiConnections.endpoints');
        $templates = RestApiTemplate::all();

        return view('devices.tabs.rest-api.index', compact('device', 'templates'));
    }

    public function applyTemplate(Request $request, Device $device)
    {
        Gate::authorize('update', $device);

        $request->validate(['template_id' => 'required|exists:rest_api_templates,id']);

        $template = RestApiTemplate::find($request->template_id);

        // A simple string replacement for placeholders.
        // A more advanced solution might use Blade or another templating engine.
        $templateJsonString = json_encode($template->template_data);
        $templateJsonString = Str::replace('{{ $device->hostname }}', $device->hostname, $templateJsonString);
        $templateJsonString = Str::replace('{{ $device->ip }}', $device->ip, $templateJsonString);

        // Regex to find all `getAttrib` placeholders
        preg_match_all('/\{\{ \$device->getAttrib\(\'(.*?)\'\) \}\}/', $templateJsonString, $matches);
        foreach ($matches[1] as $attribName) {
            $attribValue = $device->getAttrib($attribName);
            $templateJsonString = Str::replace("{{ \$device->getAttrib('$attribName') }}", $attribValue, $templateJsonString);
        }

        $templateData = json_decode($templateJsonString, true);

        foreach ($templateData['connections'] as $connData) {
            $connection = $device->restApiConnections()->create([
                'name' => $connData['name'],
                'base_url' => $connData['base_url'],
                'credential_id' => $connData['credential_id'] ?? null,
                'rate_limit' => $connData['rate_limit'] ?? 60,
            ]);

            foreach ($connData['endpoints'] as $endpointData) {
                $connection->endpoints()->create($endpointData);
            }
        }

        return redirect()->route('device.rest-api.index', $device)->with('success', 'Template applied successfully.');
    }

    public function destroyConnection(Device $device, RestApiConnection $connection)
    {
        Gate::authorize('update', $device);

        $connection->delete();

        return redirect()->route('device.rest-api.index', $device)->with('success', 'API Connection deleted successfully.');
    }
}