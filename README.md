# WP UGC Desk

[Bahasa Indonesia](README.id.md)

A WordPress plugin for newsrooms that want to take articles from readers while running WordPress headless.

Readers write on the front end. Their piece lands in WordPress as a Pending Review post. Editors read it, then publish, ask for a revision, or decline. The writer gets an email for every decision.

It runs every day on [Owrite](https://www.owrite.id), an Indonesian news site. The admin screens are in Indonesian.

## Try it

- [owrite.id/kirim-tulisan](https://www.owrite.id/kirim-tulisan/) is the "write for us" page. Sign in with Google or email, fill in a profile, start writing.
- [owrite.id/notifikasi](https://www.owrite.id/notifikasi/) is where readers turn on notifications and pick categories.
- Open any article on owrite.id. There is a button at the bottom to report a mistake.

The newsroom side (the decision panel, Laporan Tulisan) lives inside wp-admin, so it can't be shown publicly.

## What it does

- **Writer accounts.** The front end creates a WordPress user with the Contributor role and a writer profile (photo, title, bio, social links) in PublishPress Authors. These accounts can't log in to wp-admin.
- **Submissions.** Pieces arrive as Pending Review posts with a category, tags, and a featured image if there is one. Editors get an email for each new one.
- **Editor decisions.** The block editor gets a Keputusan redaksi panel. Ask for a revision with a note, or decline with a reason. The writer can fix it and resubmit to the same post.
- **Change requests.** Writers can ask for a correction on a published piece. Editors mark it done and the writer is told.
- **Reader reports.** Reports sent from articles go to a Laporan warga menu with a status and an unread count.
- **Laporan Tulisan.** A per-writer summary of submissions, revisions, declines, published and scheduled posts. Pick a date range, download Excel or CSV.
- **Web push.** Tick "Kirim notifikasi ke pembaca" in the editor. When the post goes live, the plugin asks the front end to send a push to readers who follow that category. Each post is sent once.

## Requirements

- WordPress 6+, PHP 7.4+, the `zip` extension for Excel downloads
- [PublishPress Authors](https://wordpress.org/plugins/publishpress-authors/) active. This is required, writer profiles are stored there.
- A front end that handles sign-in and the editor. Use the ready-made [wp-ugc-desk-next](https://github.com/aconymouse/wp-ugc-desk-next), or build your own in any stack (see [Without Next.js](#without-nextjs)).

## Install

1. Download [wp-ugc-desk.zip](https://github.com/aconymouse/wp-ugc-desk/releases/latest/download/wp-ugc-desk.zip) from Releases and upload it under **Plugins → Add New → Upload Plugin**. Activate it.
2. Make a random key, for example with `openssl rand -hex 32`. Keep it somewhere safe.
3. Open **Laporan Tulisan → Pengaturan** and fill in:
   - **Kunci** (key): the key from step 2. The front end must use the same one. If you'd rather keep it in the server environment, set `UGC_DESK_SECRET` and this field picks it up.
   - **Email redaksi**: where new submissions, reader reports, and change requests are emailed. Leave it empty to use the admin email.
   - **Alamat situs**: the front end address, for example `https://www.example.com`. Webhooks and links go here.
   - **Peran penulis tambahan**: Authors always write through the front end. If another role does too, like a Reporter role, tick it here.
   - **Lihat pemberi keputusan**: who can see which editor made a decision. Admins always can.
4. Create an Application Password for an administrator under **Users → Profile → Application Passwords**. The front end sends it together with the key on every call.

Optional: if PublishPress Authors has an Author Category with the slug `kolumnis`, reader writers are put in it automatically.

## Day to day

1. New pieces show up under **Posts** as Pending Review. Above the post list there are tabs for Kiriman masuk (incoming), Perlu revisi (needs revision), Ditolak (declined), and Permintaan ubah (change requests).
2. Open one and edit it like any post.
3. When it's ready, hit **Publish** or schedule it. The writer gets an email with the link.
4. If it needs work, open the **Keputusan redaksi** panel, choose Perlu revisi, write a note, and save. The post goes back to draft and the note shows up in the writer's account. When they resubmit, the same post returns to Pending Review.
5. If it's a no, choose Ditolak, write the reason, and save.
6. A change request on a published piece shows up in the same panel. Once it's handled, click **Tandai selesai** (mark as done).
7. Numbers live under **Laporan Tulisan**.

## Without Next.js

Yes, it works. The plugin doesn't care what the front end is built with. Nuxt, Astro, SvelteKit, Laravel, Express, plain PHP, anything with a server side.

What your front end needs:

1. **Its own sign-in.** The plugin doesn't handle reader logins. Keep accounts in your own database and store the `wp_user_id` the plugin gives back when an account is created.
2. **Server-side calls.** Every request carries the key and an admin Application Password, so never call it from the browser.
3. **A webhook endpoint** at `/api/akun/webhook/` to hear about editor decisions.
4. **A push endpoint** at `/api/push/kirim/`, if you want notifications.

Full request and webhook examples are in [docs/api.md](docs/api.md).

Classic WordPress sites with a theme (not headless) aren't supported yet. There is no submission form on the WordPress side.

## For other plugins

```php
ugcd_laporan_ringkasan($dari, $sampai, $jenis);
ugcd_laporan_rincian($dari, $sampai, $penulis, $jenis, $sertakanOleh);
ugcd_jejak_tulisan($postId, $sertakanOleh);
```

Actions: `ugcd_jejak_dicatat`, `ugcd_naskah_masuk`, `ugcd_kontributor_term_diperbarui`.
Filters: `ugcd_peran_penulis`, `ugcd_kontributor_medsos_meta`, `ugcd_kontributor_kategori_slug`.

## Credit

Made by Hafidz Nc ([Aconymous](https://aconymous.com), [GitHub](https://github.com/aconymouse), [LinkedIn](https://www.linkedin.com/in/tjuanmudaco/)) for Owrite.

GPL-2.0-or-later.
