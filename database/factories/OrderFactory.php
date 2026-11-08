<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'GS-'.Str::ulid(),
            'customer_id' => Customer::factory(),
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Unpaid,
            'total_amount' => '0.00',
            'customer_name_snapshot' => fake()->name(),
            'customer_email_snapshot' => fake()->safeEmail(),
            'customer_phone_snapshot' => '0123456789',
            'customer_address_snapshot' => fake()->address(),
            'creation_token' => (string) Str::uuid(),
            'lock_version' => 0,
        ];
    }
}
