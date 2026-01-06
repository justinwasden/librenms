<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiAuthSchema;
use App\Models\ApiTemplate;
use App\Models\ApiTemplateEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\ApiTemplateManager;

class ApiTemplateController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:admin');
    }

    /**
     * Display list of API templates
     */
    public function index()
    {
        $templates = ApiTemplate::with('endpoints', 'authSchema')
            ->orderBy('name')
            ->get();

        $authSchemas = ApiAuthSchema::orderBy('name')->get();

        return view('admin.api-templates.index', [
            'templates' => $templates,
            'authSchemas' => $authSchemas,
        ]);
    }

    /**
     * Show form to create a new template
     */
    public function create()
    {
        $authSchemas = ApiAuthSchema::orderBy('name')->get();

        return view('admin.api-templates.create', [
            'authSchemas' => $authSchemas,
        ]);
    }

    /**
     * Store a new template
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:100|unique:api_templates,key',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'os_types' => 'nullable|string',
            'auth_type' => 'required|string|exists:api_auth_schemas,key',
            'base_url_pattern' => 'nullable|string|max:500',
            'capabilities' => 'nullable|string',
            'enabled' => 'boolean',
        ]);

        $template = ApiTemplate::create([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'os_types' => $this->parseCommaSeparated($validated['os_types'] ?? ''),
            'auth_type' => $validated['auth_type'],
            'base_url_pattern' => $validated['base_url_pattern'] ?? null,
            'capabilities' => $this->parseCommaSeparated($validated['capabilities'] ?? ''),
            'enabled' => $validated['enabled'] ?? true,
            'is_system' => false,
        ]);

        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.edit', $template)
            ->with('success', 'Template created successfully. Add endpoints below.');
    }

    /**
     * Show form to edit a template
     */
    public function edit(ApiTemplate $template)
    {
        $template->load('endpoints');
        $authSchemas = ApiAuthSchema::orderBy('name')->get();

        return view('admin.api-templates.edit', [
            'template' => $template,
            'authSchemas' => $authSchemas,
        ]);
    }

    /**
     * Update a template
     */
    public function update(Request $request, ApiTemplate $template)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:100|unique:api_templates,key,' . $template->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'os_types' => 'nullable|string',
            'auth_type' => 'required|string|exists:api_auth_schemas,key',
            'base_url_pattern' => 'nullable|string|max:500',
            'capabilities' => 'nullable|string',
            'enabled' => 'boolean',
        ]);

        $template->update([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'os_types' => $this->parseCommaSeparated($validated['os_types'] ?? ''),
            'auth_type' => $validated['auth_type'],
            'base_url_pattern' => $validated['base_url_pattern'] ?? null,
            'capabilities' => $this->parseCommaSeparated($validated['capabilities'] ?? ''),
            'enabled' => $validated['enabled'] ?? true,
        ]);

        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.edit', $template)
            ->with('success', 'Template updated successfully.');
    }

    /**
     * Delete a template
     */
    public function destroy(ApiTemplate $template)
    {
        if ($template->is_system) {
            return redirect()->route('admin.api-templates.index')
                ->with('error', 'Cannot delete system templates.');
        }

        $template->delete();
        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.index')
            ->with('success', 'Template deleted successfully.');
    }

    /**
     * Clone a template
     */
    public function clone(ApiTemplate $template)
    {
        DB::transaction(function () use ($template) {
            $newTemplate = $template->replicate();
            $newTemplate->key = $template->key . '_copy_' . time();
            $newTemplate->name = $template->name . ' (Copy)';
            $newTemplate->is_system = false;
            $newTemplate->save();

            foreach ($template->endpoints as $endpoint) {
                $newEndpoint = $endpoint->replicate();
                $newEndpoint->template_id = $newTemplate->id;
                $newEndpoint->save();
            }
        });

        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.index')
            ->with('success', 'Template cloned successfully.');
    }

    /**
     * Store a new endpoint for a template
     */
    public function storeEndpoint(Request $request, ApiTemplate $template)
    {
        $validated = $request->validate([
            'capability' => 'required|string|max:100',
            'method' => 'required|string|in:GET,POST,PUT,PATCH,DELETE',
            'path' => 'required|string|max:500',
            'transform' => 'nullable|string|max:500',
            'for_each' => 'nullable|string|max:100',
            'enabled' => 'boolean',
        ]);

        $maxOrder = $template->endpoints()->max('sort_order') ?? 0;

        $template->endpoints()->create([
            'capability' => $validated['capability'],
            'method' => $validated['method'],
            'path' => $validated['path'],
            'transform' => $validated['transform'] ?? null,
            'for_each' => $validated['for_each'] ?? null,
            'enabled' => $validated['enabled'] ?? true,
            'sort_order' => $maxOrder + 1,
        ]);

        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.edit', $template)
            ->with('success', 'Endpoint added successfully.');
    }

    /**
     * Update an endpoint
     */
    public function updateEndpoint(Request $request, ApiTemplateEndpoint $endpoint)
    {
        $validated = $request->validate([
            'capability' => 'required|string|max:100',
            'method' => 'required|string|in:GET,POST,PUT,PATCH,DELETE',
            'path' => 'required|string|max:500',
            'transform' => 'nullable|string|max:500',
            'for_each' => 'nullable|string|max:100',
            'enabled' => 'boolean',
        ]);

        $endpoint->update([
            'capability' => $validated['capability'],
            'method' => $validated['method'],
            'path' => $validated['path'],
            'transform' => $validated['transform'] ?? null,
            'for_each' => $validated['for_each'] ?? null,
            'enabled' => $validated['enabled'] ?? true,
        ]);

        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.edit', $endpoint->template)
            ->with('success', 'Endpoint updated successfully.');
    }

    /**
     * Toggle endpoint enabled status
     */
    public function toggleEndpoint(ApiTemplateEndpoint $endpoint)
    {
        $endpoint->update(['enabled' => !$endpoint->enabled]);
        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.edit', $endpoint->template)
            ->with('success', 'Endpoint ' . ($endpoint->enabled ? 'enabled' : 'disabled') . '.');
    }

    /**
     * Delete an endpoint
     */
    public function destroyEndpoint(ApiTemplateEndpoint $endpoint)
    {
        $template = $endpoint->template;
        $endpoint->delete();
        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.edit', $template)
            ->with('success', 'Endpoint deleted successfully.');
    }

    /**
     * Reorder endpoints
     */
    public function reorderEndpoints(Request $request, ApiTemplate $template)
    {
        $order = $request->input('order', []);

        foreach ($order as $index => $endpointId) {
            ApiTemplateEndpoint::where('id', $endpointId)
                ->where('template_id', $template->id)
                ->update(['sort_order' => $index]);
        }

        ApiTemplateManager::clearCache();

        return response()->json(['success' => true]);
    }

    /**
     * Export template as JSON
     */
    public function export(ApiTemplate $template)
    {
        $template->load('endpoints');

        $data = [
            'template' => $template->toTemplateArray(),
            'exported_at' => now()->toIso8601String(),
        ];

        return response()->json($data)
            ->header('Content-Disposition', 'attachment; filename="' . $template->key . '.json"');
    }

    /**
     * Import template from JSON
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:json|max:1024',
        ]);

        $content = json_decode(file_get_contents($request->file('file')->path()), true);

        if (!isset($content['template'])) {
            return redirect()->route('admin.api-templates.index')
                ->with('error', 'Invalid template file format.');
        }

        $templateData = $content['template'];

        DB::transaction(function () use ($templateData) {
            $template = ApiTemplate::create([
                'key' => $templateData['key'] . '_imported_' . time(),
                'name' => $templateData['name'] . ' (Imported)',
                'description' => $templateData['description'] ?? null,
                'os_types' => $templateData['os'] ?? [],
                'auth_type' => $templateData['auth_type'],
                'base_url_pattern' => $templateData['base_url_pattern'] ?? null,
                'capabilities' => $templateData['capabilities'] ?? [],
                'is_system' => false,
                'enabled' => true,
            ]);

            foreach ($templateData['endpoints'] ?? [] as $index => $ep) {
                $template->endpoints()->create([
                    'capability' => $ep['capability'],
                    'method' => $ep['method'] ?? 'GET',
                    'path' => $ep['path'],
                    'transform' => $ep['transform'] ?? null,
                    'for_each' => $ep['for_each'] ?? null,
                    'enabled' => true,
                    'sort_order' => $index,
                ]);
            }
        });

        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-templates.index')
            ->with('success', 'Template imported successfully.');
    }

    /**
     * Parse comma-separated string into array
     */
    private function parseCommaSeparated(?string $value): array
    {
        if (empty($value)) {
            return [];
        }

        return array_map('trim', array_filter(explode(',', $value)));
    }
}
