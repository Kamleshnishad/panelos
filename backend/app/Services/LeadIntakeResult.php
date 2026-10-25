<?php

namespace App\Services;

/** What LeadIntakeService::ingest did with one incoming enquiry (LEADS_PLAN 2.2). Importers and the import log (2.3) read this. */
final class LeadIntakeResult
{
    /** A new lead was made. */
    public const CREATED = 'created';
    /** The buyer already has an open lead: a note was added to it ("enquired again"), no new lead. */
    public const MERGED = 'merged';
    /** This exact enquiry (same channel, same id) was handled before; nothing was changed. */
    public const ALREADY_RECEIVED = 'already_received';
    /** Not usable (no name, or no phone or e-mail to reach them). The raw message is kept; no lead was made. */
    public const REJECTED = 'rejected';

    /** @param list<string> $similar numbers of closed leads of the same buyer (a new lead was still made) */
    public function __construct(
        public readonly string $outcome,
        public readonly ?int $leadId = null,
        public readonly ?string $leadNo = null,
        public readonly ?string $reason = null,
        public readonly array $similar = [],
    ) {
    }

    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'lead_id' => $this->leadId, 'lead_no' => $this->leadNo, 'reason' => $this->reason, 'similar' => $this->similar];
    }
}
