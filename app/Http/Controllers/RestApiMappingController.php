<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Services\RestApi\Vendors\VendorMappingRegistry;

class RestApiMappingController extends Controller
{
    public function create(): View
    {
        $example = [
            'device' => [
                'hostname' => '$.items[0].name',
                'version' => '$.items[0].version',
                'hardware' => '$.items[0].model',
            ],
            'port' => [
                'ifName' => '$.items[*].name',
                'ifSpeed' => '$.items[*].speed',
                'ifPhysAddress' => '$.items[*].eth.mac_address',
            ],
            'storage' => [
                'storage_descr' => '$.items[*].name',
                'storage_size' => '$.items[*].space.total_provisioned',
                'storage_used' => '$.items[*].space.total_physical',
            ],
            'sensor' => [
                'sensor_descr' => '$.items[*].name',
                'sensor_value' => '$.items[*].value',
            ],
        ];

        return view('rest-api.mappings.create', compact('example'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|regex:/^[a-z0-9-]+$/',
            'description' => 'required|string',
            'resource_type' => 'required|in:device,port,storage,sensor,custom',
            'mapping' => 'required|json',
        ]);

        $path = storage_path('app/rest-api-mappings/' . $validated['name'] . '.json');

        if (File::exists($path)) {
            return redirect()->back()->withErrors(['name' => 'Mapping with this name already exists']);
        }

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'],
            'resource_type' => $validated['resource_type'],
            'mapping' => json_decode($validated['mapping'], true),
        ];

        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Log::info("Created custom REST API mapping: {$validated['name']}");

        return redirect()->route('rest-api.mappings.list')->with('success', 'Mapping created successfully');
    }

    public function list(): View
    {
        $customMappings = VendorMappingRegistry::getCustomMappings();
        $mappings = [];

        foreach ($customMappings as $name => $path) {
            $fullPath = storage_path('app/rest-api-mappings/' . $name . '.json');
            if (File::exists($fullPath)) {
                $data = json_decode(File::get($fullPath), true);
                $mappings[] = [
                    'name' => $name,
                    'description' => $data['description'] ?? 'No description',
                    'resource_type' => $data['resource_type'] ?? 'unknown',
                ];
            }
        }

        return view('rest-api.mappings.list', compact('mappings'));
    }

    public function edit(string $name): View
    {
        $path = storage_path('app/rest-api-mappings/' . $name . '.json');

        if (!File::exists($path)) {
            abort(404);
        }

        $data = json_decode(File::get($path), true);

        return view('rest-api.mappings.edit', compact('name', 'data'));
    }

    public function update(Request $request, string $name): RedirectResponse
    {
        $validated = $request->validate([
            'description' => 'required|string',
            'mapping' => 'required|json',
        ]);

        $path = storage_path('app/rest-api-mappings/' . $name . '.json');

        if (!File::exists($path)) {
            abort(404);
        }

        $data = json_decode(File::get($path), true);
        $data['description'] = $validated['description'];
        $data['mapping'] = json_decode($validated['mapping'], true);

        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Log::info("Updated custom REST API mapping: {$name}");

        return redirect()->route('rest-api.mappings.list')->with('success', 'Mapping updated successfully');
    }

    public function destroy(string $name): RedirectResponse
    {
        $path = storage_path('app/rest-api-mappings/' . $name . '.json');

        if (!File::exists($path)) {
            abort(404);
        }

        File::delete($path);

        Log::info("Deleted custom REST API mapping: {$name}");

        return redirect()->route('rest-api.mappings.list')->with('success', 'Mapping deleted successfully');
    }
}
