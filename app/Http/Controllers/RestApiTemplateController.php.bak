<?php

namespace App\Http\Controllers;

use App\Models\RestApiTemplate;
use App\Models\RestApiDeviceTemplate;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class RestApiTemplateController extends Controller
{
    public function index(): View
    {
        $templates = RestApiTemplate::all();
        return view('rest-api.templates.index', compact('templates'));
    }

    public function show(RestApiTemplate $template): View
    {
        $endpoints = $template->endpoints;
        return view('rest-api.templates.show', compact('template', 'endpoints'));
    }

    public function edit(RestApiTemplate $template): View
    {
        $endpoints = $template->endpoints;
        return view('rest-api.templates.edit', compact('template', 'endpoints'));
    }

    public function update(Request $request, RestApiTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'vendor' => 'required|string',
            'description' => 'required|string',
            'template_data' => 'required|array',
        ]);

        $template->update($validated);

        return redirect()->route('rest-api.templates.show', $template)->with('success', 'Template updated');
    }

    public function devices(RestApiTemplate $template): View
    {
        $devices = $template->devices()->with('device')->get();
        return view('rest-api.templates.devices', compact('template', 'devices'));
    }
}
