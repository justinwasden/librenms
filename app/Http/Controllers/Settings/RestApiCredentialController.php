<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\RestApiAuthenticationType;
use App\Models\RestApiCredential;
use Illuminate\Http\Request;

class RestApiCredentialController extends Controller
{
    public function index()
    {
        $credentials = RestApiCredential::with('authenticationType', 'params')->get();
        $authTypes = RestApiAuthenticationType::all();
        return view('settings.rest-api.credentials.index', compact('credentials', 'authTypes'));
    }

    public function create()
    {
        $credential = new RestApiCredential();
        $authTypes = RestApiAuthenticationType::all();
        return view('settings.rest-api.credentials.create', compact('credential', 'authTypes'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:rest_api_credentials,name|max:255',
            'authentication_type_id' => 'required|exists:rest_api_authentication_types,id',
            'params' => 'required|array',
        ]);

        $credential = RestApiCredential::create($validated);

        foreach ($validated['params'] as $key => $value) {
            if ($value !== null) {
                $credential->params()->create(['key' => $key, 'value' => $value]);
            }
        }

        return redirect()->route('settings.rest-api.credentials.index')->with('success', 'Credential created successfully.');
    }

    public function edit(RestApiCredential $credential)
    {
        $authTypes = RestApiAuthenticationType::all();
        $credential->load('params');
        return view('settings.rest-api.credentials.edit', compact('credential', 'authTypes'));
    }

    public function update(Request $request, RestApiCredential $credential)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:rest_api_credentials,name,' . $credential->id,
            'authentication_type_id' => 'required|exists:rest_api_authentication_types,id',
            'params' => 'required|array',
        ]);

        $credential->update($validated);
        $credential->params()->delete(); // Easiest way to handle updates is to delete and re-create

        foreach ($validated['params'] as $key => $value) {
            if ($value !== null) {
                $credential->params()->create(['key' => $key, 'value' => $value]);
            }
        }

        return redirect()->route('settings.rest-api.credentials.index')->with('success', 'Credential updated successfully.');
    }

    public function destroy(RestApiCredential $credential)
    {
        $credential->delete();
        return redirect()->route('settings.rest-api.credentials.index')->with('success', 'Credential deleted successfully.');
    }

    public function getAuthTypeParams(Request $request, $typeId)
    {
        $type = RestApiAuthenticationType::findOrFail($typeId);

        if ($request->has('credential_id')) {
            $credential = RestApiCredential::with('params')->findOrFail($request->credential_id);
        } else {
            $credential = new RestApiCredential();
        }

        $viewName = 'settings.rest-api.credentials.partials.' . str_replace(' ', '-', strtolower($type->name));

        if (view()->exists($viewName)) {
            return view($viewName, compact('credential'));
        }

        return response()->json(['error' => 'No parameters form found for this authentication type.'], 404);
    }
}