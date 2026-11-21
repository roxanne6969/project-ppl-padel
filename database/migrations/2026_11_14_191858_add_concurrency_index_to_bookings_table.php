<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a composite index on (court_id, date, status) to the bookings table.
 *
 * Purpose – concurrency control:
 * When BookingController wraps the overlap-check + INSERT in a DB transaction
 * and issues a pessimistic lock (lockForUpdate) on the parent court row, the
 * database must still be able to evaluate the WHERE clause efficiently.
 * Without this index the check degrades to a full-table scan and, more
 * importantly, the index allows the unique-constraint enforcement at the
 * database engine level to be as fast as possible.
 *
 * We deliberately do NOT add a unique index for (court_id, date, start_time)
 * because bookings can share a start_time only after a previous one is
 * cancelled; a partial unique index on status is not supported by all engines.
 * Instead, the application enforces the overlap rule inside a serialized
 * transaction, and this index makes that check fast under concurrent load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Speed up the overlap-check query used in BookingController::store()
            // and BookingController::storeByKasir().
            $table->index(['court_id', 'date', 'status'], 'bookings_court_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_court_date_status_idx');
        });
    }
};
