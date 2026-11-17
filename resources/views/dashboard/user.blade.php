<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pilih Jam Sewa Lapangan</title>

<style>
  :root {
    --bg: #000;
    --card: #111113;
    --text: #fff;
    --muted: #8e8e93;
    --faint: #48484a;
    --band: rgba(255, 255, 255, 0.09);
    --accent: #30d158;
    --item-h: 44px;       /* tinggi satu baris */
    --visible: 5;         /* jumlah baris yang tampak */
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Roboto, sans-serif;
    display: flex;
    justify-content: center;
    padding: 24px 16px;
  }

  .wrap { width: 100%; max-width: 420px; display: grid; gap: 16px; align-content: start; }

  h1 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }

  /* ---------- Section ---------- */
  .section {
    background: var(--card);
    border-radius: 20px;
    padding: 16px 12px 8px;
  }
  .section-title {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--muted);
    padding: 0 8px 4px;
  }

  /* ---------- Picker ---------- */
  .picker {
    position: relative;
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    height: calc(var(--item-h) * var(--visible));
  }

  /* Pita highlight di tengah */
  .picker::before {
    content: "";
    position: absolute;
    left: 0; right: 0;
    top: 50%;
    height: var(--item-h);
    transform: translateY(-50%);
    background: var(--band);
    border-radius: 12px;
    pointer-events: none;
  }

  .col {
    position: relative;
    height: 100%;
    overflow-y: auto;
    scroll-snap-type: y mandatory;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
    /* ruang kosong atas-bawah supaya item pertama/terakhir bisa di tengah */
    padding: calc(var(--item-h) * 2) 0;
    /* efek memudar di atas & bawah */
    -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 30%, #000 70%, transparent 100%);
            mask-image: linear-gradient(to bottom, transparent 0, #000 30%, #000 70%, transparent 100%);
  }
  .col::-webkit-scrollbar { display: none; }

  .item {
    height: var(--item-h);
    display: flex;
    align-items: center;
    justify-content: center;
    scroll-snap-align: center;
    font-size: 26px;
    font-weight: 600;
    color: var(--faint);
    font-variant-numeric: tabular-nums;
    transition: color .15s, transform .15s;
    user-select: none;
    cursor: pointer;
  }
  .item.near   { color: var(--muted); }
  .item.active { color: var(--text); transform: scale(1.12); }

  /* Kolom menit: terkunci, tidak bisa di-scroll / diubah */
  .col.locked {
    overflow: hidden;
    pointer-events: none;
    padding: calc(var(--item-h) * 2) 0;
    cursor: not-allowed;
  }
  .col.locked .item { color: var(--text); transform: scale(1.12); cursor: not-allowed; }

  /* Kolom AM/PM hanya 2 item */
  .col.period .item { font-size: 24px; }

  /* ---------- Ringkasan ---------- */
  .summary {
    background: var(--card);
    border-radius: 20px;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .summary .label { color: var(--muted); font-size: 13px; }
  .summary .range { font-size: 18px; font-weight: 700; margin-top: 2px; }
  .summary .dur {
    background: rgba(48, 209, 88, .15);
    color: var(--accent);
    font-weight: 700;
    padding: 8px 14px;
    border-radius: 999px;
    font-size: 14px;
  }

  .note { color: var(--muted); font-size: 12px; text-align: center; }
</style>
</head>

<body>
  <div class="wrap">
    <h1>Pilih Jam Sewa</h1>

    <!-- ===== JAM MULAI ===== -->
    <section class="section" id="start">
      <div class="section-title">Jam Mulai</div>
      <div class="picker">
        <div class="col hour"   aria-label="Jam mulai"></div>
        <div class="col locked" aria-label="Menit (terkunci)"><div class="item">00</div></div>
        <div class="col period" aria-label="AM/PM mulai">
          <div class="item">AM</div>
          <div class="item">PM</div>
        </div>
      </div>
    </section>

    <!-- ===== JAM SELESAI ===== -->
    <section class="section" id="end">
      <div class="section-title">Jam Selesai</div>
      <div class="picker">
        <div class="col hour"   aria-label="Jam selesai"></div>
        <div class="col locked" aria-label="Menit (terkunci)"><div class="item">00</div></div>
        <div class="col period" aria-label="AM/PM selesai">
          <div class="item">AM</div>
          <div class="item">PM</div>
        </div>
      </div>
    </section>

    <div class="summary">
      <div>
        <div class="label">Jadwal sewa</div>
        <div class="range" id="range">-</div>
      </div>
      <div class="dur" id="dur">-</div>
    </div>

    <p class="note">Menit selalu :00 — sewa dihitung per kelipatan 1 jam.</p>
  </div>

<script>
  const ITEM_H = 44;

  /* Buat 1 picker (hour + period). Menit statis "00" di HTML. */
  function createPicker(root, initial24, onChange) {
    const hourCol   = root.querySelector('.hour');
    const periodCol = root.querySelector('.period');

    // isi jam 1..12
    for (let h = 1; h <= 12; h++) {
      const d = document.createElement('div');
      d.className = 'item';
      d.textContent = h;
      hourCol.appendChild(d);
    }

    const idx = el => Math.round(el.scrollTop / ITEM_H);

    function paint(col) {
      const i = idx(col);
      [...col.querySelectorAll('.item')].forEach((it, n) => {
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
      const pm  = v >= 12;
      const h12 = v % 12 || 12;
      const behavior = smooth ? 'smooth' : 'auto';
      hourCol.scrollTo({ top: (h12 - 1) * ITEM_H, behavior });
      periodCol.scrollTo({ top: (pm ? 1 : 0) * ITEM_H, behavior });
    }

    function bind(col) {
      let t;
      col.addEventListener('scroll', () => {
        paint(col);
        clearTimeout(t);
        t = setTimeout(() => onChange(get24()), 120);   // setelah berhenti scroll
      });
      // klik item => langsung pilih
      col.querySelectorAll('.item').forEach((it, n) =>
        it.addEventListener('click', () => col.scrollTo({ top: n * ITEM_H, behavior: 'smooth' })));
    }

    bind(hourCol);
    bind(periodCol);

    set24(initial24, false);
    paint(hourCol);
    paint(periodCol);

    return { get: get24, set: set24 };
  }

  /* ---------- Logika penyewaan ---------- */
  const fmt = v24 => {
    const h = v24 % 24;
    const h12 = h % 12 || 12;
    return `${h12}:00 ${h >= 12 ? 'PM' : 'AM'}`;
  };

  let start, end;
  let lock = false;

  function refresh() {
    if (lock || !start || !end) return;
    const s = start.get();
    let e = end.get();
    let eff = e === 0 ? 24 : e;           // 12 AM dianggap tengah malam (akhir hari)

    // jam selesai harus setelah jam mulai
    if (eff <= s) {
      e = (s + 1) % 24;
      eff = e === 0 ? 24 : e;
      lock = true;
      end.set(e);
      setTimeout(() => (lock = false), 400);
    }

    document.getElementById('range').textContent = `${fmt(s)} – ${fmt(eff)}`;
    document.getElementById('dur').textContent   = `${eff - s} jam`;
  }

  start = createPicker(document.getElementById('start'), 20, refresh); // 8 PM
  end   = createPicker(document.getElementById('end'),   21, refresh); // 9 PM
  refresh();
</script>
</body>
</html>
