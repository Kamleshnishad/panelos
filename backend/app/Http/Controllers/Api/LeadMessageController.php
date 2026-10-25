<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeadMessageService;
use App\Services\LeadService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Contacting a lead from its screen (LEADS_PLAN 5.3): the company's templates filled in for this lead, the WhatsApp link, and the e-mail.
 * Under the leads module: needs leads.manage (they write to the timeline and send mail); a person restricted to their own leads (leads.own_only) reaches only those.
 */
class LeadMessageController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadMessageService $messages, private LeadService $leads)
    {
    }

    private function lead(Request $r, int $id)
    {
        return $this->leads->visible((int) $r->user()->company_id, $r->user())->findOrFail($id);
    }

    private function run(callable $f)
    {
        try {
            return $f();
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    /** The company's active templates for a channel, each already filled in for this lead and this person. */
    public function templates(Request $r, int $id)
    {
        return $this->run(function () use ($r, $id) {
            $r->validate(['channel' => 'required|in:whatsapp,email']);
            $lead = $this->lead($r, $id);
            $list = $this->messages->templates($lead->company_id, $r->query('channel'), true)->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name,
                'subject' => $t->subject ? $this->messages->render($t->subject, $lead, $r->user()) : null,
                'body' => $this->messages->render($t->body, $lead, $r->user()),
            ])->values();

            return $this->successResponse([
                'templates' => $list, 'phone' => $lead->phone, 'email' => $lead->email,
                'whatsapp_ready' => LeadMessageService::waNumber($lead->phone) !== null,
                'email_ready' => (bool) \App\Support\CompanyMail::activeFor($lead->company_id, \App\Support\CompanyMail::kindFor($lead->company_id, 'lead_message')),
            ], 'Message templates');
        });
    }

    public function whatsapp(Request $r, int $id)
    {
        return $this->run(function () use ($r, $id) {
            $d = $r->validate(['message' => 'required|string|max:1500']);

            return $this->successResponse($this->messages->whatsapp($this->lead($r, $id), $r->user(), $d['message']), 'WhatsApp link');
        });
    }

    public function email(Request $r, int $id)
    {
        return $this->run(function () use ($r, $id) {
            $d = $r->validate(['subject' => 'required|string|max:150', 'body' => 'required|string|max:5000']);
            $this->messages->email($this->lead($r, $id), $r->user(), $d['subject'], $d['body']);

            return $this->successResponse(null, 'E-mail sent');
        });
    }
}
