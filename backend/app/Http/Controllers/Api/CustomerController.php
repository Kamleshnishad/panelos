<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Rules\IndianPhone;
use App\Rules\IndianGstin;
use App\Rules\IndianPan;
use App\Rules\IndianPincode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /** Customer 360 profile — KPIs, repeat frequency, RFM segment + all related records. */
    public function profile($id)
    {
        try {
            $companyId = auth()->user()->company_id;
            $customer = Customer::where('company_id', $companyId)->findOrFail($id);
            $data = app(\App\Services\CustomerProfileService::class)->profile($customer);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /** Live credit status — outstanding vs limit (optionally incl. a prospective order). */
    public function creditStatus(Request $request, $id)
    {
        try {
            $companyId = auth()->user()->company_id;
            $customer = Customer::where('company_id', $companyId)->findOrFail($id);
            $data = app(\App\Services\CreditService::class)
                ->status($customer, (float) $request->query('order_total', 0));
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'code'           => ['nullable', 'string', 'max:20', Rule::unique('customers', 'code')->where('company_id', $companyId)],
            'type'           => 'nullable|in:retail,wholesale,distributor,corporate',
            'contact_person' => 'nullable|string|max:100',
            'email'          => ['nullable', 'email', 'max:100', Rule::unique('customers', 'email')->where('company_id', $companyId)->ignore(null)],
            'phone'          => ['nullable', 'string', 'max:20', new IndianPhone(), Rule::unique('customers', 'phone')->where('company_id', $companyId)],
            'whatsapp_no'    => ['nullable', 'string', 'max:20', new IndianPhone()],
            'gstin'          => ['nullable', 'string', 'max:15', new IndianGstin(), Rule::unique('customers', 'gstin')->where('company_id', $companyId)],
            'pan'            => ['nullable', 'string', 'max:20', new IndianPan()],
            'address_line1'  => 'nullable|string|max:255',
            'address_line2'  => 'nullable|string|max:255',
            'city'           => 'nullable|string|max:100',
            'state'          => 'nullable|string|max:100',
            'state_code'     => 'nullable|string|max:2',
            'pincode'        => ['nullable', 'string', 'max:10', new IndianPincode()],
            'credit_limit'       => 'nullable|numeric|min:0',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'notes'              => 'nullable|string|max:2000',
        ]);

        $customer = Customer::create([
            'company_id'     => $companyId,
            'name'           => $validated['name'],
            'code'           => $validated['code'] ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $validated['name']), 0, 4)) . rand(100, 999),
            'type'           => $validated['type'] ?? 'retail',
            'contact_person' => $validated['contact_person'] ?? null,
            'email'          => $validated['email'] ?? null,
            'phone'          => $validated['phone'] ?? '',
            'whatsapp_no'    => $validated['whatsapp_no'] ?? null,
            'gstin'          => $validated['gstin'] ?? null,
            // These columns are NOT NULL (no default) — coalesce to '' so the
            // create never crashes when the form omits them.
            'address_line1'  => $validated['address_line1'] ?? '',
            'address_line2'  => $validated['address_line2'] ?? null,
            'city'           => $validated['city'] ?? '',
            'state'          => $validated['state'] ?? '',
            'state_code'     => $validated['state_code'] ?? '',
            'pincode'        => $validated['pincode'] ?? '',
            'country'        => 'India',
            // Previously hard-coded 0/30, so a credit limit or payment terms sent
            // by the form were silently dropped and credit control never engaged.
            'credit_limit'   => $validated['credit_limit'] ?? 0,
            'outstanding_balance' => 0,
            'payment_terms_days'  => $validated['payment_terms_days'] ?? 30,
            'notes'          => $validated['notes'] ?? null,
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
            'email'              => ['nullable', 'email', 'max:100', Rule::unique('customers', 'email')->where('company_id', $companyId)->ignore($id)],
            'phone'              => ['nullable', 'string', 'max:20', new IndianPhone(), Rule::unique('customers', 'phone')->where('company_id', $companyId)->ignore($id)],
            'whatsapp_no'        => ['nullable', 'string', 'max:20', new IndianPhone()],
            'gstin'              => ['nullable', 'string', 'max:15', new IndianGstin(), Rule::unique('customers', 'gstin')->where('company_id', $companyId)->ignore($id)],
            'pan'                => ['nullable', 'string', 'max:20', new IndianPan()],
            'address_line1'      => 'nullable|string|max:255',
            'address_line2'      => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'state'              => 'nullable|string|max:100',
            'state_code'         => 'nullable|string|max:2',
            'pincode'            => ['nullable', 'string', 'max:10', new IndianPincode()],
            'credit_limit'       => 'nullable|numeric|min:0',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'is_active'          => 'nullable|boolean',
            'notes'              => 'nullable|string|max:2000',
        ]);

        // The edit form sends EVERY field, with "" for each one left blank, and
        // Laravel's ConvertEmptyStringsToNull turns those into null. Several
        // customer columns are NOT NULL, so writing that straight through failed
        // the whole save with "Column 'address_line1' cannot be null" — a 500 for
        // any customer with, say, no address on file. store() already coalesced
        // these to ''; update() never did, and it went unnoticed only because the
        // edit form had no way to open until recently.
        foreach (['phone', 'address_line1', 'city', 'state', 'state_code', 'pincode'] as $column) {
            if (array_key_exists($column, $validated) && $validated[$column] === null) {
                $validated[$column] = '';
            }
        }
        // Clearing a number means "back to the default", not "invalid".
        if (array_key_exists('credit_limit', $validated) && $validated['credit_limit'] === null) {
            $validated['credit_limit'] = 0;                    // 0 = no limit
        }
        if (array_key_exists('payment_terms_days', $validated) && $validated['payment_terms_days'] === null) {
            $validated['payment_terms_days'] = 30;
        }

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
