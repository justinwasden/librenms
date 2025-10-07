<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\MetricFieldMapping;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

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
		    $path = storage_path('app/resource_mappings.json');
		    if (!file_exists($path)) {
		        return back()->with('error', 'Mapping file not found.');
		    }

		    $json = json_decode(file_get_contents($path), true);
		    $mappings = $json['metric_field_mappings'] ?? [];

		    foreach ($mappings as $map) {
		        \App\Models\MetricFieldMapping::updateOrCreate([
		            'metric_name' => $map['metric_name'],
		            'resource_type' => $map['resource_type'] ?? null,
		            'vendor' => $map['vendor'] ?? null,
		            'os' => $map['os'] ?? null,
		        ], [
		            'librenms_table' => $map['librenms_table'],
		            'librenms_field' => $map['librenms_field'],
		            'enabled' => true,
		            'auto_learned' => false,
		            'last_seen_at' => now(),
		        ]);
		    }

		    return back()->with('success', 'Mappings imported successfully.');
		}

		public function exportToJson()
		{
		    $mappings = \App\Models\MetricFieldMapping::all();
		    $json = $mappings->toJson(JSON_PRETTY_PRINT);
		    Storage::put('exports/metric_field_mappings.json', $json);

		    return response()->download(storage_path('app/exports/metric_field_mappings.json'));
		}
}
