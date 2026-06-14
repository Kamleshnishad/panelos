<?php

namespace Tests\Traits;

use App\Models\User;

trait HasAuthTestHelpers
{
    protected function loginUser(?User $user = null): User
    {
        $user = $user ?? User::factory()->create();
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function loginSuperAdmin(): User
    {
        return $this->loginUser(User::factory()->superAdmin()->create());
    }

    protected function loginCompanyAdmin(): User
    {
        return $this->loginUser(User::factory()->companyAdmin()->create());
    }

    protected function getAuthToken(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    protected function authenticatedRequest($method, $uri, $data = []): mixed
    {
        $user = User::factory()->create();
        return $this->actingAs($user, 'sanctum')->json($method, $uri, $data);
    }
}
