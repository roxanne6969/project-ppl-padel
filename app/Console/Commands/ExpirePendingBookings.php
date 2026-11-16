<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expire pending bookings that have not been confirmed within the configured window.
 *
 * Usage:
 *   php artisan bookings:expire
 *   php artisan bookings:expire --hours=48
 *   php artisan bookings:expire --dry-run
 *
 * Schedule (add to routes/console.php or App\Console\Kernel):
 *   Schedule::command('bookings:expire')->hourly();
 *
 * Safety guarantees:
 *  – Only pending bookings older than the threshold are expired.
 *  – Confirmed bookings are never touched.
 *  – The status transition is guarded by a database transaction with a
 *    pessimistic lock (lockForUpdate) so an admin confirming a booking at the
 *    exact same moment the expiration job runs will not cause a conflict.
 *  – Idempotent: re-running the command never changes an already-cancelled booking.
 *  – Booking history is preserved (status set to cancelled, never deleted).
 */
class ExpirePendingBookings extends Command
{
    protected $signature = 'bookings:expire
                            {--hours=24 : Expire pending bookings older than this many hours}
                            {--dry-run  : Report what would be expired without making changes}';

    protected $description = 'Expire pending bookings that exceeded the payment confirmation window';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');
        $threshold = now()->subHours($hours);

        if ($hours < 1) {
            $this->error('--hours must be at least 1.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Expiring pending bookings created before %s (threshold: %d hours)%s',
            $threshold->toDateTimeString(),
            $hours,
            $dryRun ? ' [DRY RUN]' : ''
        ));

        // Fetch the candidate IDs first so we can iterate safely.
        $candidateIds = Booking::query()
            ->where('status', Booking::STATUS_PENDING)
            ->where('created_at', '<', $threshold)
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            $this->info('No pending bookings to expire.');

            return self::SUCCESS;
        }

        $expired = 0;
        $skipped = 0;

        foreach ($candidateIds as $bookingId) {
            if ($dryRun) {
                $this->line("  [DRY RUN] Would expire booking #{$bookingId}");
                $expired++;

                continue;
            }

            // Lock the individual booking row to prevent a concurrent admin
            // confirmation from racing with the expiration.
            DB::transaction(function () use ($bookingId, &$expired, &$skipped): void {
                $booking = Booking::query()
                    ->where('id', $bookingId)
                    ->lockForUpdate()
                    ->first();

                if (! $booking) {
                    // Booking was deleted between the query and the lock.
                    $skipped++;

                    return;
                }

                // Re-check status inside the lock. If an admin confirmed it
                // between our candidate query and now, skip it.
                if ($booking->status !== Booking::STATUS_PENDING) {
                    $skipped++;

                    return;
                }

                $booking->update(['status' => Booking::STATUS_CANCELLED]);
                $expired++;
            });
        }

        $this->info("Done. Expired: {$expired}, Skipped (already changed): {$skipped}.");

        return self::SUCCESS;
    }
}
