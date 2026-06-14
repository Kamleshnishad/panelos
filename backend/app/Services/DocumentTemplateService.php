<?php

namespace App\Services;

use App\Models\CompanyDocumentTemplate;
use Illuminate\Support\Facades\View;

/**
 * Resolves which Blade view to render for a company's chosen PDF template,
 * always falling back to the current default so PDFs never break.
 */
class DocumentTemplateService
{
    public function registry(): array
    {
        return config('document_templates', []);
    }

    public function templatesFor(string $docType): array
    {
        return $this->registry()[$docType] ?? [];
    }

    public function currentKey(int $companyId, string $docType): ?string
    {
        return CompanyDocumentTemplate::where('company_id', $companyId)
            ->where('doc_type', $docType)->value('template_key');
    }

    /**
     * The blade view to render. Uses the company's chosen template if it exists
     * in the registry AND the blade actually exists; otherwise the fallback
     * (the current default view) — so a missing/removed template can never
     * break PDF generation.
     */
    public function viewFor(int $companyId, string $docType, string $fallbackView): string
    {
        $key = $this->currentKey($companyId, $docType);
        if ($key) {
            $view = $this->templatesFor($docType)[$key]['view'] ?? null;
            if ($view && View::exists($view)) {
                return $view;
            }
        }
        return $fallbackView;
    }

    public function setTemplate(int $companyId, string $docType, string $key): void
    {
        if (!isset($this->templatesFor($docType)[$key])) {
            throw new \Exception("Unknown template '{$key}' for {$docType}.");
        }
        CompanyDocumentTemplate::updateOrCreate(
            ['company_id' => $companyId, 'doc_type' => $docType],
            ['template_key' => $key],
        );
    }

    /** Registry + current selection per doc type, for the settings screen. */
    public function listForCompany(int $companyId): array
    {
        $out = [];
        foreach ($this->registry() as $docType => $templates) {
            $current = $this->currentKey($companyId, $docType) ?? array_key_first($templates);
            $list = [];
            foreach ($templates as $key => $meta) {
                $list[] = [
                    'key'         => $key,
                    'name'        => $meta['name'] ?? $key,
                    'description' => $meta['description'] ?? '',
                    'is_current'  => $key === $current,
                ];
            }
            $out[] = ['doc_type' => $docType, 'current' => $current, 'templates' => $list];
        }
        return $out;
    }
}
