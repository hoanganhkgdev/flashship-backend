<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\User;
use Tests\TestCase;

class CustomerProfileBirthDateTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::create([
            'name' => 'Customer',
            'email' => uniqid().'@test.local',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'customer',
            'status' => 1,
        ]);
    }

    public function test_birth_date_is_saved_and_returned(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);

        $this->patchJson('/api/customer/auth/profile', ['name' => 'Customer', 'birth_date' => '1995-03-07'])
            ->assertOk()
            ->assertJsonPath('data.birth_date', '1995-03-07');

        $this->assertSame('1995-03-07', $customer->fresh()->birth_date->format('Y-m-d'));
    }

    public function test_future_birth_date_is_rejected(): void
    {
        Sanctum::actingAs($this->customer());

        $this->patchJson('/api/customer/auth/profile', ['birth_date' => now()->addDay()->format('Y-m-d')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }
}
