<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ApiResponse;

    public function index(Request $r)
    {
        $u = $r->user();
        if (!$u->is_company_admin && !$u->is_super_admin) {
            return $this->errorResponse([], 'Only admins can view the audit log.', 'FORBIDDEN', 403);
        }

        $q = AuditLog::where('company_id', $u->company_id)->with('user:id,name');

        if ($r->filled('type'))    $q->where('auditable_type', $r->input('type'));
        if ($r->filled('action'))  $q->where('action', $r->input('action'));
        if ($r->filled('user_id')) $q->where('user_id', $r->input('user_id'));
        if ($r->filled('from'))    $q->whereDate('created_at', '>=', $r->input('from'));
        if ($r->filled('to'))      $q->whereDate('created_at', '<=', $r->input('to'));
        if ($r->filled('search')) {
            $s = $r->input('search');
            $q->where(fn ($w) => $w->where('label', 'like', "%{$s}%")->orWhere('user_name', 'like', "%{$s}%"));
        }

        $page = $q->orderByDesc('id')->paginate($r->input('per_page', 40));

        // distinct entity types for the filter dropdown
        $types = AuditLog::where('company_id', $u->company_id)->distinct()->orderBy('auditable_type')->pluck('auditable_type');

        return $this->successResponse(['logs' => $page, 'types' => $types], 'Audit log retrieved');
    }
}
