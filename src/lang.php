<?php
declare(strict_types=1);

/**
 * Two languages for the gate.
 *
 * The record itself stays in one language (names, remarks and the audit trail
 * are evidence), but the screens the desk works from can be shown in
 * Indonesian: the gate officers read Indonesian while the site is written in
 * English, so the labels are translated rather than the data.
 *
 * t('Label') returns the Indonesian text when the desk is set to id and the
 * English label otherwise. A label that is not in the map falls through
 * unchanged, so adding a screen never breaks when a translation is missing.
 */

/** @return array<string, string> code => language name */
function ui_languages(): array
{
    return ['en' => 'English', 'id' => 'Bahasa Indonesia'];
}

/** The language the gate screens are drawn in (`en` or `id`). */
function ui_lang(): string
{
    $lang = (string) ($_SESSION['lang'] ?? '');
    if (isset(ui_languages()[$lang])) {
        return $lang;
    }
    // A site-wide default, so a deployment can ship in Indonesian by setting
    // the `ui_lang` row in `settings`; each desk can still switch per session.
    $saved = function_exists('setting_get') ? setting_get('ui_lang', 'en') : 'en';
    return isset(ui_languages()[$saved]) ? $saved : 'en';
}

/**
 * ?lang=id / ?lang=en anywhere in the site switches the desk language and
 * returns to the same page without the parameter.
 */
