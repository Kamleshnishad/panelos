<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\HasAuthTestHelpers;
use Tests\Traits\HasDatabaseTestHelpers;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;
    use HasAuthTestHelpers;
    use HasDatabaseTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
    }

    // ============ LOGIN TESTS ============

    public function test_login_with_valid_credentials()
    {
        $user = $this->createAdminUser([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'company_id',
                        'is_super_admin',
                        'is_company_admin',
                    ],
                    'token',
                    'token_type',
                ],
                'message',
                'meta',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'test@example.com',
                        'is_super_admin' => true,
                    ],
                    'token_type' => 'Bearer',
                ],
                'message' => 'Login successful',
            ]);

        $this->assertNotNull($response->json('data.token'));
    }

    public function test_login_with_invalid_email()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid email or password',
                'error_code' => 'INVALID_CREDENTIALS',
            ]);
    }

    public function test_login_with_wrong_password()
    {
        $this->createAdminUser([
            'email' => 'test@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error_code' => 'INVALID_CREDENTIALS',
            ]);
    }

    public function test_login_with_missing_email()
    {
        $response = $this->postJson('/api/auth/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
            ])
            ->assertJsonPath('errors.email', ['The email field is required.']);
    }

    public function test_login_with_missing_password()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
            ])
            ->assertJsonPath('errors.password', ['The password field is required.']);
    }

    public function test_login_updates_last_login_at()
    {
        $user = $this->createAdminUser([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $beforeLogin = $user->last_login_at;

        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotEquals($beforeLogin, $user->last_login_at);
    }

    // ============ GET CURRENT USER TESTS ============

    public function test_get_current_user()
    {
        $user = $this->loginUser($this->createAdminUser());

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'company_id',
                    'is_super_admin',
                    'is_company_admin',
                    'is_active',
                    'last_login_at',
                ],
                'message',
                'meta',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                ],
                'message' => 'Current user retrieved',
            ]);
    }

    public function test_get_current_user_without_auth()
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_get_current_user_with_invalid_token()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
        ])->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    // ============ REFRESH TOKEN TESTS ============

    public function test_refresh_token()
    {
        $user = $this->loginUser($this->createAdminUser());

        $response = $this->postJson('/api/auth/refresh-token');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'token_type',
                ],
                'message',
                'meta',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'token_type' => 'Bearer',
                ],
                'message' => 'Token refreshed successfully',
            ]);

        $this->assertNotNull($response->json('data.token'));
    }

    public function test_refresh_token_without_auth()
    {
        $response = $this->postJson('/api/auth/refresh-token');

        $response->assertStatus(401);
    }

    public function test_old_token_invalid_after_refresh()
    {
        $user = $this->createAdminUser();
        $this->actingAs($user, 'sanctum');

        $oldTokenResponse = $this->postJson('/api/auth/refresh-token');
        $oldToken = $oldTokenResponse->json('data.token');

        $newToken = $oldTokenResponse->json('data.token');
        $this->withHeaders([
            'Authorization' => "Bearer $newToken",
        ])->getJson('/api/auth/me')->assertStatus(200);
    }

    // ============ CHANGE PASSWORD TESTS ============

    public function test_change_password_with_valid_current_password()
    {
        $user = $this->createAdminUser([
            'password' => bcrypt('old-password'),
        ]);
        $this->loginUser($user);

        $response = $this->postJson('/api/auth/change-password', [
            'current_password' => 'old-password',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password changed successfully',
            ]);

        $user->refresh();
        $this->assertTrue(password_verify('new-password-123', $user->password));
    }

    public function test_change_password_with_wrong_current_password()
    {
        $user = $this->createAdminUser([
            'password' => bcrypt('correct-password'),
        ]);
        $this->loginUser($user);

        $response = $this->postJson('/api/auth/change-password', [
            'current_password' => 'wrong-password',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error_code' => 'PASSWORD_MISMATCH',
            ]);
    }

    public function test_change_password_confirmation_mismatch()
    {
        $user = $this->createAdminUser([
            'password' => bcrypt('old-password'),
        ]);
        $this->loginUser($user);

        $response = $this->postJson('/api/auth/change-password', [
            'current_password' => 'old-password',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'different-password',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
            ]);
    }

    public function test_change_password_without_auth()
    {
        $response = $this->postJson('/api/auth/change-password', [
            'current_password' => 'old-password',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(401);
    }

    public function test_change_password_with_missing_fields()
    {
        $this->loginUser($this->createAdminUser());

        $response = $this->postJson('/api/auth/change-password', [
            'current_password' => 'old-password',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ============ LOGOUT TESTS ============

    public function test_logout()
    {
        $user = $this->createAdminUser();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logout successful',
            ]);
    }

    public function test_logout_without_auth()
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401);
    }

    public function test_logout_with_token()
    {
        $user = $this->createAdminUser();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->getJson('/api/auth/me')->assertStatus(200);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson('/api/auth/logout')->assertStatus(200);
    }

    // ============ MULTI-TENANT TESTS ============

    public function test_user_can_only_access_own_company_data()
    {
        $company1 = Company::factory()->create();
        $company2 = Company::factory()->create();

        $user1 = $this->createTestUser(['company_id' => $company1->id]);
        $user2 = $this->createTestUser(['company_id' => $company2->id]);

        $this->actingAs($user1, 'sanctum');
        $response = $this->getJson('/api/auth/me');

        $this->assertEquals($company1->id, $response->json('data.company_id'));
        $this->assertEquals($user1->id, $response->json('data.id'));
    }
}
