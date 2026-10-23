<?php

namespace Tests\Feature;

use App\Models\Lead;
use Tests\TestCase;

class LeadInputHandlingTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** `website` used to 422 while `Website` passed — a needless trap for API callers. */
    public function test_source_is_matched_case_insensitively(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/leads', ['contact_name' => 'TEST Lead', 'source' => 'website'])
            ->assertStatus(201);

        $this->assertSame('Website', Lead::where('company_id', $admin->company_id)->firstOrFail()->source);
    }

    /** Other modules return the first field error; leads returned a bare "Validation failed". */
    public function test_validation_message_names_the_actual_problem(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/leads', ['contact_name' => 'TEST Lead', 'source' => 'Carrier pigeon'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected source is invalid.');
    }
}
