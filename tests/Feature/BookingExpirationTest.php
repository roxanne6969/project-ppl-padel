<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookingExpirationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_USER]);
    }

    private function makeCourt(): Court
    {
        return Court::factory()->create();
    }

    public function test_expired_pending_booking_is_cancelled(): void
    {
        $court = $this->makeCourt();
        $user = $this->makeUser();

        // Create a pending booking from 25 hours ago
        $booking = Booking::factory()->for($court)->for($user)->create([
            'status' => Booking::STATUS_PENDING,
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ]);

        $this->artisan('bookings:expire', ['--hours' => 24])
            ->assertSuccessful();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
    }

    public function test_recent_pending_booking_is_not_expired(): void
    {
        $court = $this->makeCourt();
        $user = $this->makeUser();

        // Create a pending booking from 2 hours ago
        $booking = Booking::factory()->for($court)->for($user)->create([
            'status' => Booking::STATUS_PENDING,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('bookings:expire', ['--hours' => 24])
            ->assertSuccessful();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_PENDING, $booking->status);
    }

    public function test_confirmed_booking_is_never_expired(): void
    {
        $court = $this->makeCourt();
        $user = $this->makeUser();

        // Create a confirmed booking from 30 hours ago
        $booking = Booking::factory()->for($court)->for($user)->confirmed()->create([
            'created_at' => now()->subHours(30),
            'updated_at' => now()->subHours(30),
        ]);

        $this->artisan('bookings:expire', ['--hours' => 24])
            ->assertSuccessful();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
    }

    public function test_cancelled_slot_becomes_available_again_for_new_customer(): void
    {
        Storage::fake('public');

        $court = $this->makeCourt();
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $date = now()->addDays(3)->toDateString();

        // User A created a booking that timed out (pending > 24h ago)
        $bookingA = Booking::factory()
            ->forSlot($court->id, $date, 15, 1)
            ->for($userA)
            ->create([
                'status' => Booking::STATUS_PENDING,
                'created_at' => now()->subHours(25),
                'updated_at' => now()->subHours(25),
            ]);

        // Run expiration command
        $this->artisan('bookings:expire', ['--hours' => 24])->assertSuccessful();

        $bookingA->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $bookingA->status);

        // User B books the exact same slot
        $response = $this->actingAs($userB)->post(route('bookings.store'), [
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '15:00',
            'duration_hours' => 1,
            'payment_proof' => UploadedFile::fake()->image('proof.jpg'),
        ]);

        $response->assertSessionHasNoErrors();

        $userBBooking = Booking::query()
            ->where('court_id', $court->id)
            ->whereDate('date', $date)
            ->where('start_time', '15:00:00')
            ->where('user_id', $userB->id)
            ->where('status', Booking::STATUS_PENDING)
            ->first();

        $this->assertNotNull($userBBooking, 'User B should have successfully booked the released slot.');
    }

    public function test_expiration_is_idempotent(): void
    {
        $court = $this->makeCourt();
        $user = $this->makeUser();

        $booking = Booking::factory()->for($court)->for($user)->create([
            'status' => Booking::STATUS_PENDING,
            'created_at' => now()->subHours(26),
            'updated_at' => now()->subHours(26),
        ]);

        // First run
        $this->artisan('bookings:expire', ['--hours' => 24])->assertSuccessful();
        $booking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);

        // Second run immediately after
        $this->artisan('bookings:expire', ['--hours' => 24])->assertSuccessful();
        $booking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $court = $this->makeCourt();
        $user = $this->makeUser();

        $booking = Booking::factory()->for($court)->for($user)->create([
            'status' => Booking::STATUS_PENDING,
            'created_at' => now()->subHours(30),
            'updated_at' => now()->subHours(30),
        ]);

        $this->artisan('bookings:expire', ['--hours' => 24, '--dry-run' => true])
            ->assertSuccessful();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_PENDING, $booking->status);
    }
}
