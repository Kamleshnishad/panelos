<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeadInboundService;
use Illuminate\Http\Request;

/**
 * POST /api/inbound/leads/{token}: the public door for enquiries from outside (LEADS_PLAN 2.3). No login: the token is the credential and
 * the only thing that decides which company the lead belongs to. All rules live in LeadInboundService.
 */
class InboundLeadController extends Controller
{
    public function __construct(private LeadInboundService $inbound)
    {
    }

    public function store(Request $r, string $token)
    {
        $input = $r->isJson() ? $r->json()->all() : $r->post();
        $result = $this->inbound->receive($token, is_array($input) ? $input : [], strlen($r->getContent()), $r->ip(), $r->headers->get('Origin'));

        return response()->json($result['body'], $result['status']);
    }
}
