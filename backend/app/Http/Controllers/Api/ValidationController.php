<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ValidationController extends Controller
{
    /**
     * Whitelist of tables and the columns that may be checked for uniqueness
     * (defense-in-depth against probing arbitrary tables).
     */
    private const ALLOWED_COLUMNS = [
        'customers' => ['code', 'email', 'phone', 'gstin', 'pan'],
        'suppliers' => ['name', 'phone', 'gstin', 'email'],
        'users'     => ['email'],
        'panel_types' => ['code', 'name'],
        'accessories' => ['code', 'name'],
        'companies' => ['subdomain', 'email'],
    ];

    public function checkUnique(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $validated = $request->validate([
            'table'      => 'required|string|in:' . implode(',', array_keys(self::ALLOWED_COLUMNS)),
            'column'     => 'required|string|max:64',
            'value'      => 'required',
            'ignore_id'  => 'nullable|integer',
        ]);

        $table  = $validated['table'];
        $column = $validated['column'];

        // Confirm column is in the whitelist for this table
        if (!in_array($column, self::ALLOWED_COLUMNS[$table], true)) {
            return response()->json([
                'success' => false,
                'message' => "Column '{$column}' is not checkable for table '{$table}'.",
            ], 422);
        }

        $query = DB::table($table)
            ->where('company_id', $companyId)
            ->where($column, $validated['value']);

        if (!empty($validated['ignore_id'])) {
            $query->where('id', '!=', $validated['ignore_id']);
        }

        $exists = $query->exists();

        return response()->json([
            'success'   => true,
            'available' => !$exists,
        ]);
    }
}