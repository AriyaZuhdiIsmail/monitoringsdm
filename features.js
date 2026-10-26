/* =====================================================================
   features.js — Penambahan fitur (modular). Dimuat SETELAH app.js.

   Prinsip: app.js dan seluruh logika lama TIDAK ditulis ulang. Fitur baru
   dipasang dengan cara membungkus (wrap) fungsi yang sudah ada:
     - normEmp     -> menambah field pendidikan, agama, TMT, end status
     - buildSotk   -> menambah field position pada baris SOTK
     - V.dash      -> menambah section Pendidikan & Agama di Dashboard
     - V.mon1      -> kolom Pendidikan/Agama + filter Person Grade
     - V.mon3      -> kolom TMT / End Status / Sisa Hari (Non-Definitif)
     - V.sotk0     -> kolom Position di SOTK Explorer
   Versi asli tiap view disimpan di V._legacy.<nama> sebagai cadangan dan
   dipakai otomatis bila modul ini gagal berjalan.

   Daftar fitur:
     1. Non-Definitif: End Status = TMT Position + 3 bulan
     2. Pendidikan Terakhir & Agama (Dashboard + tabel Monitoring Karyawan)
     3. Filter Person Grade (Monitoring Karyawan)
     4. Kolom Position pada SOTK dasar
   ===================================================================== */
