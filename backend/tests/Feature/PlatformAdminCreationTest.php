<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * is_super_admin is deliberately excluded from User::$fillable so only
 * forceFill() (per the comment on that property) can set it — but
 * SuperAdminController::createPlatformAdmin was passing it through plain
 * User::create() mass-assignment instead, so Eloquent silently dropped it.
 * Every "platform admin" created through that endpoint was actually just a
 * normal company-admin user. Found while bootstrapping the very first real
 * super admin for this app (no working one existed anywhere).
 */
class PlatformAdminCreationTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_create_platform_admin_actually_grants_super_admin(): void
    {
        $superAdmin = $this->createAdminUser();

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/admin/platform-admins', [
            'name' => 'New Admin',
            'email' => 'newadmin' . uniqid() . '@example.com',
            'password' => 'StrongPass123!',
        ]);

        $response->assertStatus(201);
        $created = User::withoutGlobalScope('tenant')->findOrFail($response->json('data.id'));
        $this->assertTrue($created->is_super_admin);

        // The listing endpoint filters on the real is_super_admin column —
        // before the fix, a created admin would never show up here either.
        $list = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/admin/platform-admins');
        $list->assertStatus(200);
        $this->assertContains($created->id, collect($list->json('data'))->pluck('id')->all());
    }

    public function test_bootstrap_command_creates_a_working_super_admin_idempotently(): void
    {
        $email = 'bootstrap' . uniqid() . '@example.com';

        Artisan::call('admin:bootstrap', ['--email' => $email, '--password' => 'FirstPass123!']);
        $admin = User::withoutGlobalScope('tenant')->where('email', $email)->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->is_super_admin);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('FirstPass123!', $admin->password));

        $company = Company::where('subdomain', 'platform-admin')->first();
        $this->assertNotNull($company);

        // Re-running must update in place, not duplicate.
        Artisan::call('admin:bootstrap', ['--email' => $email, '--password' => 'SecondPass456!']);
        $this->assertSame(1, User::withoutGlobalScope('tenant')->where('email', $email)->count());
        $fresh = $admin->fresh();
        $this->assertTrue($fresh->is_super_admin);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('SecondPass456!', $fresh->password));
    }
}