function ui_lang_handle_request(): void
{
    $lang = (string) ($_GET['lang'] ?? '');
    if (!isset(ui_languages()[$lang])) {
        return;
    }
    $_SESSION['lang'] = $lang;

    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? 'index.php');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: 'index.php');
    $path = basename($path);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $params);
    unset($params['lang']);
    $qs = http_build_query($params);

    header('Location: ' . $path . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

/** @return array<string, string> English label => Indonesian label */
function ui_label_map(): array
{
    return [
        // menu, chrome
        'Dashboard'                  => 'Dasbor',
        'Gate kiosk'                 => 'Layar gerbang',
        'Gate desk'                  => 'Meja gerbang',
        'Record entry'               => 'Catat barang',
        'Verify queue'               => 'Verifikasi',
        'Documents out'              => 'Dokumen keluar',
        'Visitors'                   => 'Tamu',
        'Summary'                    => 'Ringkasan',
        'Data Log'                   => 'Log data',
        'Document Log'               => 'Log dokumen',
        'Categories'                 => 'Kategori',
        'Users & roles'              => 'Pengguna & peran',
        'Help'                       => 'Bantuan',
        'Sign out'                   => 'Keluar',
        'Exit kiosk'                 => 'Keluar layar',
        'New entry'                  => 'Catat baru',
        'No access'                  => 'Tidak ada akses',
        'Back'                       => 'Kembali',

        // people
        'Employee'                   => 'Karyawan',
        'Visitor'                    => 'Tamu',
        'Contractor'                 => 'Kontraktor',
        'Personnel'                  => 'Personel',
        'Person'                     => 'Orang',
        'Name'                       => 'Nama',
        'Type'                       => 'Jenis',
        'Company'                    => 'Perusahaan',
        'Department'                 => 'Departemen',
        'Department / company'       => 'Departemen / perusahaan',
        'Host'                       => 'Penanggung jawab',
        'Person type'                => 'Jenis orang',
        'Personnel name'             => 'Nama personel',
        'Full name'                  => 'Nama lengkap',

        // one crossing
        'Category'                   => 'Kategori',
        'Belongings'                 => 'Barang',
        'Belongings / items'         => 'Barang',
        'Belongings / tools / documents' => 'Barang / alat / dokumen',
        'Qty'                        => 'Jumlah',
        'Direction'                  => 'Arah',
        'Dir'                        => 'Arah',
        'Gate'                       => 'Gerbang',
        'Note'                       => 'Catatan',
        'Recorded'                   => 'Dicatat',
        'Recorded by'                => 'Dicatat oleh',
        'Verified by'                => 'Diverifikasi oleh',
        'Date / time'                => 'Tanggal / jam',
        'Date & time'                => 'Tanggal & jam',
        'automatic'                  => 'otomatis',
        'optional'                   => 'opsional',
        'Status'                     => 'Status',
        'Pending'                    => 'Menunggu',
        'pending'                    => 'menunggu',
        'Verified'                   => 'Terverifikasi',
        'verified'                   => 'terverifikasi',
        'All'                        => 'Semua',
        'From'                       => 'Dari',
        'To'                         => 'Sampai',
        'Search'                     => 'Cari',
        'Filter'                     => 'Saring',
        'Reset'                      => 'Atur ulang',
        'Delete'                     => 'Hapus',
        'Save entry'                 => 'Simpan catatan',

        // record entry
        'Record personnel passing the main gate' => 'Catat personel yang melewati gerbang utama',
        'IN = entering the factory · OUT = leaving' => 'MASUK = masuk pabrik · KELUAR = pulang',
        'Open records for this person' => 'Catatan terbuka untuk orang ini',
        'live — updates as records are verified elsewhere' => 'langsung — ikut berubah saat catatan lain diverifikasi',
        'Type a personnel name to show their open (unverified) records.' => 'Ketik nama personel untuk melihat catatan yang belum diverifikasi.',

        // verify queue
        'To verify'                  => 'Menunggu verifikasi',
        'records awaiting check'     => 'catatan menunggu dicek',
        'Personnel involved'         => 'Personel terlibat',
        'with open records'          => 'punya catatan terbuka',
        'Verified today'             => 'Terverifikasi hari ini',
        'checked at the gate'        => 'dicek di gerbang',
        'Live'                       => 'Langsung',
        'auto-update'                => 'perbarui sendiri',
        'new entries appear here'    => 'catatan baru muncul di sini',
        'Find person or item'        => 'Cari nama atau barang',
        'name, department, item'     => 'nama, departemen, barang',
        'Remark / verify'            => 'Catatan / verifikasi',
        'Remark (optional)'          => 'Catatan (opsional)',
        'Verify'                     => 'Verifikasi',
        'Check against (opposite crossing)' => 'Cocokkan (arah sebaliknya)',
        'no opposite record yet'     => 'belum ada catatan sebaliknya',
        'Nothing to verify — every record has been checked. 🎉' => 'Tidak ada yang perlu diverifikasi — semua catatan sudah dicek. 🎉',
        'No entries match these filters. Record one on the Input page.' => 'Tidak ada catatan yang cocok. Catat lewat halaman Catat barang.',

        // remarks that open an exception
        'Belongings not returned'    => 'Barang belum dikembalikan',
        'Partially returned'         => 'Dikembalikan sebagian',
        'Returned as recorded'       => 'Dikembalikan sesuai catatan',
        'Item missing / damaged'     => 'Barang hilang / rusak',

        // exception register
        'Exceptions'                 => 'Kasus',
        'Exception register'         => 'Daftar kasus',
        'Open'                       => 'Terbuka',
        'Closed'                     => 'Ditutup',
        'Opened'                     => 'Dibuka',
        'Closed at'                  => 'Ditutup pada',
        'Closed by'                  => 'Ditutup oleh',
        'Close exception'            => 'Tutup kasus',
        'not returned'               => 'belum kembali',
        'exception closed'           => 'kasus ditutup',
        'Why is it closed?'          => 'Kenapa ditutup?',
        'Reopen'                     => 'Buka lagi',
        'Exception closed — it no longer counts as an open case.' => 'Kasus ditutup — tidak lagi dihitung sebagai kasus terbuka.',
        'Exception reopened.'        => 'Kasus dibuka kembali.',
        'Record verified'            => 'Catatan diverifikasi',
        'Verification cleared.'      => 'Verifikasi dibatalkan.',
        'Entry deleted.'             => 'Catatan dihapus.',
        'Open cases'                 => 'Kasus terbuka',
        'Belongings that went out and have not come back.' => 'Barang yang keluar dan belum kembali.',
        'No open exceptions.'        => 'Tidak ada kasus terbuka.',
        'Open exceptions'            => 'Kasus terbuka',

        // gate kiosk
        'On site now'                => 'Di lokasi sekarang',
        'visitors and contractors'   => 'tamu dan kontraktor',
        'visitor or contractor'      => 'tamu atau kontraktor',
        'checked in today'           => 'masuk hari ini',
        'Pending verification'       => 'Menunggu verifikasi',
        'queue is clear'             => 'antrean kosong',
        'oldest waiting'             => 'paling lama menunggu',
        'Gate passes today'          => 'Lalu lalang hari ini',
        'On site & checked in today' => 'Di lokasi & tercatat hari ini',
        'Waiting to verify'          => 'Menunggu verifikasi',
        'Open queue'                 => 'Buka antrean',
        'Waiting'                    => 'Menunggu',
        'on site'                    => 'di lokasi',
        'today'                      => 'hari ini',
        'as of'                      => 'per',
        'Refreshes automatically · press F11 for full screen' => 'Menyegarkan otomatis · tekan F11 untuk layar penuh',
        'Nobody is on site and no visitor has checked in today.' => 'Tidak ada orang di lokasi dan belum ada tamu masuk hari ini.',
        'Use Visitors to check someone in.' => 'Pakai halaman Tamu untuk mencatat tamu masuk.',

        // the two gate paths
        'Belongings IN / OUT'        => 'Barang MASUK / KELUAR',
        'Record'                     => 'Catat',
        'Log what is carried'        => 'Catat barang yang dibawa',
        'Check IN ↔ OUT'             => 'Cocokkan MASUK ↔ KELUAR',
        'Document'                   => 'Dokumen',
        'Title, scan or PDF'         => 'Judul, pindai atau PDF',
        'Approve'                    => 'Setujui',
        'A leader clears it'         => 'Disetujui atasan',

        // dashboard numbers
        'IN today'                   => 'MASUK hari ini',
        'OUT today'                  => 'KELUAR hari ini',
        'personnel entering'         => 'personel masuk',
        'personnel leaving'          => 'personel keluar',
        'Pending verify'             => 'Menunggu verifikasi',
        'Total records'              => 'Total catatan',
        'verify now'                 => 'verifikasi sekarang',
        'see summary'                => 'lihat ringkasan',
    ];
}

/** One label in the desk language (English when it has no translation). */
function t(string $en): string
{
    if (ui_lang() !== 'id') {
        return $en;
    }
    return ui_label_map()[$en] ?? $en;
}

/** Same as t(), escaped for output. */
function te(string $en): string
{
    return e(t($en));
}

/** EN / ID switch; the links carry ?lang= back to this same page. */
function lang_switcher(string $class = ''): void
{
    $cur = ui_lang();
    echo '<div class="lang-switch' . ($class !== '' ? ' ' . e($class) : '') . '" role="group" aria-label="Language">';
    foreach (ui_languages() as $code => $name) {
        echo '<a href="?lang=' . e($code) . '" title="' . e($name) . '"'
           . ($cur === $code ? ' class="is-on" aria-current="true"' : '')
           . '>' . strtoupper($code) . '</a>';
    }
    echo '</div>';
}
