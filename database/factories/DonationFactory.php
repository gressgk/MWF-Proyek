<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::inRandomOrder()->value('id'),
            'user_id' => User::where('role', 'donatur')->inRandomOrder()->value('id'),
            'jenis' => 'uang',
            'is_anonim' => fake()->boolean(20),
            'nominal' => fake()->numberBetween(5, 50) * 10000,
            'status' => 'terverifikasi',
        ];
    }
}
