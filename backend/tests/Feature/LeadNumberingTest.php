<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/** LEADS_PLAN 1.6 (LD-07): two leads created at the same instant never fail. */
class LeadNumberingTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** A LeadService whose first number is stale, as when another request took it a moment earlier. */
    private function serviceWithStaleFirstNumber(string $stale): LeadService
    {
        return new class($stale) extends LeadService {
            public int $asked = 0;

            public function __construct(private string $stale)
            {
            }

            protected function generateLeadNumber(int $companyId): string
            {
                return ++$this->asked === 1 ? $this->stale : parent::generateLeadNumber($companyId);
            }
        };
    }

    public function test_a_number_taken_a_moment_earlier_is_retried_with_the_next_one(): void
    {
        $company = Company::factory()->create();
        $first = app(LeadService::class)->create($company->id, ['contact_name' => 'First']);
        $this->assertSame('0001', substr($first->lead_no, -4));

        $service = $this->serviceWithStaleFirstNumber($first->lead_no);   // the race: it read the same "last number" as the first request
        $second = $service->create($company->id, ['contact_name' => 'Second']);

        $this->assertSame('0002', substr($second->lead_no, -4));
        $this->assertSame(2, $service->asked, 'It asked for a number twice: the clash was retried.');
        $this->assertSame(2, Lead::where('company_id', $company->id)->count());
    }

    public function test_the_unique_number_is_still_enforced_so_a_clash_can_never_slip_through_silently(): void
    {
        $company = Company::factory()->create();
        $a = app(LeadService::class)->create($company->id, ['contact_name' => 'A']);

        $this->expectException(QueryException::class);
        Lead::create(['company_id' => $company->id, 'lead_no' => $a->lead_no, 'contact_name' => 'Clone', 'source' => 'Other', 'status' => 'new']);
    }

    public function test_a_clash_that_never_clears_still_ends_in_an_error_not_a_loop(): void
    {
        $company = Company::factory()->create();
        $taken = app(LeadService::class)->create($company->id, ['contact_name' => 'A'])->lead_no;
        $always = new class extends LeadService {
            public int $asked = 0;
            public string $no = '';

            protected function generateLeadNumber(int $companyId): string
            {
                $this->asked++;

                return $this->no;
            }
        };
        $always->no = $taken;

        try {
            $always->create($company->id, ['contact_name' => 'B']);
            $this->fail('A permanent clash must surface.');
        } catch (QueryException) {
            $this->assertSame(5, $always->asked);
        }
    }

    public function test_each_company_counts_on_its_own_and_a_deleted_leads_number_is_never_reused(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $svc = app(LeadService::class);

        $a1 = $svc->create($a->id, ['contact_name' => 'A1']);
        $b1 = $svc->create($b->id, ['contact_name' => 'B1']);
        $a1->delete();
        $a2 = $svc->create($a->id, ['contact_name' => 'A2']);

        $this->assertSame('0001', substr($b1->lead_no, -4));
        $this->assertSame('0002', substr($a2->lead_no, -4));
    }
}
