<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\MetricFieldMapping;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class MetricFieldMappingController extends Controller
{
    public function index(Request $request)
    {
        $query = MetricFieldMapping::query()->with('lastMatchedDevice');

        // Filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('metric_name', 'like', "%{$search}%")
                  ->orWhere('resource_type', 'like', "%{$search}%")
                  ->orWhere('librenms_table', 'like', "%{$search}%")
                  ->orWhere('librenms_field', 'like', "%{$search}%");
            });
        }

        if ($request->filled('vendor')) {
            $query->where('vendor', $request->vendor);
        }

        if ($request->filled('os')) {
            $query->where('os', $request->os);
        }

        if ($request->filled('status')) {
            if ($request->status === 'matched') {
                $query->where('librenms_table', '!=', 'unmatched')
                    ->where('librenms_field', '!=', 'unmatched');
            } elseif ($request->status === 'unmatched') {
                $query->where(function ($q) {
                    $q->where('librenms_table', 'unmatched')
                      ->orWhere('librenms_field', 'unmatched');
                });
            }
        }

        if ($request->filled('auto_learned')) {
            $query->where('auto_learned', $request->auto_learned === '1');
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'metric_name');
        $sortDir = $request->get('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $mappings = $query->paginate(25)->withQueryString();

        // Get unique vendors and OS for filters
        $vendors = MetricFieldMapping::whereNotNull('vendor')
            ->distinct()
            ->pluck('vendor')
            ->sort();

        $operatingSystems = MetricFieldMapping::whereNotNull('os')
            ->distinct()
            ->pluck('os')
            ->sort();

        return view('settings.metric-field-mappings.index', compact('mappings', 'vendors', 'operatingSystems'));
    }

    public function create()
    {
        $devices = Device::whereHas('restApiConnections')
            ->orderBy('hostname')
            ->get();

        $tables = $this->getLibreNMSTables();

        return view('settings.metric-field-mappings.create', compact('devices', 'tables'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'metric_name' => 'required|string|max:100',
            'resource_type' => 'nullable|string|max:50',
            'vendor' => 'nullable|string|max:100',
            'os' => 'nullable|string|max:100',
            'librenms_table' => 'required|string|max:100',
            'librenms_field' => 'required|string|max:100',
            'data_type' => 'required|in:numeric,string,boolean,json',
            'unit' => 'nullable|string|max:50',
            'multiplier' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'enabled' => 'boolean',
        ]);

        $validated['metric_name'] = strtolower($validated['metric_name']);
        $validated['resource_type'] = isset($validated['resource_type']) ? strtolower($validated['resource_type']) : null;
        $validated['auto_learned'] = false;
        $validated['enabled'] = $request->has('enabled');

        MetricFieldMapping::create($validated);

        return redirect()
            ->route('settings.metric-field-mappings.index')
            ->with('success', 'Metric mapping created successfully!');
    }

    public function edit(MetricFieldMapping $mapping)
    {
        $devices = Device::whereHas('restApiConnections')
            ->orderBy('hostname')
            ->get();

        $tables = $this->getLibreNMSTables();

        return view('settings.metric-field-mappings.edit', compact('mapping', 'devices', 'tables'));
    }

    public function update(Request $request, MetricFieldMapping $mapping)
    {
        $validated = $request->validate([
            'librenms_table' => 'required|string|max:100',
            'librenms_field' => 'required|string|max:100',
            'data_type' => 'required|in:numeric,string,boolean,json',
            'unit' => 'nullable|string|max:50',
            'multiplier' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'enabled' => 'boolean',
        ]);

        $validated['auto_learned'] = false;
        $validated['enabled'] = $request->has('enabled');

        $mapping->update($validated);

        return redirect()
            ->route('settings.metric-field-mappings.index')
            ->with('success', 'Metric mapping updated successfully!');
    }

    public function destroy(MetricFieldMapping $mapping)
    {
        $mapping->delete();

        return redirect()
            ->route('settings.metric-field-mappings.index')
            ->with('success', 'Metric mapping deleted successfully!');
    }

    public function toggle(MetricFieldMapping $mapping)
    {
        $mapping->update(['enabled' => !$mapping->enabled]);

        $status = $mapping->enabled ? 'enabled' : 'disabled';

        return redirect()
            ->route('settings.metric-field-mappings.index')
            ->with('success', "Metric mapping {$status} successfully!");
    }

    public function runMatching(Request $request)
    {
        $options = [];

        if ($request->filled('device_id')) {
            $options['--device_id'] = $request->device_id;
        }

        if ($request->filled('vendor')) {
            $options['--vendor'] = $request->vendor;
        }

        if ($request->filled('os')) {
            $options['--os'] = $request->os;
        }

        if ($request->has('reset')) {
            $options['--reset'] = true;
        }

        try {
            Artisan::call('metrics:match', $options);

            $output = Artisan::output();

            return redirect()
                ->route('settings.metric-field-mappings.index')
                ->with('success', 'Metrics matching completed!')
                ->with('output', $output);
        } catch (\Exception $e) {
            return redirect()
                ->route('settings.metric-field-mappings.index')
                ->with('error', 'Error running metrics matching: ' . $e->getMessage());
        }
    }

    public function bulkDeleteUnmatched()
    {
        $count = MetricFieldMapping::unmatched()->delete();

        return redirect()
            ->route('settings.metric-field-mappings.index')
            ->with('success', "Deleted {$count} unmatched mapping(s)");
    }

    protected function getLibreNMSTables(): array
    {
        return [
            'devices' => 'Devices',
            'sensors' => 'Sensors',
            'ports' => 'Ports/Interfaces',
            'storage' => 'Storage',
            'mempools' => 'Memory Pools',
            'processors' => 'Processors',
            'wireless_sensors' => 'Wireless Sensors',
        ];
    }

    public function getTableFields(Request $request)
    {
        $table = $request->input('table');

        $fields = match($table) {
            'devices' => [
                'status' => 'Device Status',
                'serial' => 'Serial Number',
                'hardware' => 'Hardware/Model',
                'version' => 'Firmware/OS Version',
                'storage_total' => 'Total Storage',
                'storage_used' => 'Used Storage',
                'storage_free' => 'Free Storage',
                'uptime' => 'Uptime',
                'sysName' => 'System Name',
                'location' => 'Location',
                'sysContact' => 'Contact',
            ],
            'sensors' => [
                'sensor_current' => 'Current Value',
                'sensor_limit' => 'Limit',
                'sensor_limit_low' => 'Low Limit',
                'sensor_descr' => 'Description',
            ],
            'ports' => [
                'ifSpeed' => 'Interface Speed',
                'ifOperStatus' => 'Operational Status',
                'ifAdminStatus' => 'Admin Status',
                'ifName' => 'Interface Name',
                'ifAlias' => 'Interface Alias',
                'ifDescr' => 'Interface Description',
                'ifMtu' => 'MTU',
            ],
            default => [],
        };

        return response()->json($fields);
    }

    public function importFromJson(Request $request)
    {
        // 1. Validate File Upload and Type
        $request->validate([
            'mapping_file' => 'required|file|mimes:json,txt', // Only allow JSON or plain text for safety
            'overwrite' => 'nullable|boolean',
        ], [
            'mapping_file.mimes' => 'The uploaded file must be a JSON file.',
        ]);

        $file = $request->file('mapping_file');
        $jsonContent = file_get_contents($file->getRealPath());
        $mappings = json_decode($jsonContent, true);

        // 2. Validate JSON Structure (Must be a decode-able array)
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($mappings)) {
            return redirect()->route('settings.metric-field-mappings.index')
                ->with('error', 'The uploaded file is not a valid JSON array of mappings.');
        }

        $overwrite = (bool) $request->input('overwrite', false);
        $importedCount = 0;
        $failedCount = 0;
        $errors = [];

        // 3. Define Validation Rules for each Mapping Object
        $rules = [
            'metric_name'   => 'required|string|max:255',
            'resource_type' => 'nullable|string|max:255',
            'vendor'        => 'nullable|string|max:255',
            'os'            => 'nullable|string|max:255',
            'librenms_table' => 'required|string|max:255',
            'librenms_field' => 'required|string|max:255',
            'data_type'     => 'required|in:gauge,counter,rate', // Example types
            'enabled'       => 'nullable|boolean',
        ];

        // 4. Loop through and validate each mapping
        foreach ($mappings as $key => $mappingData) {
            $validator = Validator::make($mappingData, $rules);

            if ($validator->fails()) {
                $errors[] = "Mapping #{$key} failed validation: " . $validator->errors()->all()[0];
                $failedCount++;
                continue;
            }

            // 5. Check for existence (only required if NOT overwriting)
            $exists = \App\Models\MetricFieldMapping::where([
                'metric_name' => $mappingData['metric_name'],
                'vendor'      => $mappingData['vendor'] ?? null,
                'os'          => $mappingData['os'] ?? null,
            ])->first();

            if ($exists && ! $overwrite) {
                // Skip if exists and we are not overwriting
                $failedCount++;
                continue;
            }

            // 6. Import/Update the mapping
            try {
                \App\Models\MetricFieldMapping::updateOrCreate(
                    [
                        'metric_name' => $mappingData['metric_name'],
                        'vendor'      => $mappingData['vendor'] ?? null,
                        'os'          => $mappingData['os'] ?? null,
                    ],
                    array_merge($mappingData, ['auto_learned' => false]) // Mark as manual import
                );
                $importedCount++;
            } catch (\Exception $e) {
                $errors[] = "Mapping #{$key} failed database insertion: " . $e->getMessage();
                $failedCount++;
            }
        }

        // 7. Return Result
        if ($failedCount > 0) {
            $errorMessage = "Import finished. {$importedCount} mappings imported/updated. {$failedCount} failed or skipped.";
            if (! empty($errors)) {
                $errorMessage .= " Errors: " . implode('; ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '...' : '');
            }
            return redirect()->route('settings.metric-field-mappings.index')
                ->with('error', $errorMessage);
        }

        return redirect()->route('settings.metric-field-mappings.index')
            ->with('success', "Successfully imported/updated {$importedCount} metric field mappings.");
    }

		public function exportToJson()
		{
		    $mappings = \App\Models\MetricFieldMapping::all();
		    $json = $mappings->toJson(JSON_PRETTY_PRINT);
		    Storage::put('exports/metric_field_mappings.json', $json);

		    return response()->download(storage_path('app/exports/metric_field_mappings.json'));
		}

		public function showImportForm()
		{
		    return view('settings.metric-field-mappings.import');
		}
}
