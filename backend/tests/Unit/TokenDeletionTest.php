<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Traits\HasDatabaseTestHelpers;

class TokenDeletionTest extends TestCase
{
    use HasDatabaseTestHelpers;

    public function test_token_can_be_deleted_directly()
    {
        $user = $this->createAdminUser();
        $tokenResult = $user->createToken('test-token');

        // Token should exist
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);

        // Delete all tokens
        $user->tokens()->delete();

        // Token should not exist
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_current_access_token_works()
    {
        $user = $this->createAdminUser();
        $tokenResult = $user->createToken('test-token');
        $token = $tokenResult->plainTextToken;

        // Make a request with the token
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->getJson('/api/auth/me');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => ['id' => $user->id],
        ]);
    }
}
