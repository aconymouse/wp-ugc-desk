# WP UGC Desk

[English](README.md)

Plugin WordPress buat redaksi yang mau nerima tulisan dari pembaca, tapi situsnya pakai WordPress headless.

Pembaca nulis di situs depan. Tulisannya masuk ke WordPress sebagai pos Menunggu Tinjauan. Redaksi tinggal baca, lalu terbitkan, minta revisi, atau tolak. Penulis dapat email tiap ada keputusan.

Dipakai tiap hari di [Owrite](https://www.owrite.id).

## Coba langsung

- [owrite.id/kirim-tulisan](https://www.owrite.id/kirim-tulisan/), halaman ajakan menulis. Masuk pakai Google atau email, isi profil, lalu mulai nulis.
- [owrite.id/notifikasi](https://www.owrite.id/notifikasi/), buat langganan notifikasi dan pilih kategori.
- Buka artikel apa saja di owrite.id. Di bagian bawah ada tombol buat lapor kalau ada yang salah.

Bagian redaksinya (panel keputusan, Laporan Tulisan) ada di dalam wp-admin, jadi nggak bisa dibuka umum.

## Isinya

- **Akun penulis.** Situs depan bikin user WordPress dengan peran Contributor, plus profil penulis (foto, jabatan, bio, medsos) di PublishPress Authors. Akun ini nggak bisa masuk ke wp-admin.
- **Naskah masuk.** Tulisan masuk sebagai pos Menunggu Tinjauan, lengkap dengan kategori, tag, dan gambar utama kalau ada. Redaksi dapat email tiap ada naskah baru.
- **Keputusan redaksi.** Di editor blok ada panel Keputusan redaksi. Bisa minta revisi dengan catatan atau tolak dengan alasan. Penulis bisa perbaiki lalu kirim ulang ke pos yang sama.
- **Minta perubahan.** Penulis bisa minta koreksi untuk tulisan yang udah terbit. Redaksi tandai selesai, penulis dikabari.
- **Laporan pembaca.** Laporan dari artikel masuk ke menu Laporan warga, ada status dan angka yang belum dibaca.
- **Laporan Tulisan.** Rekap per penulis: kiriman, revisi, ditolak, terbit, terjadwal. Bisa pilih rentang tanggal dan unduh Excel atau CSV.
- **Notifikasi push.** Centang "Kirim notifikasi ke pembaca" di editor. Pas pos terbit, plugin minta situs depan ngirim push ke pembaca yang langganan kategorinya. Satu pos cuma dikirim sekali.

## Yang dibutuhkan

- WordPress 6 ke atas, PHP 7.4 ke atas, ekstensi `zip` buat unduh Excel
- [PublishPress Authors](https://wordpress.org/plugins/publishpress-authors/) aktif. Ini wajib, karena profil penulis disimpan di sana.
- Situs depan yang ngurus login dan editor. Bisa pakai [wp-ugc-desk-next](https://github.com/aconymouse/wp-ugc-desk-next) yang udah jadi, atau bikin sendiri pakai apa saja (lihat [Tanpa Next.js](#tanpa-nextjs)).

## Cara pasang

1. Salin folder ini ke `wp-content/plugins/wp-ugc-desk`, atau zip dulu lalu unggah lewat **Plugin → Tambah Baru → Unggah Plugin**. Aktifkan.
2. Bikin kunci acak, misalnya pakai `openssl rand -hex 32`. Simpan baik-baik.
3. Buka **Laporan Tulisan → Pengaturan**, lalu isi:
   - **Kunci**: kunci tadi. Situs depan harus pakai kunci yang sama. Kalau lebih suka lewat env server, isi `UGC_DESK_SECRET` dan kolom ini otomatis ikut.
   - **Email redaksi**: tujuan email naskah baru, laporan pembaca, dan permintaan ubah. Kosongkan kalau mau pakai email admin.
   - **Alamat situs**: alamat situs depan, misalnya `https://www.contoh.com`. Webhook dan tautan dikirim ke sini.
   - **Peran penulis tambahan**: Author otomatis nulis lewat situs depan. Kalau ada peran lain yang juga begitu, misalnya Reporter, centang di sini.
   - **Lihat pemberi keputusan**: siapa yang boleh lihat nama editor yang ngambil keputusan. Admin selalu bisa.
4. Bikin Application Password buat satu akun administrator di **Pengguna → Profil → Application Passwords**. Situs depan pakai ini bareng Kunci tiap kali manggil plugin.

Opsional: kalau di PublishPress Authors ada Author Category dengan slug `kolumnis`, penulis dari kalangan pembaca otomatis masuk ke sana.

## Cara pakai di redaksi

1. Naskah baru muncul di **Pos** dengan status Menunggu Tinjauan. Di atas daftar pos ada tab Kiriman masuk, Perlu revisi, Ditolak, dan Permintaan ubah.
2. Buka naskahnya dan sunting kayak pos biasa.
3. Kalau udah layak, klik **Terbitkan** atau jadwalkan. Penulis dapat email berisi tautannya.
4. Kalau perlu dibenerin, buka panel **Keputusan redaksi**, pilih Perlu revisi, tulis catatan, lalu simpan. Pos balik jadi draf dan catatannya muncul di akun penulis. Begitu penulis kirim ulang, pos yang sama masuk lagi ke Menunggu Tinjauan.
5. Kalau nggak layak, pilih Ditolak, tulis alasannya, simpan.
6. Kalau ada permintaan ubah untuk tulisan yang udah terbit, isinya kelihatan di panel yang sama. Setelah dikerjain, klik **Tandai selesai**.
7. Rekapnya ada di menu **Laporan Tulisan**.

## Tanpa Next.js

Bisa. Plugin ini nggak peduli situs depannya dibikin pakai apa. Nuxt, Astro, SvelteKit, Laravel, Express, PHP biasa, semuanya bisa, asal ada bagian server-nya.

Yang perlu kamu siapin di situs depan:

1. **Login sendiri.** Plugin nggak ngurus login pembaca. Simpan akun di database kamu, lalu catat `wp_user_id` yang dikasih plugin waktu akun dibuat.
2. **Panggil API dari server.** Tiap request butuh Kunci dan Application Password admin, jadi jangan pernah dipanggil dari browser.
3. **Alamat webhook** di `/api/akun/webhook/` buat nerima kabar keputusan redaksi.
4. **Alamat push** di `/api/push/kirim/`, kalau mau pakai notifikasi.

Contoh request dan isi webhook lengkapnya ada di [docs/api.id.md](docs/api.id.md).

Situs WordPress biasa yang pakai tema (bukan headless) belum didukung, karena belum ada form kirim tulisan di sisi WordPress.

## Buat plugin lain

```php
ugcd_laporan_ringkasan($dari, $sampai, $jenis);
ugcd_laporan_rincian($dari, $sampai, $penulis, $jenis, $sertakanOleh);
ugcd_jejak_tulisan($postId, $sertakanOleh);
```

Action: `ugcd_jejak_dicatat`, `ugcd_naskah_masuk`, `ugcd_kontributor_term_diperbarui`.
Filter: `ugcd_peran_penulis`, `ugcd_kontributor_medsos_meta`, `ugcd_kontributor_kategori_slug`.

## Kredit

Dibikin sama Hafidz Nc ([Aconymous](https://aconymous.com), [GitHub](https://github.com/aconymouse), [LinkedIn](https://www.linkedin.com/in/tjuanmudaco/)) buat Owrite.

Lisensi GPL-2.0-or-later.
