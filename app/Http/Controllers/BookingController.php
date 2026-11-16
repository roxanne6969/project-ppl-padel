<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BookingController extends Controller
{
    // Harga per jam: Rp 300.000 (linear)
    public const PRICE_PER_HOUR = 300000;

    public function store(Request $request)
    {
        $validated = $request->validate([
            'court_id' => ['required', 'exists:courts,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'duration_hours' => ['required', 'integer', 'min:1', 'max:8'],
            'promo_code' => ['nullable', 'string'],
            'payment_proof' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'court_id.required' => 'Lapangan wajib dipilih.',
            'date.required' => 'Tanggal booking wajib diisi.',
            'date.after_or_equal' => 'Tanggal booking tidak boleh tanggal yang sudah lewat.',
            'start_time.required' => 'Jam mulai wajib diisi.',
            'duration_hours.required' => 'Durasi booking wajib diisi.',
            'payment_proof.required' => 'Bukti pembayaran wajib diunggah.',
            'payment_proof.file' => 'Bukti pembayaran harus berupa file yang valid.',
            'payment_proof.image' => 'Bukti pembayaran harus berupa gambar.',
            'payment_proof.mimes' => 'Format gambar bukti pembayaran harus JPG, JPEG, PNG, atau WEBP.',
            'payment_proof.max' => 'Ukuran file bukti pembayaran maksimal 5MB.',
        ]);

        [$startTime, $endTime, $endH] = $this->resolveTimeWindow($validated['start_time'], (int) $validated['duration_hours']);

        // Pastikan tidak melebihi jam 24
        if ($endH > 24) {
            return back()->withErrors([
                'start_time' => 'Durasi booking melebihi batas jam operasional (max 24:00).',
            ])->withInput();
        }

        $hours = (int) $validated['duration_hours'];
        $total = $hours * self::PRICE_PER_HOUR;

        // Validate promo code before entering the transaction so we can return
        // a user-friendly error without consuming the lock.
        if (! empty($validated['promo_code'])) {
            $promo = Promo::where('code', strtoupper($validated['promo_code']))
                ->where('is_active', true)
                ->first();

            if (! $promo) {
                return back()->withErrors([
                    'promo_code' => 'Kode promo tidak valid atau sudah tidak aktif.',
                ])->withInput();
            }

            $total = $total * (1 - ($promo->discount_percentage / 100));
        }

        // Store the uploaded file before the transaction; file I/O inside a
        // DB transaction increases lock-hold time unnecessarily.
        $paymentProofPath = $request->file('payment_proof')->store('payments', 'public');

        $conflict = $this->attemptAtomicBooking(
            courtId: $validated['court_id'],
            date: $validated['date'],
            startTime: $startTime,
            endTime: $endTime,
            insertData: [
                'user_id' => $request->user()->id,
                'court_id' => $validated['court_id'],
                'date' => $validated['date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_hours' => $hours,
                'status' => Booking::STATUS_PENDING,
                'price_per_hour' => self::PRICE_PER_HOUR,
                'total_price' => $total,
                'payment_proof' => $paymentProofPath,
            ]
        );

        if ($conflict) {
            // Clean up the already-uploaded file so storage is not leaked.
            Storage::disk('public')->delete($paymentProofPath);

            return back()->withErrors([
                'start_time' => 'Maaf, lapangan ini baru saja dipesan oleh pelanggan lain. Silakan pilih jadwal yang tersedia.',
            ])->withInput();
        }

        return back()->with('success', 'Booking berhasil dibuat dan bukti pembayaran telah diunggah! Menunggu konfirmasi admin.');
    }

    /**
     * Tampilkan halaman khusus pencatatan pesanan walk-in untuk kasir.
     */
    public function walkIn()
    {
        $courts = Court::query()->orderBy('name')->get();

        $walkInBookings = Booking::query()
            ->with(['court', 'user'])
            ->where(function ($query) {
                $query->whereNotNull('customer_name')
                    ->orWhere('notes', 'like', '%Walk-in%');
            })
            ->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        return view('dashboard.walkin', [
            'courts' => $courts,
            'walkInBookings' => $walkInBookings,
        ]);
    }

    /**
     * Kasir membuat booking atas nama pelanggan langsung (walk-in order).
     * Booking langsung berstatus confirmed karena kasir yang memverifikasi.
     */
    public function storeByKasir(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'court_id' => ['required', 'exists:courts,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'duration_hours' => ['required', 'integer', 'min:1', 'max:8'],
        ], [
            'customer_name.required' => 'Nama pelanggan wajib diisi.',
            'court_id.required' => 'Lapangan wajib dipilih.',
            'date.required' => 'Tanggal booking wajib diisi.',
            'date.after_or_equal' => 'Tanggal booking tidak boleh tanggal yang sudah lewat.',
            'start_time.required' => 'Jam mulai wajib diisi.',
            'duration_hours.required' => 'Durasi wajib diisi.',
        ]);

        [$startTime, $endTime, $endH] = $this->resolveTimeWindow($validated['start_time'], (int) $validated['duration_hours']);

        if ($endH > 24) {
            return back()->withErrors([
                'start_time' => 'Durasi booking melebihi batas jam operasional (max 24:00).',
            ])->withInput();
        }

        $hours = (int) $validated['duration_hours'];
        $total = $hours * self::PRICE_PER_HOUR;
        $customer = User::where('name', $validated['customer_name'])
            ->where('role', User::ROLE_USER)
            ->first();
        $userId = $customer?->id ?? $request->user()->id;

        $conflict = $this->attemptAtomicBooking(
            courtId: $validated['court_id'],
            date: $validated['date'],
            startTime: $startTime,
            endTime: $endTime,
            insertData: [
                'user_id' => $userId,
                'court_id' => $validated['court_id'],
                'customer_name' => $validated['customer_name'],
                'date' => $validated['date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_hours' => $hours,
                // Kasir langsung confirmed karena bayar di tempat
                'status' => Booking::STATUS_CONFIRMED,
                'price_per_hour' => self::PRICE_PER_HOUR,
                'total_price' => $total,
                'notes' => 'Walk-in — dicatat oleh kasir.'.($customer ? '' : ' (tamu/non-member)'),
            ]
        );

        if ($conflict) {
            return back()->withErrors([
                'start_time' => 'Jadwal sudah terisi, silakan pilih jam lain.',
            ])->withInput();
        }

        return back()->with('success',
            'Booking atas nama "'.$validated['customer_name'].'" berhasil dibuat dan langsung dikonfirmasi.'
        );
    }

    public function uploadPayment(Request $request, Booking $booking)
    {
        // Pastikan booking milik user yang login
        abort_if($booking->user_id !== $request->user()->id, 403);
        abort_if($booking->status === Booking::STATUS_CANCELLED, 403, 'Booking telah dibatalkan.');

        $request->validate([
            'payment_proof' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        // Hapus file lama jika ada
        if ($booking->payment_proof && Storage::disk('public')->exists($booking->payment_proof)) {
            Storage::disk('public')->delete($booking->payment_proof);
        }

        $path = $request->file('payment_proof')->store('payments', 'public');

        $booking->update(['payment_proof' => $path]);

        return back()->with('success', 'Bukti pembayaran berhasil diupload. Admin akan segera memverifikasi.');
    }

    public function updateStatus(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,confirmed,cancelled'],
        ]);

        $error = null;

        DB::transaction(function () use ($booking, $validated, &$error): void {
            $locked = Booking::query()
                ->where('id', $booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Guard: a cancelled booking must not be re-confirmed.
            if ($locked->status === Booking::STATUS_CANCELLED && $validated['status'] === Booking::STATUS_CONFIRMED) {
                $error = 'Booking yang telah dibatalkan tidak dapat dikonfirmasi kembali.';

                return;
            }

            // Guard: idempotent — skip the write if status is unchanged.
            if ($locked->status === $validated['status']) {
                return;
            }

            $locked->update(['status' => $validated['status']]);
        });

        if ($error) {
            return back()->withErrors([
                'status' => $error,
            ]);
        }

        return back()->with('success', 'Status booking berhasil diperbarui.');
    }

    /**
     * API: Kembalikan slot terisi per lapangan untuk tanggal tertentu (JSON).
     * Digunakan oleh frontend agar slot grid dapat diperbarui tanpa reload halaman.
     */
    public function getSlots(Request $request)
    {
        $today = now()->toDateString();
        $date = $request->input('date', $today);
        if ($date < $today) {
            $date = $today;
        }

        $bookings = Booking::query()
            ->whereDate('date', $date)
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->get(['court_id', 'start_time', 'end_time', 'status']);

        // Bangun map: { court_id: { "HH:MM": status, ... }, ... }
        $map = [];
        foreach ($bookings as $booking) {
            // Ambil jam saja (DB simpan "HH:MM:SS", kita butuh integer)
            $startH = (int) substr($booking->start_time, 0, 2);
            $endH = (int) substr($booking->end_time, 0, 2);
            $endM = (int) substr($booking->end_time, 3, 2);

            // Jika end punya menit sisa, bulatkan ke atas
            if ($endM > 0) {
                $endH++;
            }

            for ($h = $startH; $h < $endH; $h++) {
                $slot = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
                $map[$booking->court_id][$slot] = $booking->status;
            }
        }

        return response()->json($map);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Parse start_time (HH:MM) + duration into canonical HH:MM:00 strings.
     *
     * Returns [startTime, endTime, endHour] where endHour is the raw integer
     * for the "> 24" guard check.
     *
     * @return array{string, string, int}
     */
    private function resolveTimeWindow(string $startTimeInput, int $durationHours): array
    {
        $startH = (int) explode(':', $startTimeInput)[0];
        $endH = $startH + $durationHours;
        $startTime = str_pad($startH, 2, '0', STR_PAD_LEFT).':00:00';
        $endTime = str_pad($endH, 2, '0', STR_PAD_LEFT).':00:00';

        return [$startTime, $endTime, $endH];
    }

    /**
     * Atomically check for overlapping bookings and INSERT if the slot is free.
     *
     * Concurrency strategy — pessimistic write-lock on the parent court row:
     *
     *   1. BEGIN TRANSACTION
     *   2. SELECT … FROM courts WHERE id = ? FOR UPDATE
     *      → serialises all concurrent requests for this court
     *   3. SELECT EXISTS(… overlap check …)
     *      → sees committed rows from other transactions; none can INSERT
     *        new rows for this court until our transaction ends
     *   4. If no conflict → INSERT booking
     *   5. COMMIT  (or ROLLBACK if conflict)
     *
     * Why lock the court row and not an existing booking row?
     *   – When no booking exists for the target slot, there is no row to lock.
     *   – The court row is always present, giving us a stable per-court mutex.
     *
     * @param  array<string, mixed>  $insertData
     * @return bool true if a conflict was detected and no booking was created
     */
    private function attemptAtomicBooking(
        int $courtId,
        string $date,
        string $startTime,
        string $endTime,
        array $insertData,
    ): bool {
        $conflict = false;

        DB::transaction(function () use ($courtId, $date, $startTime, $endTime, $insertData, &$conflict): void {
            // Acquire an exclusive row-level lock on the court for the duration
            // of this transaction. Any concurrent transaction for the same court
            // will block at this point until we commit or rollback.
            Court::query()
                ->where('id', $courtId)
                ->lockForUpdate()
                ->firstOrFail();

            // Overlap check — runs inside the lock, so it is atomic with the INSERT.
            // New booking [start, end) overlaps with existing [start_time, end_time) when:
            //   existing.start_time < new.end_time  AND  existing.end_time > new.start_time
            $conflict = Booking::query()
                ->where('court_id', $courtId)
                ->whereDate('date', $date)
                ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
                ->where(function ($query) use ($startTime, $endTime): void {
                    $query->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                })
                ->exists();

            if ($conflict) {
                // Return without inserting; the transaction will rollback.
                return;
            }

            Booking::query()->create($insertData);
        });

        return $conflict;
    }
}
