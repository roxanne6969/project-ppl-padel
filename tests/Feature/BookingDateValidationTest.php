<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookingDateValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_book_past_date(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $court = Court::factory()->create();

        $yesterday = now()->subDay()->toDateString();

        $response = $this->actingAs($user)->post(route('bookings.store'), [
            'court_id' => $court->id,
            'date' => $yesterday,
            'start_time' => '10:00',
            'duration_hours' => 1,
        ]);

        $response->assertSessionHasErrors('date');
    }

    public function test_user_can_book_today_or_future_date(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $court = Court::factory()->create();

        $future = now()->addDays(5)->toDateString();

        $response = $this->actingAs($user)->post(route('bookings.store'), [
            'court_id' => $court->id,
            'date' => $future,
            'start_time' => '14:00',
            'duration_hours' => 1,
            'payment_proof' => UploadedFile::fake()->image('proof.jpg'),
        ]);

        $response->assertSessionHasNoErrors();

        // Use Eloquent to avoid SQLite date-format discrepancies in assertDatabaseHas.
        $booking = Booking::where('user_id', $user->id)
            ->where('court_id', $court->id)
            ->whereDate('date', $future)
            ->first();

        $this->assertNotNull($booking, 'Booking record should exist for the future date.');
    }

    public function test_user_dashboard_clamps_past_date_to_today(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->withoutVite()
            ->actingAs($user)
            ->get(route('dashboard', ['date' => '2020-01-01']));

        $response->assertStatus(200);
        $response->assertViewHas('date', now()->toDateString());
        $response->assertSee('min="'.now()->toDateString().'"', false);
    }
}
