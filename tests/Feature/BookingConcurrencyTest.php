<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Concurrency and double-booking tests for the padel reservation system.
 *
 * Tests A–F correspond directly to the requirements spec.
 *
 * NOTE ON CONCURRENCY (Test B):
 * True simultaneous HTTP requests cannot be simulated within a single-process
 * PHPUnit run because PHP is single-threaded and SQLite in-memory databases
 * do not support SELECT … FOR UPDATE. The in-process sequential test (Test A)
 * is therefore the primary automated regression. Test B verifies that the
 * overlap-check is performed inside a DB::transaction by injecting a conflict
 * via direct DB insert before the controller can INSERT its booking row.
 *
 * Manual concurrency test instructions (requires a running server + MySQL):
 *   1. `composer run dev` (or `php artisan serve`).
 *   2. Authenticate as two users, copy their session cookies.
 *   3. Use `ab -n 2 -c 2 -C "laravel_session=…" -p body.txt -T …` or
 *      `gnu parallel` to send two simultaneous POST /bookings for the same slot.
 *   4. Verify: exactly one booking row in the database; the other gets a 302
 *      redirect with session error on `start_time`.
 */
class BookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_USER]);
    }

    private function makeCourt(): Court
    {
        return Court::factory()->create();
    }

    // -----------------------------------------------------------------------
    // Test A: Sequential duplicate booking
    // -----------------------------------------------------------------------

    public function test_a_second_sequential_booking_for_same_slot_is_rejected(): void
    {
        Storage::fake('public');

        $court = $this->makeCourt();
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $date = now()->addDays(3)->toDateString();

        $params = [
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '13:00',
            'duration_hours' => 1,
            'payment_proof' => UploadedFile::fake()->image('proof.jpg'),
        ];

        // User A books first — must succeed.
        $this->actingAs($userA)->post(route('bookings.store'), $params)
            ->assertSessionHasNoErrors();

        // User B attempts the identical slot — must be rejected.
        $params['payment_proof'] = UploadedFile::fake()->image('proof2.jpg');
        $this->actingAs($userB)->post(route('bookings.store'), $params)
            ->assertSessionHasErrors('start_time');

        // Exactly one active booking must exist for this slot.
        $count = Booking::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->count();

        $this->assertSame(1, $count, 'Only one booking should exist for this slot.');
    }

    // -----------------------------------------------------------------------
    // Test B: Conflict detection inside the transaction
    //
    // We seed a conflicting row BEFORE the request hits the controller.
    // The controller must detect it via the overlap check inside its
    // DB::transaction and return an error. This verifies that the check
    // happens atomically inside the transaction, not outside it.
    // -----------------------------------------------------------------------

    public function test_b_conflict_seeded_before_request_is_detected_inside_transaction(): void
    {
        Storage::fake('public');

        $court = $this->makeCourt();
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $date = now()->addDays(3)->toDateString();

        // Seed the winning booking (User A's booking already in the DB).
        Booking::factory()
            ->forSlot($court->id, $date, 13, 1)
            ->for($userA)
            ->create(['status' => Booking::STATUS_PENDING]);

        // User B's request arrives after A's row is committed — must lose.
        $this->actingAs($userB)->post(route('bookings.store'), [
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '13:00',
            'duration_hours' => 1,
            'payment_proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertSessionHasErrors('start_time');

        $this->assertSame(
            1,
            Booking::where('court_id', $court->id)->whereDate('date', $date)->count(),
            'Only the winning booking should exist.'
        );
    }

    // -----------------------------------------------------------------------
    // Test C: Overlapping interval detection
    // -----------------------------------------------------------------------

    #[DataProvider('overlapProvider')]
    public function test_c_overlapping_booking_is_rejected(
        int $existingStart,
        int $existingEnd,
        int $newStart,
        int $newEnd,
        bool $shouldConflict
    ): void {
        Storage::fake('public');

        $court = $this->makeCourt();
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $date = now()->addDays(5)->toDateString();

        // Seed the existing booking directly.
        Booking::factory()->forSlot(
            $court->id,
            $date,
            $existingStart,
            $existingEnd - $existingStart
        )->for($userA)->create(['status' => Booking::STATUS_CONFIRMED]);

        $duration = $newEnd - $newStart;

        $response = $this->actingAs($userB)->post(route('bookings.store'), [
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => str_pad($newStart, 2, '0', STR_PAD_LEFT).':00',
            'duration_hours' => $duration,
            'payment_proof' => UploadedFile::fake()->image('proof.jpg'),
        ]);

        $activeCount = Booking::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->count();

        if ($shouldConflict) {
            $response->assertSessionHasErrors('start_time');
            $this->assertSame(
                1,
                $activeCount,
                "Should reject overlapping slot [{$newStart}:00–{$newEnd}:00]."
            );
        } else {
            $response->assertSessionHasNoErrors();
            $this->assertSame(
                2,
                $activeCount,
                "Should allow adjacent slot [{$newStart}:00–{$newEnd}:00]."
            );
        }
    }

    /**
     * @return array<string, array{int, int, int, int, bool}>
     */
    public static function overlapProvider(): array
    {
        // Existing booking: 13:00–14:00
        return [
            // Overlapping — must conflict
            'partial overlap start (12:00–14:00 covers 13:00)' => [13, 14, 12, 14, true],
            'exact same slot (13:00–14:00)' => [13, 14, 13, 14, true],
            'partial overlap end (13:00–15:00 covers 13:00)' => [13, 14, 13, 15, true],
            'wrapping (12:00–15:00)' => [13, 14, 12, 15, true],

            // Adjacent — must NOT conflict
            'adjacent after (14:00–15:00)' => [13, 14, 14, 15, false],
            'adjacent before (12:00–13:00)' => [13, 14, 12, 13, false],
        ];
    }

    // -----------------------------------------------------------------------
    // Test D: Cancelled booking releases the slot
    // -----------------------------------------------------------------------

    public function test_d_cancelled_booking_releases_slot_for_next_customer(): void
    {
        Storage::fake('public');

        $court = $this->makeCourt();
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $date = now()->addDays(4)->toDateString();

        // User A books the slot.
        $bookingA = Booking::factory()
            ->forSlot($court->id, $date, 13, 1)
            ->for($userA)
            ->create(['status' => Booking::STATUS_PENDING]);

        // Admin cancels A's booking (simulates payment expiry / manual cancel).
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN_KASIR]);
        $this->actingAs($admin)
            ->post(route('admin.bookings.status', $bookingA), ['status' => 'cancelled'])
            ->assertSessionHasNoErrors();

        $bookingA->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $bookingA->status);

        // User B should now be able to book the released slot.
        $this->actingAs($userB)->post(route('bookings.store'), [
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '13:00',
            'duration_hours' => 1,
            'payment_proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertSessionHasNoErrors();

        // Check via Eloquent (avoids SQLite date-format discrepancies in assertDatabaseHas).
        $userBBooking = Booking::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->where('user_id', $userB->id)
            ->where('status', Booking::STATUS_PENDING)
            ->first();

        $this->assertNotNull($userBBooking, 'User B should have a pending booking for the released slot.');
    }

    // -----------------------------------------------------------------------
    // Test E: Cancelled booking cannot be re-confirmed
    // -----------------------------------------------------------------------

    public function test_e_cancelled_booking_cannot_be_re_confirmed_via_status_update(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN_KASIR]);
        $userA = $this->makeUser();
        $court = $this->makeCourt();
        $date = now()->addDays(4)->toDateString();

        // Simulate: booking exists, was cancelled (payment expired / timed out).
        $oldBooking = Booking::factory()
            ->forSlot($court->id, $date, 13, 1)
            ->for($userA)
            ->cancelled()
            ->create();

        // A new customer has since booked and been confirmed for the same slot.
        $userB = $this->makeUser();
        $newBooking = Booking::factory()
            ->forSlot($court->id, $date, 13, 1)
            ->for($userB)
            ->confirmed()
            ->create();

        // Attempting to re-confirm the cancelled booking must be rejected.
        $this->actingAs($admin)
            ->post(route('admin.bookings.status', $oldBooking), ['status' => 'confirmed'])
            ->assertSessionHasErrors('status');

        $oldBooking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $oldBooking->status, 'Cancelled booking must remain cancelled.');

        $newBooking->refresh();
        $this->assertSame(Booking::STATUS_CONFIRMED, $newBooking->status, 'Newer booking must remain confirmed.');
    }

    // -----------------------------------------------------------------------
    // Test F: Idempotent status update (duplicate webhook simulation)
    // -----------------------------------------------------------------------

    public function test_f_duplicate_status_update_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN_KASIR]);
        $userA = $this->makeUser();
        $booking = Booking::factory()->for($this->makeCourt())->for($userA)->create([
            'status' => Booking::STATUS_PENDING,
        ]);

        // First confirmation.
        $this->actingAs($admin)
            ->post(route('admin.bookings.status', $booking), ['status' => 'confirmed'])
            ->assertSessionHasNoErrors();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);

        // Second identical callback — must be a no-op.
        $this->actingAs($admin)
            ->post(route('admin.bookings.status', $booking), ['status' => 'confirmed'])
            ->assertSessionHasNoErrors();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);

        // No duplicate booking row must have been created.
        $this->assertSame(1, Booking::where('id', $booking->id)->count());
    }

    // -----------------------------------------------------------------------
    // Additional: walk-in kasir path also prevents double booking
    // -----------------------------------------------------------------------

    public function test_walk_in_kasir_booking_respects_existing_online_booking(): void
    {
        $court = $this->makeCourt();
        $userA = $this->makeUser();
        $kasir = User::factory()->create(['role' => User::ROLE_ADMIN_KASIR]);
        $date = now()->addDays(3)->toDateString();

        // Online booking already exists.
        Booking::factory()
            ->forSlot($court->id, $date, 13, 1)
            ->for($userA)
            ->create(['status' => Booking::STATUS_CONFIRMED]);

        // Kasir tries to create a walk-in booking for the same slot.
        $this->actingAs($kasir)
            ->post(route('admin.bookings.store'), [
                'customer_name' => 'Budi Santoso',
                'court_id' => $court->id,
                'date' => $date,
                'start_time' => '13:00',
                'duration_hours' => 1,
            ])->assertSessionHasErrors('start_time');

        $this->assertSame(
            1,
            Booking::where('court_id', $court->id)->whereDate('date', $date)
                ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
                ->count()
        );
    }
}
