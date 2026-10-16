# API WP UGC Desk

[English](api.md)

Panduan ini buat kamu yang bikin situs depan sendiri, tanpa [wp-ugc-desk-next](https://github.com/aconymouse/wp-ugc-desk-next). Contohnya pakai curl, tinggal disesuaikan ke bahasa yang kamu pakai.

## Dasar

Semua alamat ada di bawah:

```
https://cms.contoh.com/wp-json/ugc-desk/v1
```

Tiap request butuh dua hal:

- Header `X-UGC-Desk-Secret` berisi Kunci dari halaman pengaturan plugin.
- Basic auth pakai username administrator dan Application Password-nya.

```bash
curl "https://cms.contoh.com/wp-json/ugc-desk/v1/kontributor/username-ada?u=budi" \
  -u "admin:abcd efgh ijkl mnop qrst uvwx" \
  -H "X-UGC-Desk-Secret: $UGC_DESK_SECRET"
```

Kalau gagal, balasannya format error WordPress biasa. Pesannya udah bahasa Indonesia, jadi bisa langsung ditampilin ke pengguna.

```json
{ "code": "ugcd_username_ada", "message": "Username sudah dipakai.", "data": { "status": 409 } }
```

## Alur singkat

1. Pembaca daftar di situs kamu, lalu kamu panggil `POST /kontributor`. Simpan `wp_user_id` dari balasannya.
2. Pembaca unggah gambar, kamu teruskan ke `POST /wp-json/wp/v2/media` (bawaan WordPress, pakai Application Password yang sama). Simpan `id`-nya.
3. Pembaca kirim tulisan, kamu panggil `POST /naskah`.
4. Redaksi ambil keputusan, plugin ngabarin webhook kamu.
5. Kalau diminta revisi, ambil isinya lewat `GET /naskah/{id}`, lalu kirim ulang pakai `PUT /naskah/{id}`.

## Akun penulis

### Cek username

```
GET /kontributor/username-ada?u=budi
```

```json
{ "ada": false }
```

Username boleh huruf kecil, angka, dan strip, maksimal 30 karakter, nggak boleh diawali atau diakhiri strip. Kalau formatnya salah, balasannya juga `"ada": true`.

### Cek akun lama

```
GET /kontributor/akun-lama?email=budi@contoh.com
```

```json
{ "username": "budi" }
```

Kalau email itu udah punya akun Contributor atau Subscriber di WordPress, akun itu yang dipakai dan username-nya tetap. Kalau nggak ada, `username` berisi `null`.

### Peran yang nulis lewat situs depan

```
GET /kontributor/peran-reporter
```

```json
{ "peran": ["author", "reporter"] }
```

User redaksi dengan peran ini boleh kirim naskah juga. Pakai ini buat nentuin siapa yang dapat akses editor.

### Bikin akun

```
POST /kontributor
```

```json
{
  "username": "budi",
  "email": "budi@contoh.com",
  "nama": "Budi Santoso",
  "jabatan": "Guru SD",
  "bio": "Nulis soal pendidikan di daerah.",
  "website": "https://budi.id",
  "medsos": { "instagram": "budisantoso", "x": "budi" },
  "avatarUrl": "https://contoh.com/foto/budi.jpg"
}
```

```json
{
  "wp_user_id": 812,
  "username": "budi",
  "ppma_term_id": 455,
  "avatar_media_id": 9031,
  "avatar_url": "https://cms.contoh.com/wp-content/uploads/2026/10/avatar-budi-santoso.jpg"
}
```

- `nama` 2 sampai 60 karakter, `jabatan` maksimal 80, `bio` maksimal 600.
- `medsos` bisa berisi `instagram`, `x`, `threads`, `tiktok`, `linkedin`, `youtube`. Isinya username atau URL lengkap, dua-duanya diterima.
- `avatarUrl` harus https dan berupa jpg, png, atau webp. Plugin yang ngunduh dan nyimpen ke Media.
- Kalau emailnya punya akun lama, `username` diabaikan.
- Kalau emailnya milik akun redaksi (editor, admin), balasannya 409 `ugcd_email_redaksi`.

### Ubah profil

```
PUT /kontributor/{wp_user_id}
```

Isinya sama kayak bikin akun, tanpa `username` dan `email`. Buat ganti foto, unggah dulu ke `/wp/v2/media`, lalu kirim `"avatar_media_id": 9040`. Balasannya sama kayak bikin akun.

### Nonaktifkan

```
DELETE /kontributor/{wp_user_id}
```

```json
{ "ok": true }
```

Jabatan, bio, website, dan medsos dikosongkan. User WordPress-nya nggak dihapus, jadi tulisan lama tetap ada.

## Naskah

### Kirim

```
POST /naskah
```

```json
{
  "wp_user_id": 812,
  "akun_id": "u_8f2k1",
  "naskah_id": "3b1c9e2a-7d4f-4a51-9c3e-0f6b2d8e1a77",
  "judul": "Sekolah di Ujung Desa yang Bertahan Tanpa Listrik",
  "dek": "Tiga guru, satu genset, dan 40 murid yang tetap datang tiap pagi.",
  "kategori_id": 7,
  "tag": ["pendidikan", "guru"],
  "gambar_utama_id": 9035,
  "konten": "<!-- wp:paragraph --><p>Pagi itu...</p><!-- /wp:paragraph -->",
  "kata": 742
}
```

```json
{ "post_id": 20311 }
```

- `akun_id` dan `naskah_id` itu ID dari sistem kamu sendiri. Plugin cuma nyimpen dan ngirim balik lewat webhook, biar gampang dicocokin.
- `judul` 10 sampai 120 karakter.
- `dek` maksimal 200 karakter. Dipakai jadi ringkasan pos, juga jadi meta description Yoast kalau masih kosong.
- `kategori_id` wajib dan harus ada di WordPress.
- `tag` maksimal 5, tiap tag maksimal 40 karakter.
- `gambar_utama_id` opsional. Kalau diisi, harus ID gambar di Media.
- `konten` paling enak berupa markup blok Gutenberg, biar rapi waktu dibuka di editor. HTML biasa juga jalan. Isinya disaring `wp_kses_post`, maksimal 400 KB.
- Gambar di dalam konten kasih class `wp-image-{id}`, biar alt-nya otomatis diisi judul.
- `kata` cuma dicatat buat laporan. Batas jumlah kata kamu atur sendiri di situs depan.

### Daftar tulisan

```
GET /naskah?wp_user_id=812
```

```json
[
  {
    "id": 20311,
    "judul": "Sekolah di Ujung Desa yang Bertahan Tanpa Listrik",
    "status": "draft",
    "tanggal": "2026-10-16T14:20:00+07:00",
    "link": null,
    "keputusan": "revisi",
    "alasan": "Tambahin sumber data jumlah muridnya.",
    "naskah_id": "3b1c9e2a-7d4f-4a51-9c3e-0f6b2d8e1a77",
    "perubahan": false
  }
]
```

`status` isinya salah satu dari:

| Status | Artinya |
| --- | --- |
| `pending` | Lagi ditinjau redaksi |
| `draft` | Diminta revisi (lihat `keputusan` dan `alasan`) |
| `ditolak` | Ditolak |
| `publish` | Terbit atau terjadwal |

`perubahan` bernilai `true` kalau ada permintaan ubah yang belum selesai. Maksimal 50 tulisan, yang terakhir diubah duluan.

### Ambil isi buat revisi

```
GET /naskah/{post_id}?wp_user_id=812
```

Balasannya sama kayak satu baris di daftar, ditambah `dek`, `konten`, `kategori_id`, `tag`, dan `gambar_utama` (`{ "id": 9035, "src": "https://..." }` atau `null`).

### Kirim ulang

```
PUT /naskah/{post_id}
```

Isinya sama persis kayak `POST /naskah`. Cuma bisa kalau naskahnya lagi diminta revisi. Kalau nggak, balasannya 409 `ugcd_status`.

### Minta perubahan

```
POST /perubahan
```

```json
{
  "post_id": 20311,
  "wp_user_id": 812,
  "nama": "Budi Santoso",
  "email": "budi@contoh.com",
  "pesan": "Jumlah murid di paragraf ketiga harusnya 42, bukan 40."
}
```

```json
{ "ok": true }
```

`pesan` 30 sampai 1000 karakter. Cuma bisa buat tulisan yang udah terbit. Satu tulisan cuma boleh punya satu permintaan yang jalan. Kalau masih ada, balasannya 409 `ugcd_ubah_ada`.

## Laporan pembaca

```
POST /laporan
```

```json
{
  "post_id": 20100,
  "pesan": "Isi laporan...",
  "nama": "Budi Santoso",
  "email": "budi@contoh.com",
  "username": "budi",
  "wp_user_id": 812,
  "akun_id": "u_8f2k1",
  "ip": "203.0.113.5"
}
```

```json
{ "id": 20412 }
```

`pesan` 60 sampai 135 kata. Plugin nggak ngebatasin berapa kali orang boleh lapor, jadi batasi sendiri di situs depan. Owrite batasi per akun dan per jaringan.

## Webhook ke situs kamu

Plugin ngirim `POST` ke `{Alamat situs}/api/akun/webhook/`, termasuk garis miring di akhir, tiap kali:

| `kejadian` | Kapan |
| --- | --- |
| `terbit` | Naskah diterbitkan |
| `revisi` | Redaksi minta revisi |
| `ditolak` | Naskah ditolak |
| `ubah-selesai` | Permintaan ubah udah dikerjain |

Header:

```
Content-Type: application/json
X-UGC-Desk-Secret: <Kunci>
```

Isinya:

```json
{
  "kejadian": "revisi",
  "post_id": 20311,
  "naskah_id": "3b1c9e2a-7d4f-4a51-9c3e-0f6b2d8e1a77",
  "akun_id": "u_8f2k1",
  "wp_user_id": 812,
  "judul": "Sekolah di Ujung Desa yang Bertahan Tanpa Listrik",
  "alasan": "Tambahin sumber data jumlah muridnya.",
  "link": null
}
```

- `link` berisi URL publik kalau kejadiannya `terbit`, selain itu `null`.
- `alasan` berisi catatan revisi atau alasan tolak. Buat `ubah-selesai`, isinya permintaan ubah dari penulis.

Yang perlu kamu lakukan:

1. Cocokkan header kunci. Pakai perbandingan yang aman dari timing attack.
2. Cari akun pakai `akun_id`, pastikan `wp_user_id`-nya sama.
3. Perbarui status di database kamu dan kirim email ke penulis.
4. Balas 200.

Plugin cuma nunggu 8 detik dan nggak ngulang kalau gagal. Jadi balas cepat, kerjaan berat taruh di belakang.

PHP polos:

```php
<?php
$kunci = getenv('UGC_DESK_SECRET') ?: '';
$kiriman = $_SERVER['HTTP_X_UGC_DESK_SECRET'] ?? '';
if ($kunci === '' || !hash_equals($kunci, $kiriman)) {
    http_response_code(403);
    exit;
}
$data = json_decode(file_get_contents('php://input'), true);
simpanKabarNaskah($data);
http_response_code(200);
```

Express:

```js
app.post("/api/akun/webhook/", express.json(), (req, res) => {
  const kunci = Buffer.from(process.env.UGC_DESK_SECRET ?? "");
  const kiriman = Buffer.from(req.get("x-ugc-desk-secret") ?? "");
  if (!kunci.length || kunci.length !== kiriman.length || !crypto.timingSafeEqual(kunci, kiriman)) {
    return res.sendStatus(403);
  }
  res.sendStatus(200);
  simpanKabarNaskah(req.body);
});
```

## Push ke situs kamu

Kalau pos yang dicentang "Kirim notifikasi ke pembaca" terbit, plugin ngirim `POST` ke `{Alamat situs}/api/push/kirim/` dengan header yang sama kayak webhook.

```json
{
  "post_id": 20311,
  "judul": "Sekolah di Ujung Desa yang Bertahan Tanpa Listrik",
  "ringkasan": "Tiga guru, satu genset, dan 40 murid yang tetap datang tiap pagi.",
  "url": "https://www.contoh.com/sekolah-di-ujung-desa/",
  "gambar": "https://cms.contoh.com/wp-content/uploads/2026/10/sekolah-768x512.jpg",
  "kategori": [7, 2]
}
```

- `url` udah pakai domain situs depan.
- `gambar` bisa `null`.
- `kategori` udah termasuk kategori induknya.

Balasan yang dimengerti plugin:

| Balasan | Artinya |
| --- | --- |
| 200 `{ "target": 1520 }` | Terkirim ke 1520 perangkat. Angkanya muncul di editor. |
| 409 | Pos ini udah pernah dikirim. Dianggap berhasil. |
| Lainnya, `{ "pesan": "..." }` | Gagal. Pesannya muncul di editor. |

Batas tunggunya 10 detik. Balas dulu, kirim push-nya belakangan. Mau pakai web-push, OneSignal, atau FCM terserah kamu.

## Data laporan

Tiga alamat ini nggak butuh Kunci. Cukup user yang boleh menyunting pos orang lain (editor ke atas), lewat cookie wp-admin atau Application Password. Gunanya buat dashboard internal.

```
GET /laporan/ringkasan?dari=2026-10-01&sampai=2026-10-16&jenis=warga
GET /laporan/rincian?dari=2026-10-01&sampai=2026-10-16&penulis=812
GET /laporan/tulisan/{post_id}
```

`dari` dan `sampai` formatnya `YYYY-MM-DD`, kalau kosong otomatis awal bulan sampai hari ini. `jenis` bisa `warga` atau slug peran penulis.
