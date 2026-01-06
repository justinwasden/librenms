<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiAuthSchema;
use Illuminate\Http\Request;
use LibreNMS\Util\ApiTemplateManager;

class ApiAuthSchemaController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:admin');
    }

    /**
     * Display list of auth schemas
     */
    public function index()
    {
        $schemas = ApiAuthSchema::withCount('templates')
            ->orderBy('name')
            ->get();

        return view('admin.api-templates.auth-schemas', [
            'schemas' => $schemas,
        ]);
    }

    /**
     * Show form to create a new auth schema
     */
    public function create()
    {
        return view('admin.api-templates.auth-schema-form', [
            'schema' => null,
        ]);
    }

    /**
     * Store a new auth schema
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:100|unique:api_auth_schemas,key',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fields' => 'nullable|array',
            'fields.*.name' => 'required|string|max:100',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.type' => 'required|string|in:text,password,number,checkbox',
            'fields.*.required' => 'boolean',
            'fields.*.encrypted' => 'boolean',
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.default' => 'nullable|string|max:255',
        ]);

        ApiAuthSchema::create([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'fields' => $validated['fields'] ?? [],
            'is_system' => false,
        ]);

        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-auth-schemas.index')
            ->with('success', 'Auth schema created successfully.');
    }

    /**
     * Show form to edit an auth schema
     */
    public function edit(ApiAuthSchema $apiAuthSchema)
    {
        return view('admin.api-templates.auth-schema-form', [
            'schema' => $apiAuthSchema,
        ]);
    }

    /**
     * Update an auth schema
     */
    public function update(Request $request, ApiAuthSchema $apiAuthSchema)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:100|unique:api_auth_schemas,key,' . $apiAuthSchema->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fields' => 'nullable|array',
            'fields.*.name' => 'required|string|max:100',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.type' => 'required|string|in:text,password,number,checkbox',
            'fields.*.required' => 'boolean',
            'fields.*.encrypted' => 'boolean',
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.default' => 'nullable|string|max:255',
        ]);

        $apiAuthSchema->update([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'fields' => $validated['fields'] ?? [],
        ]);

        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-auth-schemas.index')
            ->with('success', 'Auth schema updated successfully.');
    }

    /**
     * Delete an auth schema
     */
    public function destroy(ApiAuthSchema $apiAuthSchema)
    {
        if ($apiAuthSchema->is_system) {
            return redirect()->route('admin.api-auth-schemas.index')
                ->with('error', 'Cannot delete system auth schemas.');
        }

        if ($apiAuthSchema->templates()->exists()) {
            return redirect()->route('admin.api-auth-schemas.index')
                ->with('error', 'Cannot delete auth schema that is in use by templates.');
        }

        $apiAuthSchema->delete();
        ApiTemplateManager::clearCache();

        return redirect()->route('admin.api-auth-schemas.index')
            ->with('success', 'Auth schema deleted successfully.');
    }
}
