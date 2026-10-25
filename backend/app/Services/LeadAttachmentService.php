<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files on a lead (LEADS_PLAN 5.7): a customer's drawings, site photos, BOQ sheets, documents.
 *
 *  - PRIVATE: files go on the `local` disk (storage/app/private), under a random name; nothing is reachable by address. They are read only through an
 *    authenticated download that looks the lead up like every other lead call (another company, or somebody else's lead for a person restricted to their own,
 *    is a plain 404).
 *  - ONLY KNOWN KINDS: pdf, jpg, png, webp, dwg, dxf, xls, xlsx, csv, doc, docx. The extension must be one of those AND the file's first bytes must look like it
 *    (a program renamed to .pdf is refused). Web pages, scripts and SVG are never accepted, so a file can never run in the browser.
 *  - LIMITS: 15 MB a file, 30 files a lead.
 *  - Every add and removal is a line on the lead's timeline. Deleting the lead deletes its files.
 * Not done: virus scanning; the files are not copied onto the quotation (that would change the quotation module, which this work leaves alone).
 */
class LeadAttachmentService
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const MAX_FILES = 30;
    public const DISK = 'local';

    /** extension => [default kind, content type to serve it as, may be shown in the browser] */
    public const TYPES = [
        'pdf'  => ['document', 'application/pdf', true],
        'jpg'  => ['photo', 'image/jpeg', true], 'jpeg' => ['photo', 'image/jpeg', true], 'png' => ['photo', 'image/png', true], 'webp' => ['photo', 'image/webp', true],
        'dwg'  => ['drawing', 'application/octet-stream', false], 'dxf' => ['drawing', 'application/octet-stream', false],
        'xls'  => ['boq', 'application/vnd.ms-excel', false], 'xlsx' => ['boq', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false], 'csv' => ['boq', 'text/csv', false],
        'doc'  => ['document', 'application/msword', false], 'docx' => ['document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
    ];

    /** @return list<array<string,mixed>> */
    public function list(Lead $lead): array
    {
        return LeadAttachment::where('lead_id', $lead->id)->with('uploader:id,name')->orderByDesc('id')->get()->map(fn ($a) => $this->row($a))->all();
    }

    public function row(LeadAttachment $a): array
    {
        return [
            'id' => $a->id, 'kind' => $a->kind, 'kind_label' => LeadAttachment::KINDS[$a->kind] ?? ucfirst($a->kind), 'name' => $a->original_name, 'extension' => $a->extension,
            'size_bytes' => $a->size_bytes, 'viewable' => self::TYPES[$a->extension][2] ?? false, 'uploaded_by' => $a->uploader?->name, 'uploaded_at' => $a->created_at,
        ];
    }

    public function add(Lead $lead, UploadedFile $file, ?User $actor, ?string $kind = null): LeadAttachment
    {
        if (!$file->isValid()) {
            $this->refuse('file', 'The file did not upload. Try again.');
        }
        if (LeadAttachment::where('lead_id', $lead->id)->count() >= self::MAX_FILES) {
            $this->refuse('file', 'A lead can keep ' . self::MAX_FILES . ' files. Remove one first.');
        }
        $name = $this->cleanName($file->getClientOriginalName());
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            $this->refuse('file', 'This kind of file is not accepted. Use ' . implode(', ', array_unique(array_map(fn ($e) => $e === 'jpeg' ? 'jpg' : $e, array_keys(self::TYPES)))) . '.');
        }
        $size = (int) $file->getSize();
        if ($size <= 0) {
            $this->refuse('file', 'The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            $this->refuse('file', 'The file is larger than ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4096);
        if (!self::looksLike($ext, $head)) {
            $this->refuse('file', "This does not look like a real .{$ext} file.");
        }
        $kind = array_key_exists((string) $kind, LeadAttachment::KINDS) ? $kind : self::TYPES[$ext][0];

        $path = Storage::disk(self::DISK)->putFileAs("lead-attachments/{$lead->company_id}/{$lead->id}", $file, Str::uuid() . '.' . $ext);
        $a = LeadAttachment::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'uploaded_by_user_id' => $actor?->id, 'kind' => $kind,
            'original_name' => $name, 'path' => $path, 'extension' => $ext, 'size_bytes' => $size]);
        $this->log($lead, $actor, "File added: {$name} (" . LeadAttachment::KINDS[$kind] . ', ' . self::human($size) . ')');

        return $a;
    }

    public function remove(LeadAttachment $a, ?User $actor): void
    {
        Storage::disk(self::DISK)->delete($a->path);
        $a->delete();
        if ($lead = Lead::withoutGlobalScope('tenant')->where('company_id', $a->company_id)->find($a->lead_id)) {
            $this->log($lead, $actor, "File removed: {$a->original_name}");
        }
    }

    /** The lead is being deleted: its files go with it. */
    public function purge(Lead $lead): void
    {
        foreach (LeadAttachment::where('lead_id', $lead->id)->get() as $a) {
            Storage::disk(self::DISK)->delete($a->path);
            $a->delete();
        }
    }

    /** @return array{0:string, 1:string, 2:bool} the path on the disk, the content type to serve, and whether a browser may show it in place */
    public function serving(LeadAttachment $a): array
    {
        return [$a->path, self::TYPES[$a->extension][1] ?? 'application/octet-stream', self::TYPES[$a->extension][2] ?? false];
    }

    /** Do the first bytes fit the claimed type? (Text types only need to be text.) */
    public static function looksLike(string $ext, string $head): bool
    {
        return match ($ext) {
            'pdf'          => str_contains(substr($head, 0, 1024), '%PDF-'),
            'jpg', 'jpeg'  => str_starts_with($head, "\xFF\xD8\xFF"),
            'png'          => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'webp'         => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            'xlsx', 'docx' => str_starts_with($head, "PK\x03\x04"),
            'xls', 'doc'   => str_starts_with($head, "\xD0\xCF\x11\xE0"),
            'dwg'          => (bool) preg_match('/^AC\d{4}/', $head),
            'dxf', 'csv'   => !str_contains($head, "\0"),
            default        => false,
        };
    }

    public static function human(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** What a person may see as the file's name: no folders, no control characters, no quotes, a sane length. */
    private function cleanName(string $n): string
    {
        $n = basename(str_replace('\\', '/', $n));
        $n = preg_replace('/[\x00-\x1F\x7F"<>|*?:]+/u', '', $n) ?? '';
        $n = trim($n);

        return $n === '' ? 'file' : mb_substr($n, -190);
    }

    private function log(Lead $lead, ?User $actor, string $text): void
    {
        LeadActivity::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => $actor?->id, 'type' => 'note', 'description' => $text, 'activity_date' => now()]);
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
