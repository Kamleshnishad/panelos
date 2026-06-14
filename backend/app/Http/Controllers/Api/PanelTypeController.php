<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PanelTypeController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;
        $all = $request->boolean('all', false); // include inactive

        $query = PanelType::where('company_id', $companyId)->orderBy('name');
        if (!$all) $query->where('is_active', true);

        $types = $query->get();
        if (!auth()->user()->canViewCost()) $types->each->makeHidden('base_price');
        return response()->json(['success' => true, 'data' => $types]);
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $validated = $request->validate([
            'name'                  => 'required|string|max:100',
            'code'                  => ['required', 'string', 'max:20', Rule::unique('panel_types', 'code')->where('company_id', $companyId)],
            'category'              => 'required|in:roof,wall,ceiling,cold_room',
            'hsn_code'              => 'nullable|string|max:20',
            'description'           => 'nullable|string|max:500',
            'base_price'            => 'required|numeric|min:0',
            'available_thicknesses' => 'nullable|array',
            'available_thicknesses.*' => 'integer|min:20|max:300',
        ]);

        $pt = PanelType::create([
            'company_id'            => $companyId,
            'name'                  => $validated['name'],
            'code'                  => strtoupper($validated['code']),
            'category'              => $validated['category'],
            'hsn_code'              => $validated['hsn_code'] ?? '39259010',
            'description'           => $validated['description'] ?? null,
            'base_price'            => $validated['base_price'],
            'available_thicknesses' => $validated['available_thicknesses'] ?? null,
            'thickness'             => $validated['available_thicknesses'][0] ?? 50,
            'width'                 => 1000,
            'length'                => 3000,
            'thermal_resistance'    => 2.5,
            'is_active'             => true,
        ]);

        return response()->json(['success' => true, 'data' => $pt], 201);
    }

    public function update(Request $request, $id)
    {
        $companyId = auth()->user()->company_id;
        $pt = PanelType::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'name'                  => 'sometimes|required|string|max:100',
            'code'                  => ['sometimes', 'required', 'string', 'max:20', Rule::unique('panel_types', 'code')->where('company_id', $companyId)->ignore($id)],
            'category'              => 'sometimes|required|in:roof,wall,ceiling,cold_room',
            'hsn_code'              => 'nullable|string|max:20',
            'description'           => 'nullable|string|max:500',
            'base_price'            => 'sometimes|required|numeric|min:0',
            'available_thicknesses' => 'nullable|array',
            'available_thicknesses.*' => 'integer|min:20|max:300',
            'is_active'             => 'sometimes|boolean',
        ]);

        $pt->update($validated);
        return response()->json(['success' => true, 'data' => $pt->fresh()]);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = auth()->user()->company_id;
        $pt = PanelType::where('company_id', $companyId)->findOrFail($id);
        $pt->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'Panel type deactivated.']);
    }

    public function uploadImage(Request $request, $id)
    {
        $companyId = auth()->user()->company_id;
        $pt = PanelType::where('company_id', $companyId)->findOrFail($id);

        $request->validate(['image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:3072']);

        if ($pt->image && \Illuminate\Support\Facades\Storage::disk('public')->exists($pt->image)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($pt->image);
        }
        $path = $request->file('image')->store("panel-types/{$companyId}", 'public');
        $pt->update(['image' => $path]);

        return response()->json(['success' => true, 'data' => $pt->fresh(), 'message' => 'Image uploaded']);
    }
}
