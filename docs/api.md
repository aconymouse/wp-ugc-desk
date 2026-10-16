# WP UGC Desk API

[Bahasa Indonesia](api.id.md)

This is for people building their own front end instead of using [wp-ugc-desk-next](https://github.com/aconymouse/wp-ugc-desk-next). Examples use curl, translate them to whatever you use.

Field names are in Indonesian. A short glossary: `nama` name, `judul` title, `dek` standfirst, `konten` body, `kata` word count, `pesan` message, `alasan` reason, `kejadian` event, `naskah` submission, `kontributor` contributor, `perubahan` change request, `laporan` report.

## Basics

Everything lives under:

```
https://cms.example.com/wp-json/ugc-desk/v1
```

Every request needs two things:

- An `X-UGC-Desk-Secret` header with the key from the plugin settings.
- Basic auth with an administrator's username and Application Password.

```bash
curl "https://cms.example.com/wp-json/ugc-desk/v1/kontributor/username-ada?u=budi" \
  -u "admin:abcd efgh ijkl mnop qrst uvwx" \
  -H "X-UGC-Desk-Secret: $UGC_DESK_SECRET"
```

Errors use the normal WordPress shape. Messages are in Indonesian and short enough to show to users.

```json
{ "code": "ugcd_username_ada", "message": "Username sudah dipakai.", "data": { "status": 409 } }
```

## The flow

1. A reader signs up on your site, you call `POST /kontributor`. Store the `wp_user_id` you get back.
2. A reader uploads an image, you pass it to `POST /wp-json/wp/v2/media` (core WordPress, same Application Password). Keep the `id`.
3. A reader sends a piece, you call `POST /naskah`.
4. An editor decides, the plugin calls your webhook.
5. If it needs a revision, load it with `GET /naskah/{id}` and resubmit with `PUT /naskah/{id}`.

## Writer accounts

### Check a username

```
GET /kontributor/username-ada?u=budi
```

```json
{ "ada": false }
```

`ada` means "taken". Usernames are lowercase letters, digits, and dashes, up to 30 characters, no dash at either end. A bad format also returns `"ada": true`.

### Look up an old account

```
GET /kontributor/akun-lama?email=budi@example.com
```

```json
{ "username": "budi" }
```

If that email already has a Contributor or Subscriber account in WordPress, it gets reused and keeps its username. Otherwise `username` is `null`.

### Roles that write through the front end

```
GET /kontributor/peran-reporter
```

```json
{ "peran": ["author", "reporter"] }
```

Staff with these roles can submit too. Use it to decide who gets the editor.

### Create an account

```
POST /kontributor
```

```json
{
  "username": "budi",
  "email": "budi@example.com",
  "nama": "Budi Santoso",
  "jabatan": "Primary school teacher",
  "bio": "Writes about schools outside the big cities.",
  "website": "https://budi.id",
  "medsos": { "instagram": "budisantoso", "x": "budi" },
  "avatarUrl": "https://example.com/photos/budi.jpg"
}
```

```json
{
  "wp_user_id": 812,
  "username": "budi",
  "ppma_term_id": 455,
  "avatar_media_id": 9031,
  "avatar_url": "https://cms.example.com/wp-content/uploads/2026/10/avatar-budi-santoso.jpg"
}
```

- `nama` 2 to 60 characters, `jabatan` (title) up to 80, `bio` up to 600.
- `medsos` takes `instagram`, `x`, `threads`, `tiktok`, `linkedin`, `youtube`. A handle or a full URL both work.
- `avatarUrl` must be https and jpg, png, or webp. The plugin downloads it into Media.
- If the email has an old account, `username` is ignored.
- If the email belongs to a staff account (editor, admin), you get 409 `ugcd_email_redaksi`.

### Update a profile

```
PUT /kontributor/{wp_user_id}
```

Same body as create, minus `username` and `email`. To change the photo, upload it to `/wp/v2/media` first and send `"avatar_media_id": 9040`. Same response as create.

### Deactivate

```
DELETE /kontributor/{wp_user_id}
```

```json
{ "ok": true }
```

Title, bio, website, and social links are cleared. The WordPress user stays, so old posts are untouched.

## Submissions

### Send

```
POST /naskah
```

```json
{
  "wp_user_id": 812,
  "akun_id": "u_8f2k1",
  "naskah_id": "3b1c9e2a-7d4f-4a51-9c3e-0f6b2d8e1a77",
  "judul": "The Village School That Runs Without Power",
  "dek": "Three teachers, one generator, and 40 kids who still show up every morning.",
  "kategori_id": 7,
  "tag": ["education", "teachers"],
  "gambar_utama_id": 9035,
  "konten": "<!-- wp:paragraph --><p>That morning...</p><!-- /wp:paragraph -->",
  "kata": 742
}
```

```json
{ "post_id": 20311 }
```

- `akun_id` and `naskah_id` are your own IDs. The plugin just stores them and sends them back in webhooks so you can match things up.
- `judul` 10 to 120 characters.
- `dek` up to 200 characters. It becomes the post excerpt, and the Yoast meta description if that's still empty.
- `kategori_id` is required and must exist.
- `tag` up to 5, each up to 40 characters.
- `gambar_utama_id` is optional. If set, it must be an image in Media.
- `konten` works best as Gutenberg block markup so it looks right in the editor. Plain HTML works too. It goes through `wp_kses_post`, 400 KB max.
- Give inline images a `wp-image-{id}` class and their alt text gets filled from the title.
- `kata` is only recorded for the report. Word limits are up to your front end.

### List a writer's pieces

```
GET /naskah?wp_user_id=812
```

```json
[
  {
    "id": 20311,
    "judul": "The Village School That Runs Without Power",
    "status": "draft",
    "tanggal": "2026-10-16T14:20:00+07:00",
    "link": null,
    "keputusan": "revisi",
    "alasan": "Add a source for the student numbers.",
    "naskah_id": "3b1c9e2a-7d4f-4a51-9c3e-0f6b2d8e1a77",
    "perubahan": false
  }
]
```

`status` is one of:

| Status | Meaning |
| --- | --- |
| `pending` | Being reviewed |
| `draft` | Revision requested (see `keputusan` and `alasan`) |
| `ditolak` | Declined |
| `publish` | Published or scheduled |

`perubahan` is `true` while a change request is open. Up to 50 pieces, most recently changed first.

### Load one for revision

```
GET /naskah/{post_id}?wp_user_id=812
```

Same as a list item, plus `dek`, `konten`, `kategori_id`, `tag`, and `gambar_utama` (`{ "id": 9035, "src": "https://..." }` or `null`).

### Resubmit

```
PUT /naskah/{post_id}
```

Same body as `POST /naskah`. Only works while a revision is requested, otherwise 409 `ugcd_status`.

### Ask for a change

```
POST /perubahan
```

```json
{
  "post_id": 20311,
  "wp_user_id": 812,
  "nama": "Budi Santoso",
  "email": "budi@example.com",
  "pesan": "The third paragraph says 40 students, it should be 42."
}
```

```json
{ "ok": true }
```

`pesan` 30 to 1000 characters. Published pieces only. One open request per piece; a second one gets 409 `ugcd_ubah_ada`.

## Reader reports

```
POST /laporan
```

```json
{
  "post_id": 20100,
  "pesan": "Report text...",
  "nama": "Budi Santoso",
  "email": "budi@example.com",
  "username": "budi",
  "wp_user_id": 812,
  "akun_id": "u_8f2k1",
  "ip": "203.0.113.5"
}
```

```json
{ "id": 20412 }
```

`pesan` 60 to 135 words. The plugin doesn't rate limit reports, so do that on your side. Owrite limits per account and per network.

## Webhook to your site

The plugin sends a `POST` to `{site address}/api/akun/webhook/`, trailing slash included, whenever:

| `kejadian` | When |
| --- | --- |
| `terbit` | The piece was published |
| `revisi` | An editor asked for a revision |
| `ditolak` | The piece was declined |
| `ubah-selesai` | A change request was handled |

Headers:

```
Content-Type: application/json
X-UGC-Desk-Secret: <key>
```

Body:

```json
{
  "kejadian": "revisi",
  "post_id": 20311,
  "naskah_id": "3b1c9e2a-7d4f-4a51-9c3e-0f6b2d8e1a77",
  "akun_id": "u_8f2k1",
  "wp_user_id": 812,
  "judul": "The Village School That Runs Without Power",
  "alasan": "Add a source for the student numbers.",
  "link": null
}
```

- `link` is the public URL for `terbit`, `null` otherwise.
- `alasan` is the revision note or decline reason. For `ubah-selesai` it's the writer's original request.

What to do with it:

1. Check the key header with a constant-time compare.
2. Find the account by `akun_id` and make sure `wp_user_id` matches.
3. Update your database and email the writer.
4. Return 200.

The plugin waits 8 seconds at most and doesn't retry. Reply fast, do the heavy work after.

Plain PHP:

```php
<?php
$key = getenv('UGC_DESK_SECRET') ?: '';
$sent = $_SERVER['HTTP_X_UGC_DESK_SECRET'] ?? '';
if ($key === '' || !hash_equals($key, $sent)) {
    http_response_code(403);
    exit;
}
$data = json_decode(file_get_contents('php://input'), true);
handleSubmissionEvent($data);
http_response_code(200);
```

Express:

```js
app.post("/api/akun/webhook/", express.json(), (req, res) => {
  const key = Buffer.from(process.env.UGC_DESK_SECRET ?? "");
  const sent = Buffer.from(req.get("x-ugc-desk-secret") ?? "");
  if (!key.length || key.length !== sent.length || !crypto.timingSafeEqual(key, sent)) {
    return res.sendStatus(403);
  }
  res.sendStatus(200);
  handleSubmissionEvent(req.body);
});
```

## Push to your site

When a post with "Kirim notifikasi ke pembaca" ticked is published, the plugin sends a `POST` to `{site address}/api/push/kirim/` with the same headers as the webhook.

```json
{
  "post_id": 20311,
  "judul": "The Village School That Runs Without Power",
  "ringkasan": "Three teachers, one generator, and 40 kids who still show up every morning.",
  "url": "https://www.example.com/village-school/",
  "gambar": "https://cms.example.com/wp-content/uploads/2026/10/school-768x512.jpg",
  "kategori": [7, 2]
}
```

- `url` already uses the front end domain.
- `gambar` (image) can be `null`.
- `kategori` includes parent categories.

Replies the plugin understands:

| Reply | Meaning |
| --- | --- |
| 200 `{ "target": 1520 }` | Sent to 1520 devices. The number shows in the editor. |
| 409 | Already sent for this post. Treated as success. |
| Anything else, `{ "pesan": "..." }` | Failed. The message shows in the editor. |

It waits 10 seconds. Reply first, send the pushes after. web-push, OneSignal, FCM, your call.

## Report data

These three don't need the key. Any user who can edit other people's posts (editor and up) can call them, with a wp-admin cookie or an Application Password. Handy for internal dashboards.

```
GET /laporan/ringkasan?dari=2026-10-01&sampai=2026-10-16&jenis=warga
GET /laporan/rincian?dari=2026-10-01&sampai=2026-10-16&penulis=812
GET /laporan/tulisan/{post_id}
```

`dari` (from) and `sampai` (to) are `YYYY-MM-DD` and default to the start of this month through today. `jenis` is `warga` (readers) or a writer role slug.
