<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use Tests\TestCase;

/**
 * PlatformSetting::current() used to be firstOrCreate(['id' => 1]) with a non-fillable id. When row 1 did not exist
 * (auto-increment already past it) every call created another blank row and returned it, so a saved Razorpay secret
 * was never found again and webhooks were rejected.
 */
class PlatformSettingTest extends TestCase
{
    public function test_there_is_one_settings_row_and_every_call_returns_it(): void
    {
        $first = PlatformSetting::current();
        $first->update(['razorpay_webhook_secret' => 'whsec_x']);

        $this->assertSame($first->id, PlatformSetting::current()->id);
        $this->assertSame('whsec_x', PlatformSetting::current()->rzpWebhookSecret());
        $this->assertSame(1, PlatformSetting::count());
    }

    public function test_it_still_works_when_the_first_row_is_long_gone(): void
    {
        // Auto-increment has moved on: the original row was deleted and the table now starts at a higher id.
        PlatformSetting::current()->delete();
        PlatformSetting::current()->update(['razorpay_webhook_secret' => 'whsec_later']);
        $id = PlatformSetting::current()->id;
        $this->assertGreaterThan(1, $id);

        $this->assertSame($id, PlatformSetting::current()->id, 'A second call must not create another row.');
        $this->assertSame('whsec_later', PlatformSetting::current()->rzpWebhookSecret());
        $this->assertSame(1, PlatformSetting::count());
    }
}
