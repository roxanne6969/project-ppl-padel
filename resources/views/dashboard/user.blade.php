@extends('layouts.app')

@section('content')
    @php
        $totalSlots    = $slots ? count($slots) * max($courts->count(), 1) : 0;
        $bookedSlots   = 0;
        foreach ($bookingMap as $courtSlots) {
            $bookedSlots += count($courtSlots);
        }
        $availableSlots = max($totalSlots - $bookedSlots, 0);
    @endphp

    {{-- ════════ STYLE: TIME PICKER MODAL (WHEEL) ════════ --}}
    <style>
        .tp-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 998;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .tp-modal-backdrop.tp-modal-open {
            display: flex;
        }

        .tp-modal {
            background: #ffffff;
            border: 1px solid var(--border-color, #d1d5db);
            border-radius: 16px;
            width: min(420px, 94vw);
            box-shadow: 0 20px 40px -5px rgba(15, 23, 42, 0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: tpModalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes tpModalIn {
            from { opacity: 0; transform: scale(0.96) translateY(6px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        .tp-modal-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color, #e2e8f0);
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .tp-modal-title {
            font-size: 14px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tp-modal-title svg { color: var(--primary-color, #2563eb); }

        .tp-modal-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted-color, #64748b);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            border-radius: 4px;
        }
        .tp-modal-close:hover {
            color: #ef4444;
            background: #fee2e2;
        }

        .tp-modal-body {
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: #ffffff;
        }

        .tp-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 12px 6px;
        }

        .tp-section-title {
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #64748b;
            padding: 0 4px 4px;
        }

        /* ── Wheel Picker ── */
        .tp-picker {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            height: calc(38px * 3); /* 114px: compact, 3 baris tampak */
        }

        /* Highlight band di tengah */
        .tp-picker::before {
            content: "";
            position: absolute;
            left: 2px; right: 2px;
            top: 50%;
            height: 38px;
            transform: translateY(-50%);
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(37, 99, 235, 0.18);
            border-radius: 8px;
            pointer-events: none;
            z-index: 0;
        }

        .tp-picker .tp-col {
            position: relative;
            height: 100%;
            overflow-y: auto;
            scroll-snap-type: y mandatory;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            padding: 38px 0; /* 1 item padding for 3 visible items */
            -webkit-mask-image: linear-gradient(to bottom, transparent 0%, #000 25%, #000 75%, transparent 100%);
                    mask-image: linear-gradient(to bottom, transparent 0%, #000 25%, #000 75%, transparent 100%);
            z-index: 1;
        }
        .tp-picker .tp-col::-webkit-scrollbar { display: none; }

        .tp-picker .tp-item {
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            scroll-snap-align: center;
            font-size: 20px;
            font-weight: 600;
            color: #94a3b8;
            font-variant-numeric: tabular-nums;
            transition: color .15s, transform .15s;
            user-select: none;
            cursor: pointer;
        }
        .tp-picker .tp-item.near   { color: #64748b; }
        .tp-picker .tp-item.active { color: #0f172a; font-weight: 700; transform: scale(1.12); }

        .tp-picker .tp-col.locked {
            overflow: hidden;
            pointer-events: none;
            padding: 38px 0;
            cursor: not-allowed;
        }
        .tp-picker .tp-col.locked .tp-item {
            color: #0f172a;
            font-weight: 700;
            transform: scale(1.12);
        }

        .tp-picker .tp-col.period .tp-item {
            font-size: 16px;
            font-weight: 700;
        }

        /* ── Ringkasan & Note ── */
        .tp-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .tp-summary-label { color: #64748b; font-size: 11.5px; font-weight: 500; }
        .tp-summary-range { font-size: 14.5px; font-weight: 700; color: #0f172a; margin-top: 1px; }
        .tp-summary-dur {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #2563eb;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12.5px;
        }

        .tp-note {
            color: #64748b;
            font-size: 11px;
            text-align: center;
            margin: 0;
        }

        .tp-modal-footer {
            padding: 12px 18px;
            border-top: 1px solid var(--border-color, #e2e8f0);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: #ffffff;
        }

        /* ── Trigger button in Form ── */
        .tp-modal-trigger-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            cursor: pointer;
            background: #ffffff;
            text-align: left;
            padding: 8px 12px;
        }
        .tp-modal-trigger-btn:hover {
            border-color: var(--primary-color, #2563eb);
        }
        .tp-trigger-left {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #1e293b;
            font-size: 13.5px;
        }
        .tp-trigger-left svg {
            color: var(--primary-color, #2563eb);
            flex-shrink: 0;
        }
        .tp-trigger-badge {
            font-size: 11px;
            font-weight: 600;
            color: #2563eb;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 3px 8px;
            border-radius: 4px;
            flex-shrink: 0;
        }
    </style>

    {{-- ════════ MODAL QR QRIS ════════ --}}
    <div class="qr-modal-backdrop" id="qr-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title">
        <div class="qr-modal">
            <div class="qr-modal-header">
                <div class="qr-modal-title" id="qr-modal-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Scan QRIS untuk Membayar
                </div>
                <button class="qr-modal-close" id="qr-modal-close" aria-label="Tutup modal">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="qr-modal-body">
                {{-- Countdown Timer --}}
                <div class="qr-timer-banner" id="qr-timer-banner">
                    <div class="qr-timer-left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Selesaikan Pembayaran Dalam:</span>
                    </div>
                    <div class="qr-timer-digits" id="qr-timer-countdown">01:30</div>
                </div>

                <div class="qr-summary" id="qr-summary">
                    {{-- Diisi oleh JS --}}
                </div>
                <div class="qr-image-wrap">
                    <img src="/images/qris.jpg" alt="QR Code QRIS Pembayaran" class="qr-image">
                </div>
                <div class="qr-info">
                    <div class="qr-info-row">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Scan QR lalu bayar sesuai nominal dalam batas waktu 1 menit 30 detik.
                    </div>
                    <div class="qr-info-row">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        a.n. <strong>EFRAIM DARRELL F.N</strong> &mdash; NMID: ID1026469021541
                    </div>
                </div>

                {{-- Upload Bukti Pembayaran (Wajib) --}}
                <div class="qr-upload-section">
                    <div class="qr-upload-header">
                        <label class="qr-upload-title" for="modal_payment_proof">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            Upload Bukti Pembayaran <span class="qr-upload-required">*</span>
                        </label>
                        <span class="qr-upload-hint">Wajib diunggah</span>
                    </div>

                    <div class="qr-dropzone" id="qr-dropzone">
                        <input type="file" id="modal_payment_proof" accept="image/jpeg,image/png,image/webp,image/jpg" class="qr-dropzone-input" required>
                        <div class="qr-dropzone-empty" id="qr-dropzone-empty">
                            <div class="qr-dropzone-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            </div>
                            <div class="qr-dropzone-text">
                                <strong>Pilih foto bukti transfer</strong>
                                <span>Klik atau seret file ke area ini (Maks. 5MB)</span>
                            </div>
                        </div>
                        <div class="qr-dropzone-preview" id="qr-dropzone-preview" style="display: none;">
                            <img id="qr-preview-img" src="" alt="Preview Bukti Transfer" class="qr-preview-image">
                            <div class="qr-preview-info">
                                <div class="qr-preview-name" id="qr-preview-name">—</div>
                                <div class="qr-preview-size" id="qr-preview-size">—</div>
                                <div class="qr-preview-badge">✓ Foto bukti siap diunggah</div>
                            </div>
                            <button type="button" class="qr-preview-remove" id="qr-preview-remove" title="Hapus foto">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="qr-modal-footer">
                <button class="db-btn db-btn-primary qr-confirm-btn" id="qr-confirm-btn" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Upload Bukti Bayar
                </button>
                <button class="db-btn qr-cancel-btn" id="qr-cancel-btn" type="button">Batal</button>
            </div>
        </div>
    </div>

    {{-- ════════ MODAL TIME PICKER (SCROLL-WHEEL) ════════ --}}
    <div class="tp-modal-backdrop" id="tp-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="tp-modal-title">
        <div class="tp-modal">
            <div class="tp-modal-header">
                <div class="tp-modal-title" id="tp-modal-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Pilih Jam Sewa Lapangan
                </div>
                <button class="tp-modal-close" id="tp-modal-close" type="button" aria-label="Tutup modal">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="tp-modal-body">
                <!-- ===== JAM MULAI ===== -->
                <section class="tp-section" id="tp-start-section">
                    <div class="tp-section-title">Jam Mulai</div>
                    <div class="tp-picker" id="tp-start-picker">
                        <div class="tp-col hour" aria-label="Jam mulai"></div>
                        <div class="tp-col locked" aria-label="Menit"><div class="tp-item">00</div></div>
                        <div class="tp-col period" aria-label="AM/PM mulai">
                            <div class="tp-item">AM</div>
                            <div class="tp-item">PM</div>
                        </div>
                    </div>
                </section>

                <!-- ===== JAM SELESAI ===== -->
                <section class="tp-section" id="tp-end-section">
                    <div class="tp-section-title">Jam Selesai</div>
                    <div class="tp-picker" id="tp-end-picker">
                        <div class="tp-col hour" aria-label="Jam selesai"></div>
                        <div class="tp-col locked" aria-label="Menit"><div class="tp-item">00</div></div>
                        <div class="tp-col period" aria-label="AM/PM selesai">
                            <div class="tp-item">AM</div>
                            <div class="tp-item">PM</div>
                        </div>
                    </div>
                </section>

                <div class="tp-summary">
                    <div>
                        <div class="tp-summary-label">Jadwal sewa</div>
                        <div class="tp-summary-range" id="tp-range">-</div>
                    </div>
                    <div class="tp-summary-dur" id="tp-dur">-</div>
                </div>

                <p class="tp-note">Menit selalu :00 — sewa dihitung per kelipatan 1 jam.</p>
            </div>
            <div class="tp-modal-footer">
                <button class="db-btn tp-modal-cancel-btn" id="tp-modal-cancel-btn" type="button">Batal</button>
                <button class="db-btn db-btn-primary" id="tp-modal-apply-btn" type="button">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Terapkan Jam
                </button>
            </div>
        </div>
    </div>

    {{-- ════════ HEADER ════════ --}}
    <div class="db-page-header">
        <div>
            <div class="db-page-eyebrow">Member</div>
            <h1 class="db-page-title">Booking Lapangan</h1>
            <p class="db-page-desc">Pilih jadwal kosong dan lakukan booking dengan mudah.</p>
        </div>
        <div class="db-page-badge db-badge-user">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            User
        </div>
    </div>

    @include('partials.flash')

    {{-- ════════ STATS ════════ --}}
    <div class="db-stats-grid db-stats-3">
        <div class="db-stat-card">
            <div class="db-stat-icon db-icon-blue">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="db-stat-body">
                <div class="db-stat-label">Tanggal Dipilih</div>
                <div class="db-stat-value db-stat-date">{{ \Carbon\Carbon::parse($date)->format('d M Y') }}</div>
                <div class="db-stat-sub">{{ \Carbon\Carbon::parse($date)->isoFormat('dddd') }}</div>
            </div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-icon db-icon-teal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            </div>
            <div class="db-stat-body">
                <div class="db-stat-label">Jumlah Lapangan</div>
                <div class="db-stat-value">{{ $courts->count() }}</div>
                <div class="db-stat-sub">Lapangan aktif</div>
            </div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-icon db-icon-green">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="db-stat-body">
                <div class="db-stat-label">Slot Tersedia</div>
                <div class="db-stat-value">{{ $availableSlots }}</div>
                <div class="db-stat-sub">Dari {{ $totalSlots }} total slot</div>
            </div>
        </div>
    </div>

    {{-- ════════ DATE PICKER ════════ --}}
    <div class="db-card db-date-picker-card">
        <div class="db-card-header">
            <div class="db-card-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Pilih Tanggal
            </div>
        </div>
        <div class="db-date-form">
            <input class="db-input" type="date" id="main-date-picker" name="date" value="{{ $date }}" min="{{ now()->toDateString() }}">
        </div>
        <p class="db-hint">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Slot berwarna hijau = tersedia. Merah/abu = terisi. Pemesanan hanya dapat dilakukan untuk hari ini dan tanggal mendatang.
        </p>
    </div>

    {{-- ════════ SLOT GRID PER COURT ════════ --}}
    @foreach ($courts as $court)
        <div class="db-card">
            <div class="db-card-header">
                <div class="db-card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                    {{ $court->name }}
                </div>
                <div class="db-slot-legend">
                    <span class="db-legend-dot db-legend-available"></span> Tersedia
                    <span class="db-legend-dot db-legend-booked" style="margin-left:12px"></span> Terisi
                </div>
            </div>
            <div class="db-slot-grid" data-court-slot-grid="{{ $court->id }}">
                @foreach ($slots as $slot)
                    @php $status = $bookingMap[$court->id][$slot] ?? null; @endphp
                    <div class="db-slot {{ $status ? 'db-slot-booked' : 'db-slot-free' }}">
                        <div class="db-slot-time">{{ $slot }}</div>
                        <div class="db-slot-label">{{ $status ? ucfirst($status) : 'Tersedia' }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach


    {{-- ════════ HARGA LAPANGAN ════════ --}}
    <div class="db-card">
        <div class="db-card-header">
            <div class="db-card-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Daftar Harga Sewa
            </div>
            <span class="db-page-badge db-badge-user">Rp 300.000 / jam</span>
        </div>
        <div class="db-price-grid">
            @for ($i = 1; $i <= 8; $i++)
                <div class="db-price-item {{ old('duration_hours', 1) == $i ? 'db-price-item--highlight' : '' }}" data-hours="{{ $i }}">
                    <div class="db-price-duration">{{ $i }} Jam</div>
                    <div class="db-price-amount">Rp {{ number_format($i * 300000, 0, ',', '.') }}</div>
                    @if($i === 1)
                        <div class="db-price-tag">Populer</div>
                    @endif
                </div>
            @endfor
        </div>
    </div>

    {{-- ════════ FORM BOOKING ════════ --}}
    <div class="db-card" id="booking-form-card">
        <div class="db-card-header">
            <div class="db-card-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Pesan Lapangan
            </div>
        </div>
        <form method="post" action="{{ route('bookings.store') }}" class="db-booking-form" id="booking-form" enctype="multipart/form-data">
            @csrf
            <input type="file" id="hidden_payment_proof" name="payment_proof" style="display:none;" required>
            <input type="hidden" id="held_booking_id" name="held_booking_id" value="">
            <div class="db-form-grid">
                <div class="db-form-group">
                    <label class="db-label" for="court_id">Lapangan</label>
                    <select class="db-input" id="court_id" name="court_id" required>
                        <option value="">Pilih lapangan…</option>
                        @foreach ($courts as $court)
                            <option value="{{ $court->id }}" @selected(old('court_id') == $court->id)>{{ $court->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Tanggal disinkronkan dari date picker atas (hidden) --}}
                <input type="hidden" id="booking_date" name="date" value="{{ old('date') && old('date') >= now()->toDateString() ? old('date') : $date }}">

                {{-- Jam Mulai & Selesai: scroll-wheel picker (collapsible) --}}
                @php
                    $oldStart = old('start_time', '20:00');
                    $oldDur   = (int) old('duration_hours', 1);
                    $oldStartH = (int) explode(':', $oldStart)[0];
                    $oldEndH   = ($oldStartH + $oldDur) % 24;
                    $oldEndFmt = str_pad($oldEndH, 2, '0', STR_PAD_LEFT) . ':00';
                @endphp
                <input type="hidden" id="start_time" name="start_time" value="{{ $oldStart }}">

                <div class="db-form-group">
                    <label class="db-label" id="start_time_label">Jam Sewa</label>
                    <button type="button" class="db-input tp-modal-trigger-btn" id="tp-modal-open-btn" aria-haspopup="dialog">
                        <div class="tp-trigger-left">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span id="tp-trigger-text">{{ $oldStart }} – {{ $oldEndFmt }} ({{ $oldDur }} jam)</span>
                        </div>
                        <span class="tp-trigger-badge">Pilih Jam</span>
                    </button>
                </div>
                <div class="db-form-group">
                    <label class="db-label" for="duration_hours">Durasi</label>
                    <select class="db-input" id="duration_hours" name="duration_hours" required>
                        @for ($i = 1; $i <= 8; $i++)
                            <option value="{{ $i }}" @selected(old('duration_hours', 1) == $i)>{{ $i }} jam — Rp {{ number_format($i * 300000, 0, ',', '.') }}</option>
                        @endfor
                    </select>
                </div>
                <div class="db-form-group">
                    <label class="db-label" for="promo_code">Kode Promo (Opsional)</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" class="db-input" id="promo_code" name="promo_code" placeholder="Masukkan kode promo" value="{{ old('promo_code') }}" style="flex: 1; text-transform: uppercase;">
                        <button type="button" class="db-btn db-btn-teal" id="check-promo-btn" style="padding: 0 16px;">Gunakan</button>
                    </div>
                    <span id="promo-message" style="font-size: 12px; margin-top: 4px; display: block;"></span>
                </div>
            </div>

            {{-- Ringkasan harga real-time --}}
            <div class="db-price-summary" id="price-summary">
                <div class="db-price-summary-row">
                    <span>Lapangan</span>
                    <span id="ps-court">—</span>
                </div>
                <div class="db-price-summary-row">
                    <span>Tanggal</span>
                    <span id="ps-date">—</span>
                </div>
                <div class="db-price-summary-row">
                    <span>Jam</span>
                    <span id="ps-time">—</span>
                </div>
                <div class="db-price-summary-row db-price-summary-total">
                    <span>Total Bayar</span>
                    <span id="ps-total">Rp 300.000</span>
                </div>
            </div>

            <div style="padding: 0 20px 20px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <button class="db-btn db-btn-primary" type="button" id="open-qr-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Lihat QR &amp; Booking
                </button>
                <span class="db-hint" style="padding:0; margin:0;">Kamu akan diminta scan QRIS sebelum booking dikonfirmasi.</span>
            </div>
        </form>
    </div>

    {{-- ════════ RIWAYAT BOOKING ════════ --}}
    <div class="db-card">
        <div class="db-card-header">
            <div class="db-card-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
                Riwayat Booking Saya
            </div>
            <span class="db-page-badge db-badge-admin">{{ $myBookings->count() }} Booking</span>
        </div>

        @if ($myBookings->isEmpty())
            <div class="db-empty-state">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <div>Belum ada riwayat booking.</div>
                <div style="font-size:12px;color:var(--db-muted)">Lakukan booking pertama kamu di atas!</div>
            </div>
        @else
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>Lapangan</th>
                            <th>Tanggal</th>
                            <th>Jam</th>
                            <th>Durasi</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Bukti Bayar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($myBookings as $booking)
                            @php
                                $statusLabel = match($booking->status) {
                                    'confirmed' => 'Dikonfirmasi',
                                    'cancelled' => 'Dibatalkan',
                                    default     => 'Menunggu',
                                };
                            @endphp
                            <tr>
                                <td><span class="db-court-name">{{ $booking->court->name ?? '-' }}</span></td>
                                <td class="db-muted db-nowrap">{{ $booking->date?->format('d M Y') }}</td>
                                <td class="db-muted db-nowrap">{{ \Carbon\Carbon::parse($booking->start_time)->format('H:i') }} – {{ \Carbon\Carbon::parse($booking->end_time)->format('H:i') }}</td>
                                <td class="db-muted">{{ $booking->duration_hours ?? '-' }} jam</td>
                                <td class="db-price-cell">Rp {{ number_format($booking->total_price, 0, ',', '.') }}</td>
                                <td><span class="db-status-badge db-status-{{ $booking->status }}">{{ $statusLabel }}</span></td>
                                <td>
                                    @if ($booking->payment_proof)
                                        <div class="db-proof-uploaded">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            <a href="{{ Storage::url($booking->payment_proof) }}" target="_blank" class="db-proof-link">Lihat Bukti</a>
                                        </div>
                                    @else
                                        <span class="db-muted" style="font-size:12px">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ════════ JS ════════ --}}
    <script>
    (function () {
        const PRICE_PER_HOUR = 300000;
        const TODAY = '{{ now()->toDateString() }}';

        // ── All slots (00:00 – 23:00) ──
        const ALL_SLOTS = Array.from({length: 24}, (_, i) => String(i).padStart(2,'0') + ':00');

        // ── Elements ──
        const courtSel      = document.getElementById('court_id');
        const mainDatePicker= document.getElementById('main-date-picker');  // date picker atas
        const bookingDate   = document.getElementById('booking_date');       // hidden input di form
        const timeSel       = document.getElementById('start_time');
        const durSel        = document.getElementById('duration_hours');
        const promoInput    = document.getElementById('promo_code');
        const checkPromoBtn = document.getElementById('check-promo-btn');
        const promoMsg      = document.getElementById('promo-message');
        const openQrBtn     = document.getElementById('open-qr-btn');
        const backdrop      = document.getElementById('qr-modal-backdrop');
        const closeBtn      = document.getElementById('qr-modal-close');
        const cancelBtn     = document.getElementById('qr-cancel-btn');
        const confirmBtn    = document.getElementById('qr-confirm-btn');
        const form          = document.getElementById('booking-form');

        let currentDiscountPercentage = 0;

        // ── Ringkasan harga real-time ──
        function formatRupiah(n) {
            return 'Rp ' + n.toLocaleString('id-ID');
        }

        function updateSummary() {
            const dur      = parseInt(durSel.value) || 1;
            let total      = dur * PRICE_PER_HOUR;
            const courtTxt = courtSel.options[courtSel.selectedIndex]?.text ?? '—';
            const dateTxt  = mainDatePicker?.value || '—';
            const timeTxt  = timeSel.value || '—';

            let discount = 0;
            if (currentDiscountPercentage > 0) {
                discount = total * (currentDiscountPercentage / 100);
                total = total - discount;
            }

            document.getElementById('ps-court').textContent = courtSel.value ? courtTxt : '—';
            document.getElementById('ps-date').textContent  = dateTxt;
            document.getElementById('ps-time').textContent  = timeTxt + (timeSel.value ? ` (${dur} jam)` : '');
            
            const totalEl = document.getElementById('ps-total');
            if (discount > 0) {
                totalEl.innerHTML = `<del style="color: #94a3b8; font-size: 13px; margin-right: 8px;">${formatRupiah(dur * PRICE_PER_HOUR)}</del>${formatRupiah(total)}`;
            } else {
                totalEl.textContent = formatRupiah(total);
            }
        }

        [courtSel, timeSel, durSel].forEach(el => el?.addEventListener('change', updateSummary));
        promoInput?.addEventListener('input', () => {
            currentDiscountPercentage = 0;
            promoMsg.textContent = '';
            updateSummary();
        });

        checkPromoBtn?.addEventListener('click', async () => {
            const code = promoInput.value.trim();
            if (!code) return;

            checkPromoBtn.disabled = true;
            promoMsg.textContent = 'Mengecek...';
            promoMsg.style.color = 'inherit';

            try {
                const res = await fetch(`/api/promos/check?code=${encodeURIComponent(code)}`);
                const data = await res.json();

                if (data.valid) {
                    currentDiscountPercentage = data.discount_percentage;
                    promoMsg.textContent = `Promo diterapkan! Diskon ${data.discount_percentage}%`;
                    promoMsg.style.color = '#10b981';
                } else {
                    currentDiscountPercentage = 0;
                    promoMsg.textContent = data.message || 'Promo tidak valid.';
                    promoMsg.style.color = '#ef4444';
                }
            } catch (err) {
                currentDiscountPercentage = 0;
                promoMsg.textContent = 'Gagal mengecek promo.';
                promoMsg.style.color = '#ef4444';
            } finally {
                checkPromoBtn.disabled = false;
                updateSummary();
            }
        });

        updateSummary();

        // ── Modal Time Picker (Wheel Style) ──
        (function initModalTimePicker() {
            const ITEM_H = 38;

            const modalBackdrop = document.getElementById('tp-modal-backdrop');
            const openBtn       = document.getElementById('tp-modal-open-btn');
            const closeBtn      = document.getElementById('tp-modal-close');
            const cancelBtn     = document.getElementById('tp-modal-cancel-btn');
            const applyBtn      = document.getElementById('tp-modal-apply-btn');
            const triggerText   = document.getElementById('tp-trigger-text');
            const rangeEl       = document.getElementById('tp-range');
            const durEl         = document.getElementById('tp-dur');

            const startRoot     = document.getElementById('tp-start-picker');
            const endRoot       = document.getElementById('tp-end-picker');
            if (!modalBackdrop || !openBtn || !startRoot || !endRoot) return;

            function createPicker(root, initial24, onChange) {
                const hourCol   = root.querySelector('.hour');
                const periodCol = root.querySelector('.period');

                hourCol.innerHTML = '';
                for (let h = 1; h <= 12; h++) {
                    const d = document.createElement('div');
                    d.className = 'tp-item';
                    d.textContent = h;
                    hourCol.appendChild(d);
                }

                const idx = el => Math.round(el.scrollTop / ITEM_H);

                function paint(col) {
                    const i = idx(col);
                    col.querySelectorAll('.tp-item').forEach((it, n) => {
                        it.classList.toggle('active', n === i);
                        it.classList.toggle('near', Math.abs(n - i) === 1);
                    });
                }

                function get24() {
                    const h12 = idx(hourCol) + 1;
                    const pm  = idx(periodCol) === 1;
                    return (h12 % 12) + (pm ? 12 : 0);
                }

                function set24(v, smooth = true) {
                    let pm, h12;
                    if (v === 24) {
                        pm = false; // 12 AM considered midnight / 24:00
                        h12 = 12;
                    } else {
                        pm = v >= 12;
                        h12 = v % 12 || 12;
                    }
                    const behavior = smooth ? 'smooth' : 'auto';
                    hourCol.scrollTo({ top: (h12 - 1) * ITEM_H, behavior });
                    periodCol.scrollTo({ top: (pm ? 1 : 0) * ITEM_H, behavior });
                    paint(hourCol);
                    paint(periodCol);
                }

                function bind(col) {
                    let t;
                    col.addEventListener('scroll', () => {
                        paint(col);
                        clearTimeout(t);
                        t = setTimeout(() => {
                            paint(col);
                            onChange(get24());
                        }, 80);
                    });
                    col.querySelectorAll('.tp-item').forEach((it, n) => {
                        it.addEventListener('click', () => {
                            col.scrollTo({ top: n * ITEM_H, behavior: 'smooth' });
                        });
                    });
                }

                bind(hourCol);
                bind(periodCol);

                set24(initial24, false);

                return {
                    get: get24,
                    set: set24,
                    paint: () => { paint(hourCol); paint(periodCol); }
                };
            }

            const fmt24 = v24 => String(v24 % 24).padStart(2, '0') + ':00';
            const fmt12 = v24 => {
                const h = v24 % 24;
                const h12 = h % 12 || 12;
                return `${h12}:00 ${h >= 12 ? 'PM' : 'AM'}`;
            };

            let startPicker = null;
            let endPicker   = null;
            let lockWheel   = false;
            let tempStartH  = {{ $oldStartH }};
            let tempEndH    = {{ $oldEndH === 0 ? 24 : $oldEndH }};

            function refreshWheel() {
                if (lockWheel || !startPicker || !endPicker) return;
                const s = startPicker.get();
                let e = endPicker.get();
                let eff = e === 0 ? 24 : e;

                // Jam selesai harus setelah jam mulai
                if (eff <= s) {
                    e = (s + 1) % 24;
                    eff = e === 0 ? 24 : e;
                    lockWheel = true;
                    endPicker.set(e, true);
                    setTimeout(() => { lockWheel = false; }, 300);
                }

                tempStartH = s;
                tempEndH   = eff;

                const dur = Math.max(1, eff - s);
                if (rangeEl) {
                    rangeEl.textContent = `${fmt24(s)} – ${fmt24(eff === 24 ? 0 : eff)} (${fmt12(s)} – ${fmt12(eff === 24 ? 0 : eff)})`;
                }
                if (durEl) {
                    durEl.textContent = `${dur} jam`;
                }
            }

            startPicker = createPicker(startRoot, tempStartH, refreshWheel);
            endPicker   = createPicker(endRoot, tempEndH, refreshWheel);

            function updateTriggerDisplay(startH, dur) {
                const endH = (startH + dur) % 24;
                if (triggerText) {
                    triggerText.textContent = `${fmt24(startH)} – ${fmt24(endH)} (${dur} jam)`;
                }
            }

            function openModal() {
                modalBackdrop.classList.add('tp-modal-open');
                document.body.style.overflow = 'hidden';

                const curStart = timeSel?.value || '20:00';
                const curStartH = parseInt(curStart.split(':')[0]) || 20;
                const curDur    = parseInt(durSel?.value) || 1;
                let curEndH   = (curStartH + curDur) % 24;
                if (curEndH === 0) curEndH = 24;

                tempStartH = curStartH;
                tempEndH   = curEndH;

                requestAnimationFrame(() => {
                    startPicker.set(curStartH, false);
                    endPicker.set(curEndH, false);
                    refreshWheel();
                });
            }

            function closeModal() {
                modalBackdrop.classList.remove('tp-modal-open');
                document.body.style.overflow = '';
            }

            function applyTime() {
                const s   = tempStartH;
                const eff = tempEndH;
                const dur = Math.max(1, Math.min(eff - s, 8));

                timeSel.value = fmt24(s);
                if (durSel) {
                    durSel.value = dur;
                }

                updateTriggerDisplay(s, dur);

                timeSel.dispatchEvent(new Event('change'));
                durSel?.dispatchEvent(new Event('change'));

                closeModal();
            }

            openBtn.addEventListener('click', openModal);
            closeBtn?.addEventListener('click', closeModal);
            cancelBtn?.addEventListener('click', closeModal);
            applyBtn?.addEventListener('click', applyTime);

            modalBackdrop.addEventListener('click', function(e) {
                if (e.target === modalBackdrop) closeModal();
            });

            // Sinkronisasi jika durasi diganti di luar modal
            durSel?.addEventListener('change', function() {
                const s = parseInt((timeSel?.value || '20:00').split(':')[0]) || 20;
                const dur = parseInt(this.value) || 1;
                updateTriggerDisplay(s, dur);
            });

            // Initial trigger text
            updateTriggerDisplay(tempStartH, parseInt(durSel?.value) || 1);
        })();

        // ── Price Grid Selection Sync ──
        const priceItems = document.querySelectorAll('.db-price-item');
        priceItems.forEach(item => {
            item.addEventListener('click', () => {
                const hours = item.getAttribute('data-hours');
                if (durSel && hours) {
                    durSel.value = hours;
                    durSel.dispatchEvent(new Event('change'));
                }
            });
        });

        function updatePriceGridHighlight() {
            const currentHours = durSel?.value;
            priceItems.forEach(item => {
                const hours = item.getAttribute('data-hours');
                if (hours === currentHours) {
                    item.classList.add('db-price-item--highlight');
                } else {
                    item.classList.remove('db-price-item--highlight');
                }
            });
        }
        durSel?.addEventListener('change', updatePriceGridHighlight);
        updatePriceGridHighlight();

        // ── Dynamic Slot Grid (AJAX) ──
        // bookingMap dari server (initial load)
        let currentBookingMap = @json($bookingMap);

        function renderSlotGrids(bookingMap) {
            // Setiap .db-slot-grid di-render ulang berdasarkan bookingMap baru
            document.querySelectorAll('[data-court-slot-grid]').forEach(grid => {
                const courtId = grid.getAttribute('data-court-slot-grid');
                grid.innerHTML = ALL_SLOTS.map(slot => {
                    const status = bookingMap[courtId]?.[slot] ?? null;
                    const label  = status ? (status === 'confirmed' ? 'Dikonfirmasi' : 'Pending') : 'Tersedia';
                    const cls    = status ? 'db-slot-booked' : 'db-slot-free';
                    return `<div class="db-slot ${cls}">
                        <div class="db-slot-time">${slot}</div>
                        <div class="db-slot-label">${label}</div>
                    </div>`;
                }).join('');
            });

            // Update stat "Slot Tersedia"
            updateSlotStats(bookingMap);
        }

        function updateSlotStats(bookingMap) {
            const courtCount = document.querySelectorAll('[data-court-slot-grid]').length;
            const totalSlots = ALL_SLOTS.length * courtCount;
            let bookedCount  = 0;
            Object.values(bookingMap).forEach(slots => { bookedCount += Object.keys(slots).length; });
            const available  = Math.max(totalSlots - bookedCount, 0);

            const valEl = document.querySelector('.db-stat-value:not(.db-stat-date)');
            if (valEl) {
                // Cari stat card "Slot Tersedia" secara spesifik
                document.querySelectorAll('.db-stat-card').forEach(card => {
                    if (card.querySelector('.db-stat-label')?.textContent.trim() === 'Slot Tersedia') {
                        card.querySelector('.db-stat-value').textContent = available;
                        card.querySelector('.db-stat-sub').textContent   = `Dari ${totalSlots} total slot`;
                    }
                });
            }
        }

        function setSlotGridLoading(loading) {
            document.querySelectorAll('[data-court-slot-grid]').forEach(grid => {
                if (loading) {
                    grid.style.opacity = '0.4';
                    grid.style.pointerEvents = 'none';
                } else {
                    grid.style.opacity = '';
                    grid.style.pointerEvents = '';
                }
            });
        }

        async function fetchSlotsForDate(date) {
            if (!date) return;
            if (date < TODAY) {
                date = TODAY;
                if (mainDatePicker) mainDatePicker.value = TODAY;
                if (bookingDate) bookingDate.value = TODAY;
            }
            setSlotGridLoading(true);

            // Update tanggal yang tampil di stat card
            const dateStatEl = document.querySelector('.db-stat-date');
            if (dateStatEl && date) {
                try {
                    const d = new Date(date + 'T00:00:00');
                    dateStatEl.textContent = d.toLocaleDateString('id-ID', {day:'2-digit', month:'short', year:'numeric'});
                    const dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
                    const subEl = dateStatEl.closest('.db-stat-body')?.querySelector('.db-stat-sub');
                    if (subEl) subEl.textContent = dayNames[d.getDay()];
                } catch(e) {}
            }

            try {
                const res = await fetch(`/api/slots?date=${encodeURIComponent(date)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!res.ok) throw new Error('Network error');
                const bookingMap = await res.json();
                currentBookingMap = bookingMap;
                renderSlotGrids(bookingMap);
            } catch (err) {
                console.error('Gagal memuat slot:', err);
            } finally {
                setSlotGridLoading(false);
            }
        }

        // Saat tanggal di date picker atas berubah:
        // 1) Cegah pemilihan tanggal yang sudah lewat
        // 2) Sync ke hidden input form booking
        // 3) Fetch slot grid baru via AJAX
        // 4) Update ringkasan harga
        mainDatePicker?.addEventListener('change', function () {
            if (this.value && this.value < TODAY) {
                Swal.fire({
                    title: 'Peringatan',
                    text: 'Tidak dapat memilih tanggal yang sudah lewat.',
                    icon: 'warning',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                this.value = TODAY;
            }
            const date = this.value || TODAY;
            if (bookingDate) bookingDate.value = date;
            fetchSlotsForDate(date);
            updateSummary();
        });

        // ── QR Modal & Countdown Timer (1 Menit 30 Detik) ──
        let countdownTimer = null;
        let timeRemaining = 90; // 90 detik = 1 menit 30 detik
        const TOTAL_PAYMENT_TIME = 90;

        let paymentDeadline = null;
        let paymentExpired = false;
        let heldBookingId = null;

        const modalFileInput  = document.getElementById('modal_payment_proof');
        const hiddenFileInput = document.getElementById('hidden_payment_proof');
        const dropzone        = document.getElementById('qr-dropzone');
        const dropzoneEmpty   = document.getElementById('qr-dropzone-empty');
        const dropzonePrev    = document.getElementById('qr-dropzone-preview');
        const previewImg      = document.getElementById('qr-preview-img');
        const previewName     = document.getElementById('qr-preview-name');
        const previewSize     = document.getElementById('qr-preview-size');
        const removeFileBtn   = document.getElementById('qr-preview-remove');

        async function releaseHeldSlot() {
            if (!heldBookingId) return;
            const bId = heldBookingId;
            heldBookingId = null;
            const hiddenIdInput = document.getElementById('held_booking_id');
            if (hiddenIdInput) hiddenIdInput.value = '';

            try {
                await fetch('/api/bookings/release', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ booking_id: bId })
                });
            } catch (e) {}

            fetchSlotsForDate(mainDatePicker ? mainDatePicker.value : TODAY);
        }

        function formatFileSize(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        function updateTimerDisplay() {
            const min = Math.floor(Math.max(0, timeRemaining) / 60);
            const sec = Math.max(0, timeRemaining) % 60;
            const formatted = String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
            const clockEl = document.getElementById('qr-timer-countdown');
            const bannerEl = document.getElementById('qr-timer-banner');

            if (clockEl) clockEl.textContent = formatted;

            if (bannerEl) {
                if (timeRemaining <= 30) {
                    bannerEl.classList.add('qr-timer-urgent');
                } else {
                    bannerEl.classList.remove('qr-timer-urgent');
                }
            }
        }

        function handlePaymentExpired() {
            if (paymentExpired) return;
            paymentExpired = true;

            stopCountdown();
            updateTimerDisplay();
            closeModal(false);

            Swal.fire({
                title: 'Waktu Pembayaran Habis!',
                text: 'Batas waktu 1 menit 30 detik untuk menyelesaikan pembayaran telah berakhir. Slot telah dibebaskan kembali.',
                icon: 'warning',
                confirmButtonColor: '#2563eb',
                animation: false
            });
        }

        function startCountdown() {
            stopCountdown();

            if (paymentDeadline === null) {
                paymentDeadline = Date.now() + (TOTAL_PAYMENT_TIME * 1000);
            }

            timeRemaining = Math.max(0, Math.ceil((paymentDeadline - Date.now()) / 1000));
            updateTimerDisplay();

            if (timeRemaining <= 0) {
                handlePaymentExpired();
                return;
            }

            countdownTimer = setInterval(() => {
                timeRemaining = Math.max(0, Math.ceil((paymentDeadline - Date.now()) / 1000));
                updateTimerDisplay();

                if (timeRemaining <= 0) {
                    handlePaymentExpired();
                }
            }, 1000);
        }

        function stopCountdown() {
            if (countdownTimer) {
                clearInterval(countdownTimer);
                countdownTimer = null;
            }
        }

        function handleFileSelection(file) {
            if (!file) return;

            // Validasi tipe file gambar
            if (!file.type.match(/^image\/(jpeg|png|webp|jpg)$/i)) {
                Swal.fire({
                    title: 'Format Tidak Sesuai',
                    text: 'Bukti pembayaran harus berupa gambar (JPG, PNG, atau WEBP).',
                    icon: 'error',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                resetModalUpload();
                return;
            }

            // Validasi ukuran maksimal 5MB
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire({
                    title: 'Ukuran Melebihi Batas',
                    text: 'Ukuran foto bukti transfer maksimal 5MB.',
                    icon: 'error',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                resetModalUpload();
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                if (previewImg) previewImg.src = e.target.result;
                if (previewName) previewName.textContent = file.name;
                if (previewSize) previewSize.textContent = formatFileSize(file.size);
                if (dropzoneEmpty) dropzoneEmpty.style.display = 'none';
                if (dropzonePrev) dropzonePrev.style.display = 'flex';
            };
            reader.readAsDataURL(file);

            // Sync ke hidden input form
            try {
                const dt = new DataTransfer();
                dt.items.add(file);
                if (hiddenFileInput) hiddenFileInput.files = dt.files;
            } catch (err) {}
        }

        function resetModalUpload() {
            if (modalFileInput) modalFileInput.value = '';
            if (hiddenFileInput) hiddenFileInput.value = '';
            if (previewImg) previewImg.src = '';
            if (dropzoneEmpty) dropzoneEmpty.style.display = 'flex';
            if (dropzonePrev) dropzonePrev.style.display = 'none';
            if (dropzone) dropzone.classList.remove('qr-dropzone-error');
        }

        modalFileInput?.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                handleFileSelection(this.files[0]);
            }
        });

        removeFileBtn?.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            resetModalUpload();
        });

        // Drag & Drop
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(name => {
                dropzone.addEventListener(name, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('qr-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(name => {
                dropzone.addEventListener(name, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('qr-dragover');
                });
            });
            dropzone.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                    const file = e.dataTransfer.files[0];
                    try {
                        const dt = new DataTransfer();
                        dt.items.add(file);
                        if (modalFileInput) modalFileInput.files = dt.files;
                    } catch (err) {}
                    handleFileSelection(file);
                }
            });
        }

        function openModal() {
            const dur     = parseInt(durSel.value) || 1;
            let total     = dur * PRICE_PER_HOUR;
            let discountHtml = '';

            if (currentDiscountPercentage > 0) {
                const discount = total * (currentDiscountPercentage / 100);
                total = total - discount;
                discountHtml = `<div class="qr-summary-row" style="color: #10b981;"><span>Diskon Promo (${currentDiscountPercentage}%)</span><strong>-${formatRupiah(discount)}</strong></div>`;
            }

            const court   = courtSel.options[courtSel.selectedIndex]?.text ?? '-';
            const dateVal = mainDatePicker?.value ?? '-';
            document.getElementById('qr-summary').innerHTML = `
                <div class="qr-summary-row"><span>Lapangan</span><strong>${court}</strong></div>
                <div class="qr-summary-row"><span>Tanggal</span><strong>${dateVal}</strong></div>
                <div class="qr-summary-row"><span>Jam</span><strong>${timeSel.value} (${dur} jam)</strong></div>
                ${discountHtml}
                <div class="qr-summary-row qr-summary-total"><span>Total Bayar</span><strong>${formatRupiah(total)}</strong></div>
            `;

            resetModalUpload();
            startCountdown();

            backdrop.classList.add('qr-modal-open');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(isSubmitting = false) {
            stopCountdown();
            backdrop.classList.remove('qr-modal-open');
            document.body.style.overflow = '';

            if (!isSubmitting) {
                releaseHeldSlot();
            }
        }

        async function handleOpenQr() {
            if (mainDatePicker && mainDatePicker.value && mainDatePicker.value < TODAY) {
                Swal.fire({
                    title: 'Peringatan',
                    text: 'Tidak dapat memesan untuk tanggal yang sudah lewat.',
                    icon: 'warning',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                mainDatePicker.value = TODAY;
                if (bookingDate) bookingDate.value = TODAY;
                updateSummary();
                return;
            }

            if (!courtSel.value || !mainDatePicker.value || !timeSel.value || !durSel.value) {
                Swal.fire({
                    title: 'Peringatan',
                    text: 'Lengkapi form booking terlebih dahulu.',
                    icon: 'warning',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                return;
            }

            // Validasi lokal: Cek bentrok dengan slot yang sudah terisi di grid
            const courtId = courtSel.value;
            const startH  = parseInt(timeSel.value.split(':')[0]) || 0;
            const dur     = parseInt(durSel.value) || 1;

            for (let h = startH; h < startH + dur; h++) {
                const sStr = String(h).padStart(2, '0') + ':00';
                if (currentBookingMap[courtId]?.[sStr]) {
                    Swal.fire({
                        title: 'Slot Tidak Tersedia',
                        text: `Slot jam ${sStr} sudah terisi atau sedang dalam proses transaksi. Silakan pilih jadwal lain.`,
                        icon: 'warning',
                        confirmButtonColor: '#2563eb',
                        animation: false
                    });
                    return;
                }
            }

            // Kunci slot secara atomic di backend (mencegah race condition)
            openQrBtn.disabled = true;
            const origHtml = openQrBtn.innerHTML;
            openQrBtn.textContent = 'Mengamankan slot...';

            try {
                const res = await fetch('/api/bookings/hold', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        court_id: courtId,
                        date: mainDatePicker.value,
                        start_time: timeSel.value,
                        duration_hours: dur,
                        promo_code: promoInput ? promoInput.value.trim() : null
                    })
                });

                const data = await res.json();

                if (!res.ok || !data.success) {
                    Swal.fire({
                        title: 'Slot Tidak Dapat Dipilih',
                        text: data.message || 'Slot lapangan ini sedang dalam proses transaksi oleh user lain. Silakan pilih jadwal lain.',
                        icon: 'warning',
                        confirmButtonColor: '#2563eb',
                        animation: false
                    });
                    fetchSlotsForDate(mainDatePicker.value);
                    return;
                }

                heldBookingId = data.booking_id;
                const hiddenInput = document.getElementById('held_booking_id');
                if (hiddenInput) hiddenInput.value = data.booking_id;

                paymentDeadline = null;
                paymentExpired = false;

                openModal();
                fetchSlotsForDate(mainDatePicker.value);
            } catch (err) {
                Swal.fire({
                    title: 'Kesalahan Jaringan',
                    text: 'Gagal mengamankan slot. Silakan periksa koneksi internet Anda.',
                    icon: 'error',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
            } finally {
                openQrBtn.disabled = false;
                openQrBtn.innerHTML = origHtml;
            }
        }

        openQrBtn?.addEventListener('click', handleOpenQr);
        closeBtn?.addEventListener('click', () => closeModal(false));
        cancelBtn?.addEventListener('click', () => closeModal(false));
        backdrop?.addEventListener('click', function(e) {
            if (e.target === backdrop) closeModal(false);
        });

        // ── Real-Time Slot Polling (Update Slot Jika Ada Transaksi dari User Lain) ──
        setInterval(() => {
            if (document.visibilityState === 'visible' && !backdrop.classList.contains('qr-modal-open')) {
                fetchSlotsForDate(mainDatePicker ? mainDatePicker.value : TODAY);
            }
        }, 5000);

        // Tombol "Upload Bukti Bayar" di modal → validasi bukti & submit
        confirmBtn?.addEventListener('click', function () {
            if (timeRemaining <= 0) {
                Swal.fire({
                    title: 'Waktu Pembayaran Habis',
                    text: 'Batas waktu 1 menit 30 detik telah habis. Silakan ulangi pemesanan Anda.',
                    icon: 'error',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                closeModal(false);
                return;
            }

            if (mainDatePicker && mainDatePicker.value && mainDatePicker.value < TODAY) {
                Swal.fire({
                    title: 'Peringatan',
                    text: 'Tidak dapat memesan untuk tanggal yang sudah lewat.',
                    icon: 'warning',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                mainDatePicker.value = TODAY;
                if (bookingDate) bookingDate.value = TODAY;
                updateSummary();
                closeModal(false);
                return;
            }

            const hasFile = (modalFileInput && modalFileInput.files && modalFileInput.files.length > 0) ||
                            (hiddenFileInput && hiddenFileInput.files && hiddenFileInput.files.length > 0);

            if (!hasFile) {
                Swal.fire({
                    title: 'Bukti Pembayaran Wajib Diunggah',
                    text: 'Silakan upload bukti transfer pembayaran Anda terlebih dahulu.',
                    icon: 'warning',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                if (dropzone) {
                    dropzone.classList.add('qr-dropzone-error');
                    setTimeout(() => dropzone.classList.remove('qr-dropzone-error'), 1000);
                }
                return;
            }

            // Pastikan file tersambung ke form
            if (modalFileInput && modalFileInput.files && modalFileInput.files[0]) {
                try {
                    const dt = new DataTransfer();
                    dt.items.add(modalFileInput.files[0]);
                    hiddenFileInput.files = dt.files;
                } catch (e) {
                    modalFileInput.name = 'payment_proof';
                    form.appendChild(modalFileInput);
                }
            }

            stopCountdown();
            closeModal(true);
            form.submit();
        });

        // Validasi submit form langsung
        form?.addEventListener('submit', function (e) {
            const selectedDate = bookingDate?.value || mainDatePicker?.value;
            if (selectedDate && selectedDate < TODAY) {
                e.preventDefault();
                Swal.fire({
                    title: 'Peringatan',
                    text: 'Tidak dapat memesan untuk tanggal yang sudah lewat.',
                    icon: 'warning',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                if (mainDatePicker) mainDatePicker.value = TODAY;
                if (bookingDate) bookingDate.value = TODAY;
                updateSummary();
                return false;
            }

            const hasFile = (hiddenFileInput && hiddenFileInput.files && hiddenFileInput.files.length > 0) ||
                            (modalFileInput && modalFileInput.files && modalFileInput.files.length > 0);
            if (!hasFile) {
                e.preventDefault();
                Swal.fire({
                    title: 'Bukti Bayar Diperlukan',
                    text: 'Silakan klik tombol "Lihat QR & Booking" dan upload bukti pembayaran.',
                    icon: 'warning',
                    confirmButtonColor: '#2563eb',
                    animation: false
                });
                return false;
            }
        });
    })();
    </script>
@endsection

