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

    public static function slug(): string
    {
        return 'rest-api';
    }

    public static function visible(Device $device): bool
    {
        // Show the tab if:
        // 1. User can update the device, OR
        // 2. Device has REST API connections configured
        return Gate::allows('update', $device) || $device->restApiConnections()->exists();
    }

    public function edit(Device $device)
    {
        Gate::authorize('update', $device);

        $device->load('restApiConnections.endpoints', 'restApiConnections.credential');
        $templates = RestApiTemplate::all();

        return view('device.edit.rest-api', compact('device', 'templates'));
    }

    public function applyTemplate(Request $request, Device $device)
    {
        Gate::authorize('update', $device);

        $request->validate(['template_id' => 'required|exists:rest_api_templates,id']);

        $template = RestApiTemplate::find($request->template_id);

        $templateData = $this->replacePlaceholdersInArray($template->template_data, $device);

        foreach ($templateData['connections'] as $connData) {
            $connection = $device->restApiConnections()->create([
                'name' => $connData['name'],
                'base_url' => $connData['base_url'],
                'credential_id' => $connData['credential_id'] ?? null,
                'rate_limit' => $connData['rate_limit'] ?? 60,
            ]);

            if (isset($connData['endpoints']) && is_array($connData['endpoints'])) {
                foreach ($connData['endpoints'] as $endpointData) {
                    $connection->endpoints()->create($endpointData);
                }
            }
        }

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'Template applied successfully.');
    }

    public function destroyConnection(Device $device, RestApiConnection $connection)
    {
        Gate::authorize('update', $device);

        // Ensure the connection belongs to this device
        if ($connection->device_id !== $device->device_id) {
            abort(404);
        }

        $connection->delete();

        return redirect()->route('device.edit.rest-api', $device)->with('success', 'API Connection deleted successfully.');
    }

    private function replacePlaceholdersInArray(array $data, Device $device): array
    {
        array_walk_recursive($data, function (&$value) use ($device) {
            if (is_string($value)) {
                $value = $this->replacePlaceholdersInString($value, $device);
            }
        });

        return $data;
    }

    private function replacePlaceholdersInString(string $string, Device $device): string
    {
        $string = Str::replace('{{ $device->hostname }}', $device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $device->ip, $string);

        preg_match_all('/\{\{ \$device->getAttrib\(([\'"])(.*?)\1\) \}\}/', $string, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $attribName) {
                $attribValue = $device->getAttrib($attribName);
                $fullPlaceholder = $matches[0][$index];
                $string = Str::replace($fullPlaceholder, $attribValue, $string);
            }
        }

        return $string;
    }
}