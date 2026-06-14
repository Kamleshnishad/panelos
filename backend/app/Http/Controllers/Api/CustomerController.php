<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $query = Customer::where('company_id', $companyId)->where('is_active', true);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%')
                  ->orWhere('phone', 'like', '%' . $request->search . '%');
            });
        }

        $customers = $query->orderBy('name')->paginate($request->get('per_page', 50));

        return response()->json(['success' => true, 'data' => $customers]);
    }

    public function show($id)
    {
        $companyId = auth()->user()->company_id;
        $customer = Customer::where('company_id', $companyId)->findOrFail($id);
        return response()->json(['success' => true, 'data' => $customer]);
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'code'           => 'nullable|string|max:20',
            'type'           => 'nullable|in:retail,wholesale,distributor,corporate',
            'contact_person' => 'nullable|string|max:100',
            'email'          => 'nullable|email|max:100',
            'phone'          => 'nullable|string|max:20',
            'whatsapp_no'    => 'nullable|string|max:20',
            'gstin'          => 'nullable|string|max:15',
            'address_line1'  => 'nullable|string|max:255',
            'city'           => 'nullable|string|max:100',
            'state'          => 'nullable|string|max:100',
            'state_code'     => 'nullable|string|max:2',
            'pincode'        => 'nullable|string|max:10',
        ]);

        $customer = Customer::create([
            'company_id'     => $companyId,
            'name'           => $validated['name'],
            'code'           => $validated['code'] ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $validated['name']), 0, 4)) . rand(100, 999),
            'type'           => $validated['type'] ?? 'retail',
            'contact_person' => $validated['contact_person'] ?? null,
            'email'          => $validated['email'] ?? null,
            'phone'          => $validated['phone'] ?? null,
            'whatsapp_no'    => $validated['whatsapp_no'] ?? null,
            'gstin'          => $validated['gstin'] ?? null,
            'address_line1'  => $validated['address_line1'] ?? null,
            'city'           => $validated['city'] ?? null,
            'state'          => $validated['state'] ?? null,
            'state_code'     => $validated['state_code'] ?? null,
            'pincode'        => $validated['pincode'] ?? null,
            'country'        => 'India',
            'credit_limit'   => 0,
            'outstanding_balance' => 0,
            'payment_terms_days'  => 30,
            'is_active'      => true,
        ]);

        return response()->json(['success' => true, 'data' => $customer], 201);
    }

    public function update(Request $request, $id)
    {
        $companyId = auth()->user()->company_id;
        $customer = Customer::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'name'               => 'sometimes|required|string|max:255',
            'type'               => 'nullable|in:retail,wholesale,distributor,corporate',
            'contact_person'     => 'nullable|string|max:100',
            'email'              => 'nullable|email|max:100',
            'phone'              => 'nullable|string|max:20',
            'whatsapp_no'        => 'nullable|string|max:20',
            'gstin'              => 'nullable|string|max:15',
            'address_line1'      => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'state'              => 'nullable|string|max:100',
            'state_code'         => 'nullable|string|max:2',
            'pincode'            => 'nullable|string|max:10',
            'credit_limit'       => 'nullable|numeric|min:0',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'is_active'          => 'nullable|boolean',
            'notes'              => 'nullable|string|max:2000',
        ]);

        $customer->update($validated);

        return response()->json(['success' => true, 'data' => $customer]);
    }

    public function destroy($id)
    {
        $companyId = auth()->user()->company_id;
        $customer = Customer::where('company_id', $companyId)->findOrFail($id);
        $customer->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'Customer deactivated']);
    }
}
