<?php

namespace App\Http\Controllers\Device;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RestApiConnection;
use App\Models\RestApiTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class RestApiActionsController extends Controller
{
    public function index(Device $device)
    {
        Gate::authorize('view', $device);

        $device->load([
            'restApiConnections.credential',
            'restApiConnections.endpoints'
        ]);

        $templates = RestApiTemplate::all();

        return view('devices.tabs.rest-api.index', compact('device', 'templates'));
    }

    public function applyTemplate(Request $request, Device $device)
    {
        Gate::authorize('update', $device);

        $request->validate([
            'template_id' => 'required|exists:rest_api_templates,id'
        ]);

        $template = RestApiTemplate::findOrFail($request->template_id);

        $templateData = $this->replacePlaceholdersInArray($template->template_data, $device);

        foreach ($templateData['connections'] ?? [] as $connData) {
            $connection = $device->restApiConnections()->create([
                'name' => $connData['name'],
                'base_url' => $connData['base_url'],
                'credential_id' => $connData['credential_id'] ?? null,
                'rate_limit' => $connData['rate_limit'] ?? 60,
            ]);

            foreach ($connData['endpoints'] ?? [] as $endpointData) {
                $connection->endpoints()->create([
                    'name' => $endpointData['name'],
                    'path' => $endpointData['path'],
                    'method' => $endpointData['method'] ?? 'GET',
                    'query_params' => $endpointData['query_params'] ?? null,
                    'headers' => $endpointData['headers'] ?? null,
                    'body' => $endpointData['body'] ?? null,
                    'metric_map' => $endpointData['metric_map'] ?? null,
                ]);
            }
        }

        return redirect()->route('device.rest-api.index', $device)
            ->with('success', 'Template applied successfully.');
    }

    public function destroyConnection(Device $device, RestApiConnection $connection)
    {
        Gate::authorize('update', $device);

        // Security: Verify the connection belongs to this device
        if ($connection->device_id !== $device->device_id) {
            abort(403, 'This connection does not belong to the specified device.');
        }

        $connection->delete();

        return redirect()->route('device.rest-api.index', $device)
            ->with('success', 'API Connection deleted successfully.');
    }

    private function replacePlaceholdersInArray(array $data, Device $device): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->replacePlaceholdersInArray($value, $device);
            } elseif (is_string($value)) {
                $result[$key] = $this->replacePlaceholdersInString($value, $device);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function replacePlaceholdersInString(string $string, Device $device): string
    {
        $string = Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $device->ip, $string);

        $string = preg_replace_callback(
            '/\{\{\s*\$device->getAttrib\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*\}\}/',
            function ($matches) use ($device) {
                return $device->getAttrib($matches[1]) ?? '';
            },
            $string
        );

        return $string;
    }
}