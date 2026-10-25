<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadMessageTemplate;
use App\Services\LeadMessageService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The company's message templates for contacting leads (LEADS_PLAN 5.3), under Settings: reads are open, changes need settings.manage.
 * Placeholders: see LeadMessageService::PLACEHOLDERS. Deleting is allowed (a template is only text); a company that deletes all of them does not get the starters back.
 */
class LeadMessageTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadMessageService $messages)
    {
    }

    private function cid(Request $r): int
    {
        return (int) $r->user()->company_id;
    }

    private function rules(): array
    {
        return ['channel' => 'required|in:whatsapp,email', 'name' => 'required|string|max:80', 'subject' => 'nullable|string|max:150', 'body' => 'required|string|max:5000', 'is_active' => 'sometimes|boolean'];
    }

    public function index(Request $r)
    {
        return $this->successResponse([
            'templates' => $this->messages->templates($this->cid($r)),
            'placeholders' => LeadMessageService::PLACEHOLDERS,
        ], 'Lead message templates');
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules());
        $this->messages->ensureDefaults($this->cid($r));
        $d['subject'] = $d['channel'] === 'email' ? ($d['subject'] ?? null) : null;
        $t = LeadMessageTemplate::create($d + ['company_id' => $this->cid($r), 'sort_order' => (int) LeadMessageTemplate::where('company_id', $this->cid($r))->max('sort_order') + 1]);

        return $this->createdResponse($t, 'Template added', 201);
    }

    public function update(Request $r, int $id)
    {
        $t = LeadMessageTemplate::where('company_id', $this->cid($r))->find($id);
        if (!$t) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
        $d = $r->validate(array_map(fn ($rule) => 'sometimes|' . $rule, $this->rules()));
        if (($d['channel'] ?? $t->channel) !== 'email') {
            $d['subject'] = null;
        }
        $t->update($d);

        return $this->successResponse($t->fresh(), 'Template saved');
    }

    public function destroy(Request $r, int $id)
    {
        $t = LeadMessageTemplate::where('company_id', $this->cid($r))->find($id);
        if (!$t) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
        $t->delete();

        return $this->noContentResponse('Template deleted');
    }
}
