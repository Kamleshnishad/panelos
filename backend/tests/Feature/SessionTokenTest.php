<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Session handling. Reproduced on production before this was fixed:
 *
 *  - refresh-token DELETED the old token, so a second browser tab still holding
 *    it got 401 at once, and 2 of 40 simulated page loads lost a request to it
 *    (AppShell refreshes while the screen you opened is already fetching). The
 *    browser treats every 401 as "session expired" and signs you out.
 *  - changing your password with a wrong current password returned 401, which
 *    did exactly the same.
 *
 * These use real bearer tokens through the real Sanctum guard (not actingAs,
 * which bypasses it), so the token lifecycle is what is actually under test.
 */
class SessionTokenTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function userWithPassword(string $password = 'Testing12345'): User
    {
        $admin = $this->createAdminUser();
        $admin->forceFill(['password' => Hash::make($password), 'is_active' => true])->save();
        return $admin;
    }

    /**
     * Sanctum's request guard caches the resolved user for the life of the app
     * instance, so two requests with different tokens inside one test would both
     * authenticate as the first. Forget it between calls.
     */
    private function as(string $token)
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($token);
    }

    private function refresh(string $token): array
    {
        $res = $this->as($token)->postJson('/api/auth/refresh-token')->assertStatus(200);
        return $res->json('data');
    }

    public function test_login_says_how_long_the_session_lasts(): void
    {
        $user = $this->userWithPassword();

        $res = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Testing12345'])
            ->assertStatus(200);

        // The client schedules its warning from this duration, not from a server
        // timestamp, so a wrong clock on the user's machine cannot break it.
        $this->assertSame(config('sanctum.expiration') * 60, $res->json('data.expires_in'));
    }

    public function test_refresh_issues_a_new_token_and_reports_its_lifetime(): void
    {
        $user = $this->userWithPassword();
        $old = $user->createToken('auth_token')->plainTextToken;

        $data = $this->refresh($old);

        $this->assertNotEmpty($data['token']);
        $this->assertNotSame($old, $data['token']);
        $this->assertSame(config('sanctum.expiration') * 60, $data['expires_in']);
    }

    /** The bug: the old token used to be dead the instant a refresh happened. */
    public function test_the_old_token_survives_a_refresh_for_a_short_grace_window(): void
    {
        $user = $this->userWithPassword();
        $old = $user->createToken('auth_token')->plainTextToken;

        $new = $this->refresh($old)['token'];

        // A second tab, or a request already in flight, still holds the old one.
        $this->as($old)->getJson('/api/company')->assertStatus(200);
        $this->as($new)->getJson('/api/company')->assertStatus(200);
    }

    public function test_the_old_token_stops_working_once_the_grace_window_has_passed(): void
    {
        $user = $this->userWithPassword();
        $old = $user->createToken('auth_token')->plainTextToken;
        $new = $this->refresh($old)['token'];

        $this->travel(3)->minutes();   // grace is 90 seconds

        $this->as($old)->getJson('/api/company')->assertStatus(401);
        $this->as($new)->getJson('/api/company')->assertStatus(200);
    }

    /** Two tabs refreshing at once must not knock each other out. */
    public function test_two_tabs_can_refresh_the_same_token(): void
    {
        $user = $this->userWithPassword();
        $shared = $user->createToken('auth_token')->plainTextToken;

        $tabA = $this->refresh($shared)['token'];
        $tabB = $this->refresh($shared)['token'];   // still inside the grace window

        $this->as($tabA)->getJson('/api/company')->assertStatus(200);
        $this->as($tabB)->getJson('/api/company')->assertStatus(200);
    }

    /** A chain of refreshes must not be able to keep an old credential alive. */
    public function test_refreshing_again_does_not_extend_the_old_tokens_cutoff(): void
    {
        $user = $this->userWithPassword();
        $old = $user->createToken('auth_token')->plainTextToken;

        $this->refresh($old);
        $first = $user->tokens()->orderBy('id')->first()->fresh()->expires_at;

        $this->travel(30)->seconds();
        $this->refresh($old);
        $second = $user->tokens()->orderBy('id')->first()->fresh()->expires_at;

        $this->assertTrue($second->lte($first), 'The grace cutoff moved later on a second refresh.');
    }

    /**
     * The contract the browser relies on: only a dead token returns 401 with
     * UNAUTHENTICATED, and that is the one thing it treats as an expired session.
     */
    public function test_an_expired_token_is_reported_as_unauthenticated(): void
    {
        $user = $this->userWithPassword();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->travel(config('sanctum.expiration') + 60)->minutes();

        $this->as($token)->getJson('/api/company')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /** Was 401 — which the browser read as "session expired" and signed you out. */
    public function test_a_wrong_current_password_is_not_a_401(): void
    {
        $user = $this->userWithPassword();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->as($token)->postJson('/api/auth/change-password', [
            'current_password'          => 'not-my-password',
            'new_password'              => 'Brandnew12345',
            'new_password_confirmation' => 'Brandnew12345',
        ])->assertStatus(422)->assertJsonPath('error_code', 'PASSWORD_MISMATCH');

        // ...and the session is untouched.
        $this->as($token)->getJson('/api/company')->assertStatus(200);
    }
}