(function () {
  'use strict';

  const UNK = 'Tidak Diketahui';
  V._legacy = V._legacy || {};

  /* ------------------------------------------------------------------
     Util
     ------------------------------------------------------------------ */
  // Tanggal kalender (jam 00:00 lokal). SheetJS memberi nilai ±23:59:48 hari sebelumnya
  // pada zona waktu tertentu (mis. Asia/Jakarta); +12 jam lalu dipotong ke tanggal menjamin hari yang benar.
  function calDay(d) {
    if (!(d instanceof Date) || isNaN(d)) return null;
    const t = new Date(d.getTime() + 12 * 3600e3);
    return new Date(t.getFullYear(), t.getMonth(), t.getDate());
  }
  // Tambah n bulan kalender; bila tanggal tujuan tidak ada (mis. 31 Mei + 1 bln) dipotong ke akhir bulan.
  function addMonths(d, n) {
    if (!d) return null;
    const last = new Date(d.getFullYear(), d.getMonth() + n + 1, 0).getDate();
    return new Date(d.getFullYear(), d.getMonth() + n, Math.min(d.getDate(), last));
  }
  const z2 = n => String(n).padStart(2, '0');
  const fmtDay = d => (d ? `${d.getFullYear()}-${z2(d.getMonth() + 1)}-${z2(d.getDate())}` : '');
  const daysFromToday = d => {
    const t = new Date(); const t0 = new Date(t.getFullYear(), t.getMonth(), t.getDate());
    return Math.round((d - t0) / 864e5);
  };
  const isNonDef = e => { const p = String(e.pt || '').trim().toLowerCase(); return !!p && p !== 'definitif' && p !== 'tidak diketahui'; };
  const pct = (a, b) => (b ? Math.round(a / b * 100) + '%' : '0%');
  const fm = n => Number(n).toLocaleString('id');

  /* ------------------------------------------------------------------
     Pendidikan & Agama — normalisasi
     ------------------------------------------------------------------ */
  const EDU_ORDER = ['SMA Sederajat', 'D1', 'D2', 'D3', 'D4', 'S1', 'S2', 'S3'];
  function normEdu(v) {
    const t = N(v).replace(/\s+/g, ' ').toUpperCase();
    if (!t) return '';
    if (/^(SMA|SMU|SMK|SLTA|MA)\b/.test(t)) return 'SMA Sederajat';
    return t;
  }
  function normRel(v) {
    const t = N(v).replace(/\s+/g, ' ').toLowerCase();
    return t ? t.replace(/(^|\s)\S/g, c => c.toUpperCase()) : '';
  }
  const eduRank = k => { const i = EDU_ORDER.indexOf(k); return i < 0 ? 99 : i; };

  /* ------------------------------------------------------------------
     [1,2] Wrap normEmp — tambah field baru pada objek karyawan
        e.edu / e.eduOrg  : pendidikan terakhir (level) & institusi
        e.rel             : agama
        e.tmt             : TMT Position (tanggal)
        e.endStatus       : TMT Position + 3 bulan (hanya non-definitif)
     ------------------------------------------------------------------ */
  const _normEmp = normEmp;
  window.normEmp = function (rows) {
    const out = _normEmp(rows);
    try {
      const clean = validEmployeeRows(rows).rows; // urutan sama dengan hasil normEmp
      if (clean.length === out.length) {
        out.forEach((e, i) => {
          const r = nk(clean[i]);
          e.edu = normEdu(r['LAST EDU LEVEL'] || r['PENDIDIKAN TERAKHIR'] || r['PENDIDIKAN']);
          e.eduOrg = N(r['LAST EDU ORG'] || r['INSTITUSI PENDIDIKAN']);
          e.rel = normRel(r['RELIGION'] || r['AGAMA']);
          e.tmt = calDay(toD(r['TMT POSITION']));
          e.endStatus = isNonDef(e) && e.tmt ? addMonths(e.tmt, 3) : null;
        });
      }
    } catch (err) { console.error('[features] gagal memperkaya data karyawan', err); }
    return out;
  };

  /* ------------------------------------------------------------------
     [4] Position pada SOTK
     Sumber utama: kolom POSITION di sheet SOTK (data.js / master terbaru).
     Cadangan (bila master lama tanpa kolom POSITION): diturunkan dari
     POSITION_GRADE_RULES + kelas cabang, aturan sama dengan build_data.py.
     ------------------------------------------------------------------ */
  const TIERED = ['AMNG', 'PSR', 'SAO', 'AO', 'PAGN'];
  function tierText(rules, key) {
    const names = [];
    (rules || []).filter(r => N(r.SOTK_JOB_KEY) === key).forEach(r => {
      const name = N(r.POSITION_NAME_PREFIX_MATCH || r['POSITION NAME / LEVEL'] || r.POSITION_NAME_PREFIX);
      const grade = N(r.FALLBACK_JOB_GRADE || r['JOB GRADE TARGET'] || r.JOB_GRADE_PASANGAN);
      if (!name || /fallback lama/i.test(name)) return;
      const label = grade ? `${name} (JG ${grade})` : name;
      if (!names.includes(label)) names.push(label);
    });
    return names.join(' \u00b7 ');
  }
  function derivePositionC(r, rules) {
    const job = N(r['JOB CODE']), jn = N(r['JOB NAME ']) || N(r['JOB NAME']), kelas = N(r['KELAS CABANG']);
    if (TIERED.includes(job)) return tierText(rules, job) || jn;
    if ((job === 'PNC' || job === 'MBI') && /^[AB]$/.test(kelas)) return `${jn} ${kelas}`;
    if (job === 'KUPC' && /^[AB]$/.test(kelas)) return `Kepala ${/^(UPS|UPKS)/i.test(N(r['NAMA UNIT KERJA'])) ? 'UPS' : 'UPC'} ${kelas}`;
    return jn;
  }
  function attachPosition(sh) {
    const rules = (sh.POSITION_GRADE_RULES && sh.POSITION_GRADE_RULES.length ? sh.POSITION_GRADE_RULES : (window.SOTK_PRELOAD && window.SOTK_PRELOAD.POSITION_GRADE_RULES_H
      ? window.SOTK_PRELOAD.POSITION_GRADE_RULES.map(a => Object.fromEntries(window.SOTK_PRELOAD.POSITION_GRADE_RULES_H.map((h, i) => [h, a[i]]))) : []));
    const mapOf = (rows, fallback) => {
      const m = new Map();
      (rows || []).forEach(r => { const k = keyNorm(r.FORMASI_KEY); if (k && !m.has(k)) m.set(k, N(r.POSITION) || (fallback ? fallback(r) : '')); });
      return m;
    };
    const mW = mapOf(sh.SOTK_WILAYAH, r => N(r['JOB NAME']));
    const mA = mapOf(sh.SOTK_AREA, r => N(r['JOB NAME']));
    const mC = mapOf(sh.SOTK_CABANG, r => derivePositionC(r, rules));
    S.sotk.W.forEach(r => { r.position = mW.get(r.key) || r.jobName; });
    S.sotk.A.forEach(r => { r.position = mA.get(r.key) || r.jobName; });
    S.sotk.C.forEach(r => { r.position = mC.get(r.key) || r.jobName; });
  }
  const _buildSotk = buildSotk;
  window.buildSotk = function (sh) {
    _buildSotk(sh);
    try { attachPosition(sh); } catch (err) { console.error('[features] gagal memasang Position SOTK', err); }
  };
  const posOf = q => q.position || q.jobName || '-';

  V._legacy.sotk0 = V.sotk0;
  V.sotk0 = function () {
    try {
      shell();
      panel({
        recs: l => S.sotk[l],
        cb: (r, l) => table($('#out'), [
          ...locCols(l),
          ...(l === 'A' ? [['Klasifikasi Area', q => esc(q.klas || '-')]] : []),
          ['Kode Jabatan', q => esc(q.job || '-')],
          ['Position', q => esc(posOf(q))],
          ['Job Grade', q => q.gmin === q.gmax ? q.gmin : q.gmin + '\u2013' + q.gmax],
          ['Rentang PG Sesuai (Pasal 15)', q => { const x = pgRangeOfJob(q.gmin, q.gmax); return x ? fmtR(x[0], x[1]) : '-'; }],
          ['Corporate Title', q => esc(titleOf(q.gmin) || (q.gmin !== q.gmax ? titleOf(q.gmax) : '') || '-')],
          ['Formasi', q => q.f]
        ], r, { name: 'SOTK_Explorer' })
      });
    } catch (err) { console.error('[features] sotk0 fallback ke versi lama', err); V._legacy.sotk0(); }
  };

  /* ------------------------------------------------------------------
     [2] Dashboard — section Pendidikan Terakhir & Agama
     ------------------------------------------------------------------ */
  function countBy(list, f) { return list.reduce((m, e) => { const k = f(e) || UNK; m[k] = (m[k] || 0) + 1; return m; }, {}); }
  function topKnown(m) { return Object.entries(m).filter(([k]) => k !== UNK).sort((a, b) => b[1] - a[1])[0]; }

  function renderEduRel(target = 'dashboard', empOverride) {
    const emp = Array.isArray(empOverride) ? empOverride : S.emp, tot = emp.length;
    const edu = countBy(emp, e => e.edu), rel = countBy(emp, e => e.rel);
    const eduKnown = tot - (edu[UNK] || 0), relKnown = tot - (rel[UNK] || 0);
    const topE = topKnown(edu), topR = topKnown(rel);
    const sarjana = ['S1', 'S2', 'S3'].reduce((a, k) => a + (edu[k] || 0), 0);
    const unkAny = emp.filter(e => !e.edu || !e.rel).length;

    const html = `<div id="eduRelSec"><div class="section-t"><h3>Pendidikan Terakhir &amp; Agama</h3><p>Dari kolom LAST EDU LEVEL dan RELIGION pada data karyawan.${unkAny ? ` ${fm(unkAny)} karyawan belum lengkap datanya dan dikelompokkan sebagai &ldquo;${UNK}&rdquo;.` : ''}</p></div>
      <div class="grid">${kpi('Pendidikan Terbanyak', topE ? `${esc(topE[0])} \u00b7 ${pct(topE[1], eduKnown)}` : '-')}${kpi('Pendidikan S1 ke Atas', eduKnown ? `${fm(sarjana)} (${pct(sarjana, eduKnown)})` : '-')}${kpi('Agama Mayoritas', topR ? `${esc(topR[0])} \u00b7 ${pct(topR[1], relKnown)}` : '-')}${kpi('Data Belum Lengkap', fm(unkAny))}</div>
      <div class="two dashboard-two">${chartCard('c6', 'Komposisi Pendidikan Terakhir')}${chartCard('c7', 'Komposisi Agama')}</div></div>`;

    if (target === 'summary') {
      // Ringkasan SDM: ganti section ketika filter berubah supaya statistik mengikuti baris terpilih.
      const old = $('#eduRelSec'); if (old) old.remove();
      const out = $('#out');
      if (out) out.insertAdjacentHTML('beforebegin', html);
      else $('#view').insertAdjacentHTML('beforeend', html);
    } else {
      const anchor = [...document.querySelectorAll('#view .section-t')].find(el => /Kepatuhan Job Grade vs Person Grade/.test(el.textContent));
      if (anchor) anchor.insertAdjacentHTML('beforebegin', html); else $('#view').insertAdjacentHTML('beforeend', html);
    }

    const eduKeys = Object.keys(edu).sort((a, b) => (a === UNK) - (b === UNK) || eduRank(a) - eduRank(b) || a.localeCompare(b));
    chart('c6', 'bar', eduKeys, eduKeys.map(k => edu[k]), 'Jumlah Karyawan');
    const relKeys = Object.keys(rel).sort((a, b) => (a === UNK) - (b === UNK) || rel[b] - rel[a]);
    chart('c7', 'doughnut', relKeys, relKeys.map(k => rel[k]), 'Agama');
  }

  V._legacy.dash = V.dash;
  V.dash = function () {
    V._legacy.dash.apply(this, arguments);   // dashboard lama dirender utuh
    if (!S.ready) return;
    try { renderEduRel('dashboard'); } catch (err) { console.error('[features] section Pendidikan & Agama gagal', err); }
  };

  // Hook dipanggil dari callback Ringkasan SDM setiap level/filter/search berubah.
  window.updateSummaryEduRel = function (rows) {
    if (!S.ready) return;
    try { renderEduRel('summary', Array.isArray(rows) ? rows : S.emp); }
    catch (err) { console.error('[features] Pendidikan & Agama pada Ringkasan SDM gagal', err); }
  };

  /* ------------------------------------------------------------------
     [2,3] Monitoring Karyawan — kolom Pendidikan/Agama + filter Person Grade
     ------------------------------------------------------------------ */
  const pgLabel = e => (e.pg > 0 ? String(e.pg) : '(Kosong)');
  const pgCmp = (a, b) => (a === '(Kosong)') - (b === '(Kosong)') || (+a) - (+b);

  function currentJobFamilyLabel(e) {
    // Jabatan yang ditampilkan/filter mengikuti JOB CODE saat ini. Position Name current
    // digunakan untuk mengonfirmasi tier (khusus SAO, perlu juga JG > 6), bukan untuk
    // menggeser karyawan ke formasi keluarga lain. Teks lokasi tidak menjadi pilihan filter.
    const r = e && e.t === 'C' ? e.branchRoleCurrent : null;
    if (r && r.canonicalName && ['AMNG','PSR','SAO','AO','PAGN'].includes(r.jobKey)) return r.canonicalName;
    if (e && e.t === 'C') {
      const code = N(e.job).toUpperCase();
      const rows = (S.sotk && S.sotk.C) || [];
      const own = rows.find(x => x.job === code && (x.unitKey === e.unitCode || x.branchKey === e.br));
      const byCode = own || rows.find(x => x.job === code);
      if (byCode && byCode.jobName) return N(byCode.jobName);
      const raw = N(e.jn || e.job); // label fallback mengikuti JOB NAME/kode saat ini, bukan POSITION NAME DEF
      const beforeLocation = raw.split(/\s+(?:pada|di|untuk)\s+/i)[0];
      return beforeLocation.split(/\s+/).slice(0,4).join(' ') || N(e.jn) || '(Tidak diketahui)';
    }
    return N(e && e.jn) || '(Tidak diketahui)';
  }

  function prepareMonitoringSdmRows(rows, level) {
    if (level !== 'C') return rows;
    return (rows || []).map(e => {
      const currentRole = currentJobFamilyLabel(e);
      const currentSao = !!(e && e.branchRoleCurrent && e.branchRoleCurrent.jobKey === 'SAO' && e.branchRoleCurrent.validatedSenior);
      const p = Array.isArray(e.p) ? e.p.slice() : [];
      if (p.length > 5) p[5] = currentRole;
      // JOB CODE / POSITION NAME current menentukan jabatan saat ini. Kolom definitif
      // tetap mentah untuk menunjukkan jabatan asal; tidak dipakai untuk filter current role.
      return { ...e, p, jn: currentRole, monitoringRoleName: currentRole, monitoringSaoConfirmed: currentSao };
    });
  }
  window.prepareMonitoringSdmRows = prepareMonitoringSdmRows;

  function empColsMon(lv) {
    const base = empCols(lv).slice();           // kolom lama tetap utuh
    if (lv === 'C') {
      const jobIdx = base.findIndex(c => c[0] === 'Jabatan');
      if (jobIdx >= 0) base[jobIdx] = ['Jabatan', r => esc(currentJobFamilyLabel(r))];
    }
    const roleFields = [
      ['Job Code', r => esc(r.job || '-')],
      ['Position Name', r => esc(r.positionName || '-')],
      ['Job Code Def', r => esc(r.jobDef || '-')],
      ['Position Name Def', r => esc(r.positionNameDef || '-')]
    ];
    // Letakkan nama posisi current dan pasangan definitif berdekatan dengan jabatan saat ini.
    const jobIdx = base.findIndex(c => c[0] === 'Jabatan');
    base.splice(jobIdx < 0 ? base.length : jobIdx + 1, 0, ...roleFields);
    const extra = [
      ['Pendidikan Terakhir', r => r.edu ? `<span title="${esc(r.eduOrg || '')}">${esc(r.edu)}</span>` : '-'],
      ['Agama', r => esc(r.rel || '-')]
    ];
    const i = base.findIndex(c => c[0] === 'Jenis Kelamin');
    base.splice(i < 0 ? base.length : i + 1, 0, ...extra);
    return base;
  }

  V._legacy.mon1 = V.mon1;
  V.mon1 = function () {
    try {
      shell();
      panel({
        // Panel bersama menambahkan satu opsi keluarga Senior Account Officer pada seluruh tab Monitoring SDM.
        // Position Name lengkap tidak digunakan sebagai opsi per outlet.
        recs: l => S.emp.filter(e => e.t === l),
        extras: [
          { l: 'Position Type', f: r => r.pt },
          { l: 'Person Grade', f: pgLabel, cmp: pgCmp }
        ],
        cb: (r, lv) => table($('#out'), empColsMon(lv), r, { name: 'Monitoring_Karyawan' })
      });
    } catch (err) { console.error('[features] mon1 fallback ke versi lama', err); V._legacy.mon1(); }
  };

  /* ------------------------------------------------------------------
     Vacancy Priority — memakai seluruh karyawan aktif berdasarkan posisi saat ini.
     Non-definitif dihitung dengan JOB CODE / POSITION NAME / ORG CODE saat ini,
     bukan JOB CODE DEF / POSITION NAME DEF yang menunjukkan jabatan asal.
     ------------------------------------------------------------------ */
  function currentOccupancyRows(level) {
    const occupied = new Map();
    S.emp.filter(e => e.t === level).forEach(e => {
      // Nonaktifkan pasangan definitif pada pencocokan khusus vacancy agar posisi asal
      // tidak menggantikan penugasan/current position yang sedang ditempati.
      const current = { ...e, branchRoleDef: null, branchRoleForSotk: e.branchRoleCurrent || null };
      const hit = locate(current);
      if (!hit || !hit.row) return;
      const key = N(hit.row.key);
      occupied.set(key, (occupied.get(key) || 0) + 1);
    });
    return (S.sotk[level] || []).map(r => {
      const n = occupied.get(N(r.key)) || 0;
      return { ...r, vacancyActual: n, vacancyGap: (+r.f || 0) - n };
    }).filter(r => r.vacancyGap > 0);
  }

  V._legacy.intel0 = V.intel0;
  V.intel0 = function () {
    shell('<p class="mu" style="margin:-6px 0 14px;font-size:12.5px">Vacancy Priority menghitung seluruh karyawan aktif, termasuk definitif dan non-definitif, berdasarkan posisi yang sedang ditempati: <b>ORG CODE + JOB CODE + POSITION NAME</b>. <b>JOB CODE DEF</b> tidak digunakan untuk menganggap posisi asal sebagai posisi saat ini.</p>');
    panel({
      recs: l => currentOccupancyRows(l),
      cb: (rows, lv) => table($('#out'), [
        ...locCols(lv),
        ['Formasi', r => r.f],
        ['Aktual Terisi (Semua Karyawan)', r => r.vacancyActual],
        ['Vacancy Tersisa', r => badge(r.vacancyGap)]
      ], rows.slice().sort((a, b) => b.vacancyGap - a.vacancyGap || N(a.jobName).localeCompare(N(b.jobName))), { name: 'Vacancy_Priority' })
    });
  };

  /* ------------------------------------------------------------------
     [1] Monitoring Non-Definitif — TMT Position, End Status (+3 bulan), sisa hari
     ------------------------------------------------------------------ */
  const SOON_DAYS = 30;
  function endState(e) {
    if (!e.endStatus) return { k: 'TMT Kosong', days: null };
    const days = daysFromToday(e.endStatus);
    return { k: days < 0 ? 'Sudah Berakhir' : days <= SOON_DAYS ? `Berakhir \u2264 ${SOON_DAYS} Hari` : 'Berjalan', days };
  }

  V._legacy.mon3 = V.mon3;
  V.mon3 = function () {
    try {
      const nd = S.emp.filter(isNonDef);
      const cmp = e => { const a = N(e.job).toUpperCase(), b = N(e.jobDef).toUpperCase(); return !b ? 'Job Code Def kosong' : a !== b ? 'Berbeda' : 'Sama'; };
      const byPt = {}; nd.forEach(e => { byPt[e.pt] = (byPt[e.pt] || 0) + 1; });
      const diff = nd.filter(e => cmp(e) === 'Berbeda').length;
      const st = {}; nd.forEach(e => { const k = endState(e).k; st[k] = (st[k] || 0) + 1; });

      shell('<div class="grid">' + kpi('Karyawan Non-Definitif', nd.length)
        + Object.entries(byPt).sort((a, b) => b[1] - a[1]).map(([k, v]) => kpi(esc(k), v)).join('')
        + kpi('Job Code \u2260 Job Code Def', diff)
        + kpi(`End Status \u2264 ${SOON_DAYS} Hari`, st[`Berakhir \u2264 ${SOON_DAYS} Hari`] || 0)
        + kpi('End Status Sudah Lewat', st['Sudah Berakhir'] || 0) + '</div>'
        + '<p class="mu" style="margin:-6px 0 14px;font-size:12.5px">Karyawan dengan Position Type selain Definitif. <b>Job Code</b> adalah jabatan yang sedang dijalani, <b>Job Code Def</b> adalah jabatan definitifnya. <b>End Status</b> dihitung otomatis: <b>TMT Position + 3 bulan</b>.</p>');

      panel({
        recs: l => nd.filter(e => e.t === l),
        extras: [{ l: 'Position Type', f: r => r.pt }, { l: 'Status Kode', f: r => cmp(r) }, { l: 'Status End Status', f: r => endState(r).k }],
        cb: (r, lv) => {
          const rank = { Berbeda: 0, 'Job Code Def kosong': 1, Sama: 2 };
          const rows = r.slice().sort((a, b) => rank[cmp(a)] - rank[cmp(b)] || N(a.nama).localeCompare(N(b.nama)));
          const tag = x => { const c = cmp(x); return `<span class="b ${c === 'Berbeda' ? 'r' : c === 'Sama' ? '' : 'y'}">${esc(c)}</span>`; };
          const tagEnd = x => { const s = endState(x); return `<span class="b ${s.k === 'Sudah Berakhir' ? 'r' : s.k === 'Berjalan' ? '' : 'y'}">${esc(s.k)}</span>`; };
          table($('#out'), [
            ['NIK', x => x.nik], ['Nama', x => esc(x.nama)], ...loc[lv],
            ['Position Type', x => esc(x.pt)], ['Job Code', x => esc(x.job || '-')], ['Nama Jabatan', x => esc(x.jn || '-')],
            ['Position Name', x => esc(x.positionName || '-')],
            ['Job Code Def', x => esc(x.jobDef || '-')], ['Nama Jabatan Def', x => esc(x.jnDef || '-')],
            ['Position Name Def', x => esc(x.positionNameDef || '-')],
            ...(lv === 'C' ? [['Tingkat Jabatan Definitif', x => esc(branchRoleSummary(x.branchRoleDef))]] : []),
            ['Unit Definitif', x => esc(x.orgNameDef || x.orgDef || '-')], ['Status Kode', tag],
            ['TMT Position', x => x.tmt ? fmtDay(x.tmt) : '-'],
            ['End Status (TMT + 3 Bln)', x => x.endStatus ? fmtDay(x.endStatus) : '-'],
            ['Sisa Hari', x => { const s = endState(x); return s.days == null ? '-' : s.days; }],
            ['Status End Status', tagEnd]
          ], rows, { name: 'Monitoring_NonDefinitif' });
        }
      });
    } catch (err) { console.error('[features] mon3 fallback ke versi lama', err); V._legacy.mon3(); }
  };
})();
