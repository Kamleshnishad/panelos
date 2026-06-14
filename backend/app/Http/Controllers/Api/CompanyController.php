<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    use ApiResponse;

    /**
     * GET /company — current user's company profile.
     */
    public function show(Request $request)
    {
        try {
            $company = Company::findOrFail($request->user()->company_id);
            return $this->successResponse($this->present($company), 'Company retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to load company', 'COMPANY_ERROR', 500);
        }
    }

    /**
     * PUT /company — update profile fields.
     */
    public function update(Request $request)
    {
        try {
            $company = Company::findOrFail($request->user()->company_id);

            // Only company admins / super admins may edit
            if (!$request->user()->is_company_admin && !$request->user()->is_super_admin) {
                return $this->errorResponse([], 'Only company admins can edit company settings', 'FORBIDDEN', 403);
            }

            $validated = $request->validate([
                'name'                 => 'required|string|max:255',
                'gstin'                => 'nullable|string|max:20',
                'pan'                  => 'nullable|string|max:20',
                'address_line1'        => 'nullable|string|max:255',
                'city'                 => 'nullable|string|max:100',
                'state'                => 'nullable|string|max:100',
                'state_code'           => 'nullable|string|max:5',
                'pincode'              => 'nullable|string|max:10',
                'phone'                => 'nullable|string|max:20',
                'email'                => 'nullable|email|max:255',
                'bank_name'            => 'nullable|string|max:255',
                'bank_account_no'      => 'nullable|string|max:50',
                'bank_ifsc'            => 'nullable|string|max:20',
                'bank_branch'          => 'nullable|string|max:255',
                'authorized_signatory' => 'nullable|string|max:255',
                'signatory_phone'      => 'nullable|string|max:20',
                'primary_color'        => 'nullable|string|max:20',
                'secondary_color'      => 'nullable|string|max:20',
                'quotation_prefix'     => 'nullable|string|max:10',
                'invoice_prefix'       => 'nullable|string|max:10',
                'order_prefix'         => 'nullable|string|max:10',
                'challan_prefix'       => 'nullable|string|max:10',
                'financial_year_start' => 'nullable|integer|min:1|max:12',
                'e_invoice_applicable' => 'nullable|boolean',
                'tcs_applicable'       => 'nullable|boolean',
            ]);

            $company->update($validated);

            return $this->successResponse($this->present($company->fresh()), 'Company updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'COMPANY_UPDATE_ERROR', 400);
        }
    }

    /**
     * POST /company/logo — upload a logo image.
     */
    public function uploadLogo(Request $request)
    {
        try {
            $company = Company::findOrFail($request->user()->company_id);

            if (!$request->user()->is_company_admin && !$request->user()->is_super_admin) {
                return $this->errorResponse([], 'Only company admins can change the logo', 'FORBIDDEN', 403);
            }

            $request->validate([
                'logo' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            ]);

            // Delete old logo if present
            if ($company->logo && Storage::disk('public')->exists($company->logo)) {
                Storage::disk('public')->delete($company->logo);
            }

            $path = $request->file('logo')->store("logos/{$company->id}", 'public');
            $company->update(['logo' => $path]);

            return $this->successResponse($this->present($company->fresh()), 'Logo uploaded');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'LOGO_UPLOAD_ERROR', 400);
        }
    }

    private function present(Company $company): array
    {
        $data = $company->toArray();
        $data['logo_url'] = $company->logo ? Storage::disk('public')->url($company->logo) : null;
        return $data;
    }
}
