<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $city = fake()->city();

        return [
            'name' => $city.' Operations Site',
            'code' => strtoupper(fake()->unique()->bothify('SITE-##??')),
            'business_type' => fake()->randomElement([
                'Hospital',
                'Clinic',
                'Data Center',
                'Branch Office',
            ]),
            'address' => fake()->address(),
            'timezone' => fake()->randomElement([
                'Asia/Makassar',
                'Asia/Jakarta',
                'Asia/Singapore',
            ]),
            'notes' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
