<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use Tests\TestCase;

class SmsReminderCommandTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_command_does_not_abort_when_platform_sms_is_unset(): void
    {
        // Previously the command returned 1 whenever the global SMS service was
        // disabled, skipping every tenant. Per-company resolution must not do that.
        $company = Company::factory()->create();
        Invoice::create([
            'company_id' => $company->id,
            'invoice_no' => 'INV-SMS-' . uniqid(),
            'status' => 'sent',
            'subtotal' => 500,
            'tax_amount' => 0,
            'total_amount' => 500,
            'invoice_date' => now()->subDays(30),
            'due_date' => now()->subDays(20),
        ]);

        $this->artisan('sms:send-reminders', ['--days_overdue' => 7])
            ->assertExitCode(0);
    }
}
