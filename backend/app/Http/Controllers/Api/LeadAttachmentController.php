<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadAttachment;
use App\Services\LeadAttachmentService;
use App\Services\LeadService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Files on a lead (LEADS_PLAN 5.7). Under the leads module: listing and downloading need leads.view, adding and removing need leads.manage. Every call finds
 * the lead the ordinary way, so another company's file, or someone else's lead for a person restricted to their own (leads.own_only), is a plain 404.
 */
class LeadAttachmentController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadAttachmentService $files, private LeadService $leads)
    {
    }

    private function cid(Request $r): int
    {
        return (int) $r->user()->company_id;
    }

    private function lead(Request $r, int $id)
    {
        return $this->leads->visible($this->cid($r), $r->user())->findOrFail($id);
    }

    /** A file, found through its lead so the lead's own visibility rule applies. */
    private function file(Request $r, int $id): LeadAttachment
    {
        $a = LeadAttachment::where('company_id', $this->cid($r))->findOrFail($id);
        $this->lead($r, $a->lead_id);

        return $a;
    }

    public function index(Request $r, int $id)
    {
        try {
            return $this->successResponse([
                'files' => $this->files->list($this->lead($r, $id)), 'kinds' => LeadAttachment::KINDS,
                'max_mb' => LeadAttachmentService::MAX_BYTES / 1024 / 1024, 'max_files' => LeadAttachmentService::MAX_FILES,
                'accept' => '.' . implode(',.', array_keys(LeadAttachmentService::TYPES)),
            ], 'Files on the lead');
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    public function store(Request $r, int $id)
    {
        try {
            $r->validate(['file' => 'required|file', 'kind' => 'nullable|string|max:12']);
            $a = $this->files->add($this->lead($r, $id), $r->file('file'), $r->user(), $r->input('kind'));

            return $this->createdResponse($this->files->row($a->load('uploader:id,name')), 'File added', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    /** The file itself. `?inline=1` lets the browser show a PDF or picture in place; everything else is always a download. */
    public function download(Request $r, int $id)
    {
        try {
            $a = $this->file($r, $id);
            [$path, $type, $viewable] = $this->files->serving($a);
            if (!Storage::disk(LeadAttachmentService::DISK)->exists($path)) {
                return $this->errorResponse([], 'The file is no longer on the server.', 'FILE_MISSING', 404);
            }

            return Storage::disk(LeadAttachmentService::DISK)->response($path, $a->original_name, [
                'Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            ], $r->boolean('inline') && $viewable ? 'inline' : 'attachment');
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
    }

    public function destroy(Request $r, int $id)
    {
        try {
            $this->files->remove($this->file($r, $id), $r->user());

            return $this->noContentResponse('File removed');
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Not found', 'NOT_FOUND', 404);
        }
    }
}
