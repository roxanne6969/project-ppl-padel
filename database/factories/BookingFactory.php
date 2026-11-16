<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $startHour = fake()->numberBetween(8, 20);
        $endHour = $startHour + 1;

        return [
            'user_id' => User::factory(),
            'court_id' => Court::factory(),
            'customer_name' => null,
            'date' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'start_time' => str_pad($startHour, 2, '0', STR_PAD_LEFT).':00:00',
            'end_time' => str_pad($endHour, 2, '0', STR_PAD_LEFT).':00:00',
            'duration_hours' => 1,
            'status' => Booking::STATUS_PENDING,
            'price_per_hour' => 300000,
            'total_price' => 300000,
            'payment_proof' => null,
            'notes' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Booking::STATUS_CANCELLED,
        ]);
    }

    /**
     * Place the booking at a specific court, date, and hour window.
     */
    public function forSlot(int $courtId, string $date, int $startHour, int $durationHours = 1): static
    {
        $endHour = $startHour + $durationHours;

        return $this->state(fn (array $attributes) => [
            'court_id' => $courtId,
            'date' => $date,
            'start_time' => str_pad($startHour, 2, '0', STR_PAD_LEFT).':00:00',
            'end_time' => str_pad($endHour, 2, '0', STR_PAD_LEFT).':00:00',
            'duration_hours' => $durationHours,
            'total_price' => $durationHours * 300000,
        ]);
    }
}
