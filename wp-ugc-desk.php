<?php
/*
Plugin Name: WP UGC Desk
Plugin URI: https://github.com/aconymouse/wp-ugc-desk
Description: Terima tulisan dari pembaca di WordPress headless. Akun penulis, antrean tinjauan, keputusan redaksi, notifikasi push, dan Laporan Tulisan.
Version: 1.0.0
Author: Hafidz Nc (Aconymous)
Author URI: https://aconymous.com
License: GPL-2.0-or-later
*/

if (!defined('ABSPATH')) {
    exit;
}

final class UGC_Desk
{
    const VERSI = '1.0.0';
    const OPSI_RAHASIA = 'ugcd_akun_secret';
    const OPSI_EMAIL = 'ugcd_laporan_email';
    const OPSI_SITUS = 'ugcd_situs';
    const OPSI_LIHAT = 'ugcd_lihat_keputusan';
    const OPSI_REPORTER = 'ugcd_peran_reporter';
    const OPSI_DB = 'ugcd_jejak_versi';
    const DB_VERSI = '1';
    const TABEL = 'ugcd_jejak';
    const MENU = 'ugcd-laporan-tulisan';
    const META_SUMBER = 'ugcd_naskah_sumber';
    const META_KEPUTUSAN_OLEH_ID = 'ugcd_keputusan_oleh_id';
    const PERAN_INTI = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
    const META_NASKAH = 'ugcd_naskah_kontributor';
    const META_NASKAH_ID = 'ugcd_naskah_id';
    const META_NASKAH_AKUN = 'ugcd_naskah_akun';
    const META_KEPUTUSAN = 'ugcd_keputusan';
    const META_ALASAN = 'ugcd_alasan';
    const META_TANDA = 'ugcd_keputusan_tanda';
    const META_KEPUTUSAN_OLEH = 'ugcd_keputusan_oleh';
    const META_KEPUTUSAN_WAKTU = 'ugcd_keputusan_waktu';
    const META_UBAH_PESAN = 'ugcd_ubah_pesan';
    const META_UBAH_STATUS = 'ugcd_ubah_status';
    const META_USER = 'ugcd_kontributor';
    const CPT = 'ugcd_laporan';
    const META_STATUS = 'ugcd_laporan_status';
    const KATEGORI_SLUG = 'kolumnis';
    const MEDSOS = ['instagram', 'x', 'threads', 'tiktok', 'linkedin', 'youtube'];

    private static $instance;

    public static function mulai()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'siapkanTabel'], 5);
        add_action('init', [$this, 'daftarCpt']);
        add_action('init', [$this, 'daftarMeta']);
        add_action('rest_api_init', [$this, 'daftarRute']);
        add_action('rest_after_insert_post', [$this, 'setelahSimpanPost'], 10, 2);
        add_action('transition_post_status', [$this, 'statusBerubah'], 10, 3);
        add_action('enqueue_block_editor_assets', [$this, 'skripEditor']);
        add_filter('wp_authenticate_user', [$this, 'tolakLoginKontributor'], 10, 2);
        add_filter('wp_is_application_passwords_available_for_user', [$this, 'tolakAppPassword'], 10, 2);
        add_filter('rest_authentication_errors', [$this, 'tolakRestKontributor'], 99);
        add_action('trashed_post', [$this, 'segarkanHitungan']);
        add_action('untrashed_post', [$this, 'segarkanHitungan']);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'menuLaporan']);
            add_action('admin_menu', [$this, 'menuPengaturan'], 20);
            add_action('admin_menu', [$this, 'urutkanMenu'], 998);
            add_filter('custom_menu_order', '__return_true');
            add_filter('menu_order', [$this, 'urutanMenuUtama'], 20);
            add_action('admin_menu', [$this, 'lencanaMenu'], 999);
            add_action('admin_init', [$this, 'daftarPengaturan']);
            add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'kolom']);
            add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'isiKolom'], 10, 2);
            add_filter('post_row_actions', [$this, 'aksiBaris'], 10, 2);
            add_filter('views_edit-' . self::CPT, [$this, 'tampilanStatus']);
            add_action('pre_get_posts', [$this, 'saringStatus']);
            add_action('admin_post_ugcd_laporan_status', [$this, 'ubahStatus']);
            add_action('add_meta_boxes_' . self::CPT, [$this, 'kotakPelapor']);
            add_filter('bulk_actions-edit-' . self::CPT, [$this, 'aksiMassal']);
            add_filter('handle_bulk_actions-edit-' . self::CPT, [$this, 'jalankanAksiMassal'], 10, 3);
            add_action('admin_notices', [$this, 'pemberitahuan']);
            add_filter('views_edit-post', [$this, 'tampilanWarga']);
            add_filter('display_post_states', [$this, 'labelWarga'], 10, 2);
            add_action('pre_get_posts', [$this, 'saringWarga']);
            add_action('admin_post_ugcd_ubah_selesai', [$this, 'ubahSelesai']);
            add_action('admin_post_ugcd_laporan_unduh', [$this, 'unduhLaporan']);
            add_action('admin_enqueue_scripts', [$this, 'skripLaporan']);
        }
    }

    private function rahasia()
    {
        $env = getenv('UGC_DESK_SECRET');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return (string) get_option(self::OPSI_RAHASIA, '');
    }

    private function emailLaporan()
    {
        $email = (string) get_option(self::OPSI_EMAIL, '');
        return is_email($email) ? $email : get_option('admin_email');
    }

    private function situs()
    {
        $situs = untrailingslashit((string) get_option(self::OPSI_SITUS, ''));
        return $situs !== '' ? $situs : untrailingslashit(home_url());
    }

    private function tabel()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABEL;
    }

    public function siapkanTabel()
    {
        if (get_option(self::OPSI_DB) === self::DB_VERSI) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $tabel = $this->tabel();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$tabel} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            penulis_id bigint(20) unsigned NOT NULL,
            sumber varchar(10) NOT NULL DEFAULT 'warga',
            kejadian varchar(12) NOT NULL,
            oleh_id bigint(20) unsigned NOT NULL DEFAULT 0,
            alasan text NULL,
            waktu datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY waktu (waktu),
            KEY penulis (penulis_id,waktu),
            KEY post (post_id)
        ) {$charset};");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabel)) !== $tabel) {
            return;
        }
        $kosong = !(int) $wpdb->get_var("SELECT COUNT(*) FROM {$tabel}");
        if ($kosong) {
            $this->isiJejakAwal();
        }
        update_option(self::OPSI_DB, self::DB_VERSI);
    }

    private function isiJejakAwal()
    {
        global $wpdb;
        $ids = get_posts([
            'post_type' => 'post',
            'post_status' => ['pending', 'draft', 'publish', 'future', 'private'],
            'meta_key' => self::META_NASKAH,
            'meta_value' => '1',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post) {
                continue;
            }
            if (!get_post_meta($id, self::META_SUMBER, true)) {
                update_post_meta($id, self::META_SUMBER, 'warga');
            }
            $dasar = [
                'post_id' => (int) $id,
                'penulis_id' => (int) $post->post_author,
                'sumber' => (string) get_post_meta($id, self::META_SUMBER, true),
            ];
            $masuk = $post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt : get_gmt_from_date($post->post_date);
            $ubah = $post->post_modified_gmt !== '0000-00-00 00:00:00' ? $post->post_modified_gmt : $masuk;
            $wpdb->insert($this->tabel(), $dasar + ['kejadian' => 'kirim', 'oleh_id' => 0, 'alasan' => '', 'waktu' => $masuk]);
            $keputusan = (string) get_post_meta($id, self::META_KEPUTUSAN, true);
            if ($post->post_status === 'draft' && in_array($keputusan, ['revisi', 'ditolak'], true)) {
                $wpdb->insert($this->tabel(), $dasar + [
                    'kejadian' => $keputusan === 'ditolak' ? 'tolak' : 'revisi',
                    'oleh_id' => (int) get_post_meta($id, self::META_KEPUTUSAN_OLEH_ID, true),
                    'alasan' => (string) get_post_meta($id, self::META_ALASAN, true),
                    'waktu' => $ubah,
                ]);
            } elseif (in_array($post->post_status, ['publish', 'future', 'private'], true)) {
                $wpdb->insert($this->tabel(), $dasar + ['kejadian' => 'terbit', 'oleh_id' => 0, 'alasan' => '', 'waktu' => $ubah]);
            }
        }
    }

    public function segarkanHitungan()
    {
        delete_transient('ugcd_hitung_warga');
        delete_transient('ugcd_laporan_baru');
    }

    private function catat($postId, $kejadian, $olehId = 0, $alasan = '')
    {
        global $wpdb;
        $post = get_post($postId);
        if (!$post) {
            return;
        }
        $sumber = (string) get_post_meta($post->ID, self::META_SUMBER, true);
        $data = [
            'post_id' => (int) $post->ID,
            'penulis_id' => (int) $post->post_author,
            'sumber' => $sumber === 'reporter' ? 'reporter' : 'warga',
            'kejadian' => $kejadian,
            'oleh_id' => (int) $olehId,
            'alasan' => mb_substr((string) $alasan, 0, 1000),
            'waktu' => current_time('mysql', true),
        ];
        $wpdb->insert($this->tabel(), $data);
        $this->segarkanHitungan();
        do_action('ugcd_jejak_dicatat', $data);
    }

    private function judulPolos($post)
    {
        $post = get_post($post);
        return $post ? html_entity_decode(wp_strip_all_tags($post->post_title), ENT_QUOTES, 'UTF-8') : '';
    }

    private function tautanPublik($post)
    {
        $permalink = get_permalink($post);
        if (!$permalink) {
            return null;
        }
        return str_replace(untrailingslashit(home_url()), $this->situs(), $permalink);
    }

    private function petaMedsos()
    {
        $peta = [
            'instagram' => 'instagram',
            'x' => 'twitter',
            'threads' => 'threads',
            'tiktok' => 'tiktok',
            'linkedin' => 'linkedin',
            'youtube' => 'youtube',
        ];
        return apply_filters('ugcd_kontributor_medsos_meta', $peta);
    }

    private function urlMedsos($jenis, $handle)
    {
        $h = rawurlencode($handle);
        switch ($jenis) {
            case 'instagram':
                return "https://www.instagram.com/{$h}/";
            case 'x':
                return "https://x.com/{$h}";
            case 'threads':
                return "https://www.threads.com/@{$h}";
            case 'tiktok':
                return "https://www.tiktok.com/@{$h}";
            case 'linkedin':
                return "https://www.linkedin.com/in/{$h}/";
            case 'youtube':
                return "https://www.youtube.com/@{$h}";
        }
        return '';
    }

    private function idKategoriKolumnis()
    {
        global $wpdb;
        $tabel = $wpdb->prefix . 'ppma_author_categories';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabel)) !== $tabel) {
            return 0;
        }
        $slug = apply_filters('ugcd_kontributor_kategori_slug', self::KATEGORI_SLUG);
        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tabel} WHERE slug = %s LIMIT 1", $slug));
    }

    public function daftarRute()
    {
        $izin = [$this, 'izin'];
        register_rest_route('ugc-desk/v1', '/kontributor/username-ada', [
            'methods' => 'GET',
            'callback' => [$this, 'usernameAda'],
            'permission_callback' => $izin,
        ]);
        $redaksi = function () {
            return current_user_can('edit_others_posts');
        };
        register_rest_route('ugc-desk/v1', '/laporan/ringkasan', [
            'methods' => 'GET',
            'callback' => function (WP_REST_Request $r) {
                return $this->apiRingkasan((string) $r->get_param('dari'), (string) $r->get_param('sampai'), (string) $r->get_param('jenis'));
            },
            'permission_callback' => $redaksi,
        ]);
        register_rest_route('ugc-desk/v1', '/laporan/rincian', [
            'methods' => 'GET',
            'callback' => function (WP_REST_Request $r) {
                return $this->apiRincian(
                    (string) $r->get_param('dari'),
                    (string) $r->get_param('sampai'),
                    (int) $r->get_param('penulis'),
                    (string) $r->get_param('jenis'),
                    $this->bolehLihatKeputusan()
                );
            },
            'permission_callback' => $redaksi,
        ]);
        register_rest_route('ugc-desk/v1', '/laporan/tulisan/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => function (WP_REST_Request $r) {
                return $this->apiJejakTulisan((int) $r->get_param('id'), $this->bolehLihatKeputusan());
            },
            'permission_callback' => function (WP_REST_Request $r) {
                return current_user_can('edit_post', (int) $r->get_param('id'));
            },
        ]);
        register_rest_route('ugc-desk/v1', '/kontributor/peran-reporter', [
            'methods' => 'GET',
            'callback' => function () {
                return ['peran' => $this->peranReporter()];
            },
            'permission_callback' => $izin,
        ]);
        register_rest_route('ugc-desk/v1', '/kontributor/akun-lama', [
            'methods' => 'GET',
            'callback' => [$this, 'akunLama'],
            'permission_callback' => $izin,
        ]);
        register_rest_route('ugc-desk/v1', '/kontributor', [
            'methods' => 'POST',
            'callback' => [$this, 'buatKontributor'],
            'permission_callback' => $izin,
        ]);
        register_rest_route('ugc-desk/v1', '/kontributor/(?P<id>\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [$this, 'perbaruiKontributor'],
                'permission_callback' => $izin,
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'kosongkanKontributor'],
                'permission_callback' => $izin,
            ],
        ]);
        register_rest_route('ugc-desk/v1', '/laporan', [
            'methods' => 'POST',
            'callback' => [$this, 'terimaLaporan'],
            'permission_callback' => $izin,
        ]);
        register_rest_route('ugc-desk/v1', '/naskah', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'daftarNaskah'],
                'permission_callback' => $izin,
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'terimaNaskah'],
                'permission_callback' => $izin,
            ],
        ]);
        register_rest_route('ugc-desk/v1', '/keputusan/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'siapaMemutuskan'],
            'permission_callback' => function (WP_REST_Request $r) {
                return $this->bolehLihatKeputusan() && current_user_can('edit_post', (int) $r->get_param('id'));
            },
        ]);
        register_rest_route('ugc-desk/v1', '/perubahan', [
            'methods' => 'POST',
            'callback' => [$this, 'terimaPerubahan'],
            'permission_callback' => $izin,
        ]);
        register_rest_route('ugc-desk/v1', '/naskah/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'isiNaskah'],
                'permission_callback' => $izin,
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'kirimUlangNaskah'],
                'permission_callback' => $izin,
            ],
        ]);
    }

    public function izin(WP_REST_Request $r)
    {
        $rahasia = $this->rahasia();
        $kiriman = (string) $r->get_header('x_ugc_desk_secret');
        if ($rahasia === '' || $kiriman === '' || !hash_equals($rahasia, $kiriman)) {
            return new WP_Error('ugcd_rahasia', 'Kunci akun tidak cocok.', ['status' => 403]);
        }
        if (!current_user_can('edit_users')) {
            return new WP_Error('ugcd_izin', 'Butuh Application Password administrator.', ['status' => 401]);
        }
        return true;
    }

    private function usernameValid($u)
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])?$/', $u);
    }

    private function usernameTerpakai($u, $kecualiUser = 0)
    {
        $uid = username_exists($u);
        if ($uid && (int) $uid !== (int) $kecualiUser) {
            return true;
        }
        $term = get_term_by('slug', $u, 'author');
        if ($term && !is_wp_error($term)) {
            $pemilik = (int) get_term_meta($term->term_id, 'user_id', true);
            if (!$kecualiUser || $pemilik !== (int) $kecualiUser) {
                return true;
            }
        }
        return false;
    }

    private function peranTambahan()
    {
        $peran = get_option(self::OPSI_REPORTER, []);
        if (!is_array($peran)) {
            return [];
        }
        $ada = array_keys(wp_roles()->get_names());
        return array_values(array_diff(array_intersect(array_map('sanitize_key', $peran), $ada), self::PERAN_INTI));
    }

    private function peranReporter()
    {
        $peran = apply_filters('ugcd_peran_penulis', array_merge(['author'], $this->peranTambahan()));
        return array_values(array_unique(array_filter(array_map('sanitize_key', (array) $peran))));
    }

    private function argPenulis($uid)
    {
        $kelas = $this->kelasAuthor();
        $author = $kelas ? $kelas::get_by_user_id((int) $uid) : null;
        if ($author && !empty($author->term_id)) {
            return ['tax_query' => [['taxonomy' => 'author', 'field' => 'term_id', 'terms' => [(int) $author->term_id]]]];
        }
        return ['author' => (int) $uid];
    }

    private function milikPenulis($postId, $uid)
    {
        $post = get_post($postId);
        if (!$post) {
            return false;
        }
        if ((int) $post->post_author === (int) $uid) {
            return true;
        }
        if (function_exists('is_multiple_author_for_post')) {
            return (bool) is_multiple_author_for_post((int) $uid, (int) $post->ID);
        }
        return false;
    }

    private function adalahReporter($user)
    {
        if (!$user instanceof WP_User || get_user_meta($user->ID, self::META_USER, true)) {
            return false;
        }
        return (bool) array_intersect((array) $user->roles, $this->peranReporter());
    }

    private function sumberPenulis($user)
    {
        return $this->adalahReporter($user) ? 'reporter' : 'warga';
    }

    private function bisaDiadopsi($user)
    {
        if (!$user instanceof WP_User || get_user_meta($user->ID, self::META_USER, true) || $this->adalahReporter($user)) {
            return false;
        }
        if (is_super_admin($user->ID) || user_can($user, 'publish_posts') || user_can($user, 'edit_others_posts') || user_can($user, 'list_users')) {
            return false;
        }
        $peran = array_values((array) $user->roles);
        return !empty($peran) && !array_diff($peran, ['contributor', 'subscriber']);
    }

    private function usernameAkun($uid)
    {
        $kelas = $this->kelasAuthor();
        $author = $kelas ? $kelas::get_by_user_id((int) $uid) : null;
        if ($author && !empty($author->slug)) {
            return (string) $author->slug;
        }
        $user = get_user_by('id', (int) $uid);
        return $user ? (string) $user->user_nicename : '';
    }

    public function akunLama(WP_REST_Request $r)
    {
        $email = sanitize_email((string) $r->get_param('email'));
        $uid = is_email($email) ? email_exists($email) : false;
        $user = $uid ? get_user_by('id', $uid) : null;
        if (!$user || !$this->bisaDiadopsi($user)) {
            return ['username' => null];
        }
        return ['username' => $this->usernameAkun($user->ID)];
    }

    public function usernameAda(WP_REST_Request $r)
    {
        $u = strtolower(sanitize_user((string) $r->get_param('u'), true));
        if (!$this->usernameValid($u)) {
            return ['ada' => true];
        }
        return ['ada' => $this->usernameTerpakai($u)];
    }

    private function pisahNama($nama)
    {
        $bagian = preg_split('/\s+/', trim($nama));
        $depan = array_shift($bagian);
        return [$depan, implode(' ', $bagian)];
    }

    private function bersihkanMedsos($mentah)
    {
        $hasil = [];
        if (!is_array($mentah)) {
            return $hasil;
        }
        foreach (self::MEDSOS as $jenis) {
            if (empty($mentah[$jenis]) || !is_string($mentah[$jenis])) {
                continue;
            }
            $nilai = preg_replace('/^https?:\/\/[^\/]+\//i', '', trim($mentah[$jenis]));
            $nilai = ltrim($nilai, '@');
            $nilai = rtrim($nilai, '/');
            $nilai = substr($nilai, 0, 60);
            if ($nilai !== '' && preg_match('/^[a-z0-9._-]+$/i', $nilai)) {
                $hasil[$jenis] = $nilai;
            }
        }
        return $hasil;
    }

    private function kelasAuthor()
    {
        return class_exists('MultipleAuthors\\Classes\\Objects\\Author') ? 'MultipleAuthors\\Classes\\Objects\\Author' : null;
    }

    private function unduhAvatar($url, $nama)
    {
        if (!$url || !preg_match('#^https://#i', $url)) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($url, 20);
        if (is_wp_error($tmp)) {
            return 0;
        }
        $jenis = wp_get_image_mime($tmp);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$jenis || !isset($ext[$jenis])) {
            @unlink($tmp);
            return 0;
        }
        $berkas = [
            'name' => 'avatar-' . sanitize_title($nama) . '-' . wp_generate_password(6, false) . '.' . $ext[$jenis],
            'tmp_name' => $tmp,
            'type' => $jenis,
        ];
        $id = media_handle_sideload($berkas, 0, $nama);
        if (is_wp_error($id)) {
            @unlink($tmp);
            return 0;
        }
        return (int) $id;
    }

    private function isiTerm($termId, $data, $avatarId)
    {
        update_term_meta($termId, 'job_title', $data['jabatan']);
        update_term_meta($termId, 'description', $data['bio']);
        update_term_meta($termId, 'user_url', $data['website']);
        $peta = $this->petaMedsos();
        foreach (self::MEDSOS as $jenis) {
            if (empty($peta[$jenis])) {
                continue;
            }
            $kunci = $peta[$jenis];
            if (!empty($data['medsos'][$jenis])) {
                update_term_meta($termId, $kunci, $this->urlMedsos($jenis, $data['medsos'][$jenis]));
            } else {
                delete_term_meta($termId, $kunci);
            }
        }
        if ($avatarId) {
            update_term_meta($termId, 'avatar', $avatarId);
        }
        $kategori = $this->idKategoriKolumnis();
        if ($kategori) {
            update_term_meta($termId, 'author_category', $kategori);
        }
        do_action('ugcd_kontributor_term_diperbarui', $termId, $data);
    }

    private function bacaData(WP_REST_Request $r)
    {
        $nama = sanitize_text_field((string) $r->get_param('nama'));
        $nama = trim(preg_replace('/\s+/', ' ', $nama));
        return [
            'nama' => mb_substr($nama, 0, 60),
            'jabatan' => mb_substr(sanitize_text_field((string) $r->get_param('jabatan')), 0, 80),
            'bio' => mb_substr(sanitize_textarea_field((string) $r->get_param('bio')), 0, 600),
            'website' => esc_url_raw((string) $r->get_param('website')),
            'medsos' => $this->bersihkanMedsos($r->get_param('medsos')),
            'avatarUrl' => esc_url_raw((string) $r->get_param('avatarUrl')),
        ];
    }

    private function balasan($uid, $termId, $avatarId)
    {
        return [
            'wp_user_id' => (int) $uid,
            'username' => $this->usernameAkun($uid),
            'ppma_term_id' => (int) $termId,
            'avatar_media_id' => $avatarId ? (int) $avatarId : null,
            'avatar_url' => $avatarId ? wp_get_attachment_image_url($avatarId, 'full') : null,
        ];
    }

    public function buatKontributor(WP_REST_Request $r)
    {
        $kelas = $this->kelasAuthor();
        if (!$kelas) {
            return new WP_Error('ugcd_ppma', 'PublishPress Authors tidak aktif.', ['status' => 500]);
        }
        $username = strtolower(sanitize_user((string) $r->get_param('username'), true));
        $email = sanitize_email((string) $r->get_param('email'));
        $data = $this->bacaData($r);
        if (!is_email($email)) {
            return new WP_Error('ugcd_email', 'Email tidak valid.', ['status' => 400]);
        }
        if (mb_strlen($data['nama']) < 2) {
            return new WP_Error('ugcd_nama', 'Nama terlalu pendek.', ['status' => 400]);
        }

        $uid = email_exists($email);
        if ($uid) {
            $lama = get_user_by('id', $uid);
            if (!get_user_meta($uid, self::META_USER, true)) {
                if (!$this->bisaDiadopsi($lama)) {
                    return new WP_Error('ugcd_email_redaksi', 'Email ini milik akun redaksi.', ['status' => 409]);
                }
                if (in_array('subscriber', (array) $lama->roles, true)) {
                    $lama->set_role('contributor');
                }
                list($depan, $belakang) = $this->pisahNama($data['nama']);
                wp_update_user([
                    'ID' => $uid,
                    'display_name' => $data['nama'],
                    'first_name' => $depan,
                    'last_name' => $belakang,
                    'user_url' => $data['website'],
                    'description' => $data['bio'],
                    'show_admin_bar_front' => 'false',
                ]);
                update_user_meta($uid, self::META_USER, 1);
                update_user_meta($uid, self::META_USER . '_sejak', current_time('mysql'));
                update_user_meta($uid, self::META_USER . '_adopsi', current_time('mysql'));
                WP_Session_Tokens::get_instance($uid)->destroy_all();
            }
        } else {
            if (!$this->usernameValid($username)) {
                return new WP_Error('ugcd_username', 'Username tidak valid.', ['status' => 400]);
            }
            if ($this->usernameTerpakai($username)) {
                return new WP_Error('ugcd_username_ada', 'Username sudah dipakai.', ['status' => 409]);
            }
            list($depan, $belakang) = $this->pisahNama($data['nama']);
            $uid = wp_insert_user([
                'user_login' => $username,
                'user_nicename' => $username,
                'user_email' => $email,
                'user_pass' => wp_generate_password(48, true, true),
                'display_name' => $data['nama'],
                'first_name' => $depan,
                'last_name' => $belakang,
                'user_url' => $data['website'],
                'description' => $data['bio'],
                'role' => 'contributor',
                'show_admin_bar_front' => 'false',
            ]);
            if (is_wp_error($uid)) {
                return new WP_Error('ugcd_user', $uid->get_error_message(), ['status' => 500]);
            }
            update_user_meta($uid, self::META_USER, 1);
            update_user_meta($uid, self::META_USER . '_sejak', current_time('mysql'));
        }

        $author = $kelas::get_by_user_id((int) $uid);
        if (!$author || empty($author->term_id)) {
            $author = $kelas::create_from_user((int) $uid);
        }
        if (!$author || is_wp_error($author) || empty($author->term_id)) {
            return new WP_Error('ugcd_term', 'Profil penulis gagal dibuat.', ['status' => 500]);
        }
        $termId = (int) $author->term_id;
        $kelas::update_author_from_user($termId, (int) $uid);
        $avatarId = (int) get_term_meta($termId, 'avatar', true);
        if (!$avatarId && $data['avatarUrl']) {
            $avatarId = $this->unduhAvatar($data['avatarUrl'], $data['nama']);
        }
        $this->isiTerm($termId, $data, $avatarId);
        if (method_exists($kelas, 'clear_cache')) {
            $kelas::clear_cache();
        }
        return $this->balasan($uid, $termId, $avatarId);
    }

    private function ambilKontributor($id)
    {
        $user = get_user_by('id', (int) $id);
        if (!$user || !get_user_meta($user->ID, self::META_USER, true)) {
            return null;
        }
        return $user;
    }

    public function perbaruiKontributor(WP_REST_Request $r)
    {
        $kelas = $this->kelasAuthor();
        $user = $this->ambilKontributor($r->get_param('id'));
        if (!$user) {
            return new WP_Error('ugcd_user', 'Kontributor tidak ditemukan.', ['status' => 404]);
        }
        $data = $this->bacaData($r);
        if (mb_strlen($data['nama']) < 2) {
            return new WP_Error('ugcd_nama', 'Nama terlalu pendek.', ['status' => 400]);
        }
        list($depan, $belakang) = $this->pisahNama($data['nama']);
        wp_update_user([
            'ID' => $user->ID,
            'display_name' => $data['nama'],
            'first_name' => $depan,
            'last_name' => $belakang,
            'user_url' => $data['website'],
            'description' => $data['bio'],
        ]);
        $author = $kelas ? $kelas::get_by_user_id($user->ID) : null;
        if (!$author || empty($author->term_id)) {
            $author = $kelas ? $kelas::create_from_user($user->ID) : null;
        }
        if (!$author || is_wp_error($author) || empty($author->term_id)) {
            return new WP_Error('ugcd_term', 'Profil penulis tidak ditemukan.', ['status' => 500]);
        }
        $termId = (int) $author->term_id;
        $kelas::update_author_from_user($termId, $user->ID);
        $avatarId = (int) $r->get_param('avatar_media_id');
        if (!$avatarId) {
            $avatarId = (int) get_term_meta($termId, 'avatar', true);
        }
        $this->isiTerm($termId, $data, $avatarId);
        if (method_exists($kelas, 'clear_cache')) {
            $kelas::clear_cache();
        }
        return $this->balasan($user->ID, $termId, $avatarId);
    }

    public function kosongkanKontributor(WP_REST_Request $r)
    {
        $kelas = $this->kelasAuthor();
        $user = $this->ambilKontributor($r->get_param('id'));
        if (!$user) {
            return new WP_Error('ugcd_user', 'Kontributor tidak ditemukan.', ['status' => 404]);
        }
        wp_update_user(['ID' => $user->ID, 'user_url' => '', 'description' => '']);
        $author = $kelas ? $kelas::get_by_user_id($user->ID) : null;
        if ($author && !empty($author->term_id)) {
            $kosong = ['nama' => $user->display_name, 'jabatan' => '', 'bio' => '', 'website' => '', 'medsos' => []];
            $this->isiTerm((int) $author->term_id, $kosong, 0);
        }
        update_user_meta($user->ID, self::META_USER . '_nonaktif', current_time('mysql'));
        return ['ok' => true];
    }

    public function tolakLoginKontributor($user, $password)
    {
        if ($user instanceof WP_User && get_user_meta($user->ID, self::META_USER, true)) {
            return new WP_Error('ugcd_kontributor', 'Akun kontributor masuk lewat situs depan, bukan lewat cms.');
        }
        return $user;
    }

    public function tolakAppPassword($tersedia, $user)
    {
        if ($user instanceof WP_User && get_user_meta($user->ID, self::META_USER, true)) {
            return false;
        }
        return $tersedia;
    }

    public function tolakRestKontributor($hasil)
    {
        if (!empty($hasil)) {
            return $hasil;
        }
        $uid = get_current_user_id();
        if ($uid && get_user_meta($uid, self::META_USER, true)) {
            return new WP_Error('ugcd_kontributor', 'Akun warga tidak punya akses cms.', ['status' => 403]);
        }
        return $hasil;
    }

    public function daftarCpt()
    {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Laporan warga',
                'singular_name' => 'Laporan',
                'menu_name' => 'Laporan warga',
                'all_items' => 'Laporan warga',
                'edit_item' => 'Lihat Laporan',
                'search_items' => 'Cari laporan',
                'not_found' => 'Belum ada laporan.',
                'not_found_in_trash' => 'Tidak ada laporan di sampah.',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => self::MENU,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'capabilities' => ['create_posts' => 'do_not_allow', 'edit_posts' => 'edit_others_posts'],
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    public function terimaLaporan(WP_REST_Request $r)
    {
        $postId = (int) $r->get_param('post_id');
        $pesan = sanitize_textarea_field((string) $r->get_param('pesan'));
        $pesan = trim($pesan);
        $kata = count(preg_split('/\s+/', $pesan, -1, PREG_SPLIT_NO_EMPTY));
        $artikel = $postId ? get_post($postId) : null;
        if (!$artikel || $artikel->post_type !== 'post') {
            return new WP_Error('ugcd_artikel', 'Artikel tidak ditemukan.', ['status' => 404]);
        }
        if ($kata < 60 || $kata > 135) {
            return new WP_Error('ugcd_pesan', 'Pesan harus 60 sampai 135 kata.', ['status' => 400]);
        }
        $nama = mb_substr(sanitize_text_field((string) $r->get_param('nama')), 0, 60);
        $email = sanitize_email((string) $r->get_param('email'));
        $username = strtolower(sanitize_user((string) $r->get_param('username'), true));
        $wpUserId = (int) $r->get_param('wp_user_id');
        $akunId = sanitize_text_field((string) $r->get_param('akun_id'));
        $ip = sanitize_text_field((string) $r->get_param('ip'));

        $id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => get_the_title($artikel),
            'post_content' => $pesan,
            'post_author' => $wpUserId ?: 0,
        ], true);
        if (is_wp_error($id)) {
            return new WP_Error('ugcd_laporan', 'Laporan gagal disimpan.', ['status' => 500]);
        }
        update_post_meta($id, 'ugcd_laporan_post', $postId);
        update_post_meta($id, 'ugcd_laporan_nama', $nama);
        update_post_meta($id, 'ugcd_laporan_email', $email);
        update_post_meta($id, 'ugcd_laporan_username', $username);
        update_post_meta($id, 'ugcd_laporan_akun', $akunId);
        update_post_meta($id, 'ugcd_laporan_ip', $ip);
        update_post_meta($id, self::META_STATUS, 'baru');
        $this->segarkanHitungan();

        $ke = $this->emailLaporan();
        if ($ke) {
            $tautan = admin_url('post.php?post=' . $id . '&action=edit');
            $isi = "Ada laporan baru dari pembaca.\n\nArtikel: " . get_the_title($artikel) . "\n" . get_permalink($artikel)
                . "\n\nPelapor: {$nama} ({$email})\n\nPesan:\n{$pesan}\n\nBuka di cms:\n{$tautan}\n";
            wp_mail($ke, 'Laporan warga: ' . get_the_title($artikel), $isi);
        }
        return ['id' => (int) $id];
    }

    public function daftarMeta()
    {
        $boleh = function () {
            return current_user_can('edit_others_posts');
        };
        register_post_meta('post', self::META_NASKAH, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $boleh,
        ]);
        register_post_meta('post', self::META_KEPUTUSAN, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => function ($nilai) {
                return in_array($nilai, ['ditolak', 'revisi'], true) ? $nilai : '';
            },
            'auth_callback' => $boleh,
        ]);
        register_post_meta('post', self::META_UBAH_PESAN, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $boleh,
        ]);
        register_post_meta('post', self::META_UBAH_STATUS, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => function ($nilai) {
                return $nilai === 'baru' ? 'baru' : '';
            },
            'auth_callback' => $boleh,
        ]);
        register_post_meta('post', self::META_ALASAN, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => function ($nilai) {
                return mb_substr(sanitize_textarea_field((string) $nilai), 0, 1000);
            },
            'auth_callback' => $boleh,
        ]);
    }

    private function daftarPelihat()
    {
        $ids = get_option(self::OPSI_LIHAT, []);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    private function bolehLihatKeputusan()
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        return in_array(get_current_user_id(), $this->daftarPelihat(), true);
    }

    public function siapaMemutuskan(WP_REST_Request $r)
    {
        $id = (int) $r->get_param('id');
        return [
            'oleh' => (string) get_post_meta($id, self::META_KEPUTUSAN_OLEH, true),
            'waktu' => (string) get_post_meta($id, self::META_KEPUTUSAN_WAKTU, true),
        ];
    }

    public function skripEditor()
    {
        $layar = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$layar || $layar->post_type !== 'post' || !current_user_can('edit_others_posts')) {
            return;
        }
        wp_enqueue_script(
            'ugcd-keputusan',
            plugins_url('keputusan.js', __FILE__),
            ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch'],
            self::VERSI,
            true
        );
        wp_add_inline_script('ugcd-keputusan', 'window.ugcdKeputusan=' . wp_json_encode(['lihat' => $this->bolehLihatKeputusan()]) . ';', 'before');
    }

    private function statusNaskah($post)
    {
        $status = $post->post_status;
        $keputusan = (string) get_post_meta($post->ID, self::META_KEPUTUSAN, true);
        if ($status === 'draft' && $keputusan === 'ditolak') {
            return 'ditolak';
        }
        if ($status === 'future' || $status === 'private') {
            return 'publish';
        }
        return $status;
    }

    private function ringkasNaskah($post)
    {
        $keputusan = (string) get_post_meta($post->ID, self::META_KEPUTUSAN, true);
        $naskahId = (string) get_post_meta($post->ID, self::META_NASKAH_ID, true);
        return [
            'id' => (int) $post->ID,
            'judul' => $this->judulPolos($post),
            'status' => $this->statusNaskah($post),
            'tanggal' => get_post_modified_time('c', false, $post) ?: current_time('c'),
            'link' => $post->post_status === 'publish' ? $this->tautanPublik($post) : null,
            'keputusan' => $keputusan !== '' ? $keputusan : null,
            'alasan' => $keputusan !== '' ? (string) get_post_meta($post->ID, self::META_ALASAN, true) : null,
            'naskah_id' => $naskahId !== '' ? $naskahId : null,
            'perubahan' => get_post_meta($post->ID, self::META_UBAH_STATUS, true) === 'baru',
        ];
    }

    private function postMilik($id, $wpUserId)
    {
        $post = get_post((int) $id);
        if (!$post || $post->post_type !== 'post') {
            return null;
        }
        if (get_post_meta($post->ID, self::META_NASKAH, true)) {
            return (int) $post->post_author === (int) $wpUserId ? $post : null;
        }
        $user = get_user_by('id', (int) $wpUserId);
        return $this->adalahReporter($user) && $this->milikPenulis($post->ID, $wpUserId) ? $post : null;
    }

    public function daftarNaskah(WP_REST_Request $r)
    {
        $wpUserId = (int) $r->get_param('wp_user_id');
        if (!$wpUserId) {
            return new WP_Error('ugcd_user', 'wp_user_id kosong.', ['status' => 400]);
        }
        $dasar = [
            'post_type' => 'post',
            'author' => $wpUserId,
            'posts_per_page' => 50,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        ];
        $daftar = get_posts($dasar + [
            'post_status' => ['pending', 'draft', 'publish', 'future', 'private'],
            'meta_key' => self::META_NASKAH,
            'meta_value' => '1',
        ]);
        if ($this->adalahReporter(get_user_by('id', $wpUserId))) {
            $lain = $dasar;
            unset($lain['author']);
            $daftar = array_merge($daftar, get_posts($this->argPenulis($wpUserId) + $lain + [
                'post_status' => ['pending', 'publish', 'future', 'private'],
                'meta_query' => [['key' => self::META_NASKAH, 'compare' => 'NOT EXISTS']],
            ]));
            usort($daftar, function ($a, $b) {
                return strcmp($b->post_modified_gmt, $a->post_modified_gmt);
            });
            $daftar = array_slice($daftar, 0, 50);
        }
        return array_map(function ($post) {
            return $this->ringkasNaskah($post);
        }, $daftar);
    }

    public function isiNaskah(WP_REST_Request $r)
    {
        $post = $this->postMilik($r->get_param('id'), (int) $r->get_param('wp_user_id'));
        if (!$post) {
            return new WP_Error('ugcd_naskah', 'Naskah tidak ditemukan.', ['status' => 404]);
        }
        $kategori = wp_get_post_categories($post->ID);
        $tag = wp_get_post_tags($post->ID, ['fields' => 'names']);
        $gambarId = (int) get_post_thumbnail_id($post->ID);
        $src = $gambarId ? wp_get_attachment_image_url($gambarId, 'full') : null;
        return array_merge($this->ringkasNaskah($post), [
            'dek' => (string) $post->post_excerpt,
            'konten' => (string) $post->post_content,
            'kategori_id' => $kategori ? (int) $kategori[0] : null,
            'tag' => is_array($tag) ? array_values($tag) : [],
            'gambar_utama' => $gambarId && $src ? ['id' => $gambarId, 'src' => $src] : null,
        ]);
    }

    private function bacaNaskah(WP_REST_Request $r)
    {
        $judul = trim(preg_replace('/\s+/', ' ', sanitize_text_field((string) $r->get_param('judul'))));
        $tag = $r->get_param('tag');
        $tag = is_array($tag) ? array_slice(array_values(array_filter(array_map(function ($t) {
            return mb_substr(sanitize_text_field((string) $t), 0, 40);
        }, $tag))), 0, 5) : [];
        return [
            'wp_user_id' => (int) $r->get_param('wp_user_id'),
            'akun_id' => sanitize_text_field((string) $r->get_param('akun_id')),
            'naskah_id' => sanitize_text_field((string) $r->get_param('naskah_id')),
            'judul' => mb_substr($judul, 0, 120),
            'dek' => mb_substr(sanitize_textarea_field((string) $r->get_param('dek')), 0, 200),
            'kategori_id' => (int) $r->get_param('kategori_id'),
            'tag' => $tag,
            'gambar_utama_id' => (int) $r->get_param('gambar_utama_id'),
            'konten' => (string) $r->get_param('konten'),
            'kata' => (int) $r->get_param('kata'),
        ];
    }

    private function cekNaskah($data)
    {
        $user = get_user_by('id', $data['wp_user_id']);
        if (!$user || (!get_user_meta($user->ID, self::META_USER, true) && !$this->adalahReporter($user))) {
            return new WP_Error('ugcd_user', 'Akun penulis tidak ditemukan.', ['status' => 404]);
        }
        if (mb_strlen($data['judul']) < 10) {
            return new WP_Error('ugcd_judul', 'Judul terlalu pendek.', ['status' => 400]);
        }
        if (!$data['kategori_id'] || !term_exists($data['kategori_id'], 'category')) {
            return new WP_Error('ugcd_kategori', 'Kategori tidak ditemukan.', ['status' => 400]);
        }
        if ($data['gambar_utama_id']) {
            $gambar = get_post($data['gambar_utama_id']);
            if (!$gambar || $gambar->post_type !== 'attachment' || !wp_attachment_is_image($gambar)) {
                return new WP_Error('ugcd_gambar', 'Gambar utama tidak ditemukan.', ['status' => 400]);
            }
        }
        if (trim(wp_strip_all_tags($data['konten'])) === '') {
            return new WP_Error('ugcd_konten', 'Isi naskah kosong.', ['status' => 400]);
        }
        if (strlen($data['konten']) > 400000) {
            return new WP_Error('ugcd_konten', 'Isi naskah terlalu panjang.', ['status' => 400]);
        }
        return $user;
    }

    private function isiPost($postId, $data, $sumber = 'warga')
    {
        update_post_meta($postId, self::META_SUMBER, $sumber);
        wp_set_post_categories($postId, [$data['kategori_id']]);
        if (!empty($data['tag'])) {
            wp_set_post_terms($postId, $data['tag'], 'post_tag', false);
        }
        if ($data['gambar_utama_id']) {
            set_post_thumbnail($postId, $data['gambar_utama_id']);
            $this->isiAltGambar($data['gambar_utama_id'], $data['judul']);
        }
        if (preg_match_all('/wp-image-(\d+)/', $data['konten'], $m)) {
            foreach (array_unique($m[1]) as $gambarId) {
                $this->isiAltGambar((int) $gambarId, $data['judul']);
            }
        }
        update_post_meta($postId, self::META_NASKAH, 1);
        update_post_meta($postId, self::META_NASKAH_ID, $data['naskah_id']);
        update_post_meta($postId, self::META_NASKAH_AKUN, $data['akun_id']);
        update_post_meta($postId, 'ugcd_naskah_kata', $data['kata']);
        $dekLama = (string) get_post_meta($postId, 'ugcd_naskah_dek', true);
        $metaDesc = (string) get_post_meta($postId, '_yoast_wpseo_metadesc', true);
        if ($data['dek'] !== '' && ($metaDesc === '' || $metaDesc === $dekLama)) {
            update_post_meta($postId, '_yoast_wpseo_metadesc', $data['dek']);
        }
        update_post_meta($postId, 'ugcd_naskah_dek', $data['dek']);
        delete_post_meta($postId, self::META_KEPUTUSAN);
        delete_post_meta($postId, self::META_ALASAN);
        delete_post_meta($postId, self::META_TANDA);
    }

    private function isiAltGambar($gambarId, $judul)
    {
        if (!$gambarId || !$judul || get_post_type($gambarId) !== 'attachment') {
            return;
        }
        if ((string) get_post_meta($gambarId, '_wp_attachment_image_alt', true) === '') {
            update_post_meta($gambarId, '_wp_attachment_image_alt', $judul);
        }
    }

    private function pasangPenulis($postId, $uid)
    {
        $kelas = $this->kelasAuthor();
        if (!$kelas || !class_exists('MultipleAuthors\\Classes\\Utils')) {
            return;
        }
        $author = $kelas::get_by_user_id((int) $uid);
        if (!$author || empty($author->term_id)) {
            $author = $kelas::create_from_user((int) $uid);
        }
        if (!$author || is_wp_error($author) || empty($author->term_id)) {
            return;
        }
        $kategori = $this->adalahReporter(get_user_by('id', (int) $uid)) ? 0 : $this->idKategoriKolumnis();
        $peta = $kategori ? [(int) $author->term_id => [$kategori]] : [];
        \MultipleAuthors\Classes\Utils::set_post_authors($postId, [$author], true, (int) $uid, $peta);
    }

    private function kabariRedaksi($postId, $user, $ulang)
    {
        $ke = $this->emailLaporan();
        if (!$ke) {
            return;
        }
        $judul = $this->judulPolos($postId);
        $tautan = admin_url('post.php?post=' . $postId . '&action=edit');
        $asal = $this->adalahReporter($user) ? 'penulis' : 'warga';
        $isi = ($ulang ? "Naskah revisi dari {$asal} sudah masuk lagi." : "Ada naskah baru dari {$asal}.")
            . "\n\nJudul: {$judul}\nPenulis: {$user->display_name} ({$user->user_email})"
            . "\n\nBuka di cms:\n{$tautan}\n";
        wp_mail($ke, ($ulang ? 'Naskah revisi: ' : 'Naskah baru: ') . $judul, $isi);
    }

    public function terimaNaskah(WP_REST_Request $r)
    {
        $data = $this->bacaNaskah($r);
        $user = $this->cekNaskah($data);
        if (is_wp_error($user)) {
            return $user;
        }
        $postId = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'pending',
            'post_title' => $data['judul'],
            'post_excerpt' => $data['dek'],
            'post_content' => wp_kses_post($data['konten']),
            'post_author' => $user->ID,
            'comment_status' => get_default_comment_status(),
        ], true);
        if (is_wp_error($postId)) {
            return new WP_Error('ugcd_naskah', 'Naskah gagal disimpan.', ['status' => 500]);
        }
        $this->isiPost($postId, $data, $this->sumberPenulis($user));
        $this->pasangPenulis($postId, $user->ID);
        $this->catat($postId, 'kirim');
        $this->kabariRedaksi($postId, $user, false);
        do_action('ugcd_naskah_masuk', $postId, $user->ID, false);
        return ['post_id' => (int) $postId];
    }

    public function kirimUlangNaskah(WP_REST_Request $r)
    {
        $data = $this->bacaNaskah($r);
        $user = $this->cekNaskah($data);
        if (is_wp_error($user)) {
            return $user;
        }
        $post = $this->postMilik($r->get_param('id'), $user->ID);
        if (!$post) {
            return new WP_Error('ugcd_naskah', 'Naskah tidak ditemukan.', ['status' => 404]);
        }
        if ($post->post_status !== 'draft' || get_post_meta($post->ID, self::META_KEPUTUSAN, true) !== 'revisi') {
            return new WP_Error('ugcd_status', 'Naskah ini tidak sedang diminta revisi.', ['status' => 409]);
        }
        $hasil = wp_update_post([
            'ID' => $post->ID,
            'post_status' => 'pending',
            'post_title' => $data['judul'],
            'post_excerpt' => $data['dek'],
            'post_content' => wp_kses_post($data['konten']),
        ], true);
        if (is_wp_error($hasil)) {
            return new WP_Error('ugcd_naskah', 'Naskah gagal diperbarui.', ['status' => 500]);
        }
        $this->isiPost($post->ID, $data, $this->sumberPenulis($user));
        $this->pasangPenulis($post->ID, $user->ID);
        $this->catat($post->ID, 'kirim_ulang');
        $this->kabariRedaksi($post->ID, $user, true);
        do_action('ugcd_naskah_masuk', $post->ID, $user->ID, true);
        return ['post_id' => (int) $post->ID];
    }

    private function kabariSitus($post, $kejadian, $catatan = null)
    {
        $rahasia = $this->rahasia();
        if ($rahasia === '') {
            return;
        }
        $keputusan = (string) get_post_meta($post->ID, self::META_KEPUTUSAN, true);
        $body = [
            'kejadian' => $kejadian,
            'post_id' => (int) $post->ID,
            'naskah_id' => (string) get_post_meta($post->ID, self::META_NASKAH_ID, true),
            'akun_id' => (string) get_post_meta($post->ID, self::META_NASKAH_AKUN, true),
            'wp_user_id' => (int) $post->post_author,
            'judul' => $this->judulPolos($post),
            'alasan' => $catatan !== null ? (string) $catatan : ($keputusan !== '' ? (string) get_post_meta($post->ID, self::META_ALASAN, true) : ''),
            'link' => $post->post_status === 'publish' ? $this->tautanPublik($post) : null,
        ];
        wp_remote_post($this->situs() . '/api/akun/webhook/', [
            'timeout' => 8,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-UGC-Desk-Secret' => $rahasia,
            ],
            'body' => wp_json_encode($body),
        ]);
    }

    public function terimaPerubahan(WP_REST_Request $r)
    {
        $post = $this->postMilik($r->get_param('post_id'), (int) $r->get_param('wp_user_id'));
        if (!$post || !in_array($post->post_status, ['publish', 'private'], true)) {
            return new WP_Error('ugcd_naskah', 'Tulisan tidak ditemukan.', ['status' => 404]);
        }
        $pesan = trim(sanitize_textarea_field((string) $r->get_param('pesan')));
        if (mb_strlen($pesan) < 30 || mb_strlen($pesan) > 1000) {
            return new WP_Error('ugcd_pesan', 'Pesan harus 30 sampai 1000 karakter.', ['status' => 400]);
        }
        if (get_post_meta($post->ID, self::META_UBAH_STATUS, true) === 'baru') {
            return new WP_Error('ugcd_ubah_ada', 'Permintaan sebelumnya masih ditangani redaksi.', ['status' => 409]);
        }
        $nama = mb_substr(sanitize_text_field((string) $r->get_param('nama')), 0, 60);
        $email = sanitize_email((string) $r->get_param('email'));
        update_post_meta($post->ID, self::META_UBAH_PESAN, $pesan);
        update_post_meta($post->ID, self::META_UBAH_STATUS, 'baru');
        update_post_meta($post->ID, 'ugcd_ubah_waktu', current_time('mysql'));
        update_post_meta($post->ID, 'ugcd_ubah_dari', $nama . ' <' . $email . '>');
        $this->segarkanHitungan();

        $ke = $this->emailLaporan();
        if ($ke) {
            $tautan = admin_url('post.php?post=' . $post->ID . '&action=edit');
            $isi = "Penulis minta perubahan pada tulisan yang sudah terbit.\n\nTulisan: " . $this->judulPolos($post) . "\n" . $this->tautanPublik($post)
                . "\n\nPenulis: {$nama} ({$email})\n\nPermintaan:\n{$pesan}\n\nBuka di cms:\n{$tautan}\n";
            wp_mail($ke, 'Permintaan ubah: ' . $this->judulPolos($post), $isi);
        }
        return ['ok' => true];
    }

    public function ubahSelesai()
    {
        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$postId || !current_user_can('edit_post', $postId)) {
            wp_die('Tidak diizinkan.');
        }
        check_admin_referer('ugcd_ubah_' . $postId);
        delete_post_meta($postId, self::META_UBAH_STATUS);
        $this->segarkanHitungan();
        $this->kabariUbahSelesai($postId);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php'));
        exit;
    }

    private function kabariUbahSelesai($postId)
    {
        $post = get_post($postId);
        if (!$post || !in_array($post->post_status, ['publish', 'private'], true)) {
            return;
        }
        if (get_post_meta($post->ID, self::META_UBAH_STATUS, true) === 'baru') {
            return;
        }
        $waktu = (string) get_post_meta($post->ID, 'ugcd_ubah_waktu', true);
        if ($waktu === '' || (string) get_post_meta($post->ID, 'ugcd_ubah_dikabari', true) === $waktu) {
            return;
        }
        update_post_meta($post->ID, 'ugcd_ubah_dikabari', $waktu);
        $this->kabariSitus($post, 'ubah-selesai', (string) get_post_meta($post->ID, self::META_UBAH_PESAN, true));
    }

    public function setelahSimpanPost($post, $request)
    {
        if (!$post || $post->post_type !== 'post' || !get_post_meta($post->ID, self::META_NASKAH, true)) {
            return;
        }
        $this->segarkanHitungan();
        $this->kabariUbahSelesai($post->ID);
        $keputusan = (string) get_post_meta($post->ID, self::META_KEPUTUSAN, true);
        if ($keputusan === '') {
            delete_post_meta($post->ID, self::META_TANDA);
            return;
        }
        $alasan = (string) get_post_meta($post->ID, self::META_ALASAN, true);
        $tanda = $keputusan . '|' . md5($alasan);
        if ((string) get_post_meta($post->ID, self::META_TANDA, true) === $tanda) {
            return;
        }
        update_post_meta($post->ID, self::META_TANDA, $tanda);
        $pengguna = wp_get_current_user();
        update_post_meta($post->ID, self::META_KEPUTUSAN_OLEH, $pengguna && $pengguna->exists() ? $pengguna->display_name : '');
        update_post_meta($post->ID, self::META_KEPUTUSAN_OLEH_ID, $pengguna && $pengguna->exists() ? (int) $pengguna->ID : 0);
        update_post_meta($post->ID, self::META_KEPUTUSAN_WAKTU, wp_date('j M Y H.i'));
        $this->catat($post->ID, $keputusan === 'ditolak' ? 'tolak' : 'revisi', $pengguna && $pengguna->exists() ? (int) $pengguna->ID : 0, $alasan);
        if ($post->post_status === 'pending') {
            remove_action('transition_post_status', [$this, 'statusBerubah'], 10);
            wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
            add_action('transition_post_status', [$this, 'statusBerubah'], 10, 3);
            $post = get_post($post->ID);
        }
        $this->kabariSitus($post, $keputusan);
    }

    public function statusBerubah($baru, $lama, $post)
    {
        $tayang = ['publish', 'private'];
        if (!in_array($baru, $tayang, true) || in_array($lama, $tayang, true) || !$post || $post->post_type !== 'post') {
            return;
        }
        if (!get_post_meta($post->ID, self::META_NASKAH, true)) {
            return;
        }
        delete_post_meta($post->ID, self::META_KEPUTUSAN);
        delete_post_meta($post->ID, self::META_ALASAN);
        delete_post_meta($post->ID, self::META_TANDA);
        $this->catat($post->ID, 'terbit', get_current_user_id());
        if ($baru === 'publish') {
            $this->kabariSitus($post, 'terbit');
        }
    }

    public function tampilanWarga($views)
    {
        $hitung = function ($status, $keputusan = '', $kunci = self::META_KEPUTUSAN) {
            $args = [
                'post_type' => 'post',
                'post_status' => $status,
                'fields' => 'ids',
                'posts_per_page' => 1,
                'no_found_rows' => false,
                'meta_query' => [['key' => self::META_NASKAH, 'value' => '1']],
            ];
            if ($keputusan !== '') {
                $args['meta_query'][] = ['key' => $kunci, 'value' => $keputusan];
            }
            $q = new WP_Query($args);
            return (int) $q->found_posts;
        };
        $kini = isset($_GET['ugcd_warga']) ? sanitize_key($_GET['ugcd_warga']) : '';
        $dasar = admin_url('edit.php?post_type=post&ugcd_warga=');
        $angka = get_transient('ugcd_hitung_warga');
        if (!is_array($angka)) {
            $angka = [
                'masuk' => $hitung('pending'),
                'revisi' => $hitung('draft', 'revisi'),
                'ditolak' => $hitung('draft', 'ditolak'),
                'ubah' => $hitung(['publish', 'private'], 'baru', self::META_UBAH_STATUS),
            ];
            set_transient('ugcd_hitung_warga', $angka, 5 * MINUTE_IN_SECONDS);
        }
        $daftar = [
            'masuk' => ['Kiriman masuk', $angka['masuk']],
            'revisi' => ['Perlu revisi', $angka['revisi']],
            'ditolak' => ['Ditolak', $angka['ditolak']],
            'ubah' => ['Permintaan ubah', $angka['ubah']],
        ];
        foreach ($daftar as $kunci => $isi) {
            $views['ugcd_' . $kunci] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($dasar . $kunci),
                $kini === $kunci ? ' class="current" aria-current="page"' : '',
                esc_html($isi[0]),
                $isi[1]
            );
        }
        return $views;
    }

    public function saringWarga($q)
    {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== 'post') {
            return;
        }
        $mode = isset($_GET['ugcd_warga']) ? sanitize_key($_GET['ugcd_warga']) : '';
        if ($mode === '') {
            return;
        }
        $meta = [['key' => self::META_NASKAH, 'value' => '1']];
        if ($mode === 'masuk') {
            $q->set('post_status', 'pending');
        } elseif ($mode === 'revisi' || $mode === 'ditolak') {
            $q->set('post_status', 'draft');
            $meta[] = ['key' => self::META_KEPUTUSAN, 'value' => $mode];
        } elseif ($mode === 'ubah') {
            $q->set('post_status', ['publish', 'private']);
            $meta[] = ['key' => self::META_UBAH_STATUS, 'value' => 'baru'];
        }
        $q->set('meta_query', $meta);
    }

    public function labelWarga($states, $post)
    {
        if ($post->post_type !== 'post' || !get_post_meta($post->ID, self::META_NASKAH, true)) {
            return $states;
        }
        $keputusan = (string) get_post_meta($post->ID, self::META_KEPUTUSAN, true);
        if (get_post_meta($post->ID, self::META_UBAH_STATUS, true) === 'baru') {
            $states['ugcd_warga'] = 'Minta perubahan';
        } elseif ($keputusan === 'ditolak') {
            $states['ugcd_warga'] = 'Ditolak';
        } elseif ($keputusan === 'revisi') {
            $states['ugcd_warga'] = 'Perlu revisi';
        } else {
            $states['ugcd_warga'] = get_post_meta($post->ID, self::META_SUMBER, true) === 'reporter' ? $this->jenisPenulis($post->post_author)['label'] : 'Warga';
        }
        return $states;
    }

    private function hitungBaru()
    {
        $simpan = get_transient('ugcd_laporan_baru');
        if ($simpan !== false) {
            return (int) $simpan;
        }
        $q = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'meta_key' => self::META_STATUS,
            'meta_value' => 'baru',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
        ]);
        $jumlah = (int) $q->found_posts;
        set_transient('ugcd_laporan_baru', $jumlah, 10 * MINUTE_IN_SECONDS);
        return $jumlah;
    }

    public function urutanMenuUtama($urutan)
    {
        if (!is_array($urutan)) {
            return $urutan;
        }
        $i = array_search(self::MENU, $urutan, true);
        if ($i === false) {
            return $urutan;
        }
        unset($urutan[$i]);
        $urutan = array_values($urutan);
        $pos = array_search('edit.php', $urutan, true);
        if ($pos === false) {
            return $urutan;
        }
        array_splice($urutan, $pos + 1, 0, [self::MENU]);
        return $urutan;
    }

    public function urutkanMenu()
    {
        global $submenu;
        if (empty($submenu[self::MENU])) {
            return;
        }
        $urutan = [self::MENU => 0, 'edit.php?post_type=' . self::CPT => 1, 'ugcd-kontributor' => 2];
        $daftar = array_values($submenu[self::MENU]);
        usort($daftar, function ($a, $b) use ($urutan) {
            return ($urutan[$a[2]] ?? 9) <=> ($urutan[$b[2]] ?? 9);
        });
        $submenu[self::MENU] = $daftar;
    }

    public function lencanaMenu()
    {
        global $menu;
        $baru = $this->hitungBaru();
        if (!$baru || !is_array($menu)) {
            return;
        }
        global $submenu;
        $lencana = ' <span class="update-plugins count-' . $baru . '"><span class="plugin-count">' . $baru . '</span></span>';
        foreach ($menu as $i => $item) {
            if (isset($item[2]) && $item[2] === self::MENU) {
                $menu[$i][0] .= $lencana;
                break;
            }
        }
        if (!empty($submenu[self::MENU])) {
            foreach ($submenu[self::MENU] as $i => $item) {
                if (isset($item[2]) && $item[2] === 'edit.php?post_type=' . self::CPT) {
                    $submenu[self::MENU][$i][0] .= $lencana;
                    break;
                }
            }
        }
    }

    public function kolom($kolom)
    {
        return [
            'cb' => $kolom['cb'],
            'title' => 'Artikel',
            'pelapor' => 'Pelapor',
            'pesan' => 'Pesan',
            'status' => 'Status',
            'date' => 'Masuk',
        ];
    }

    public function isiKolom($kolom, $id)
    {
        if ($kolom === 'pelapor') {
            $nama = get_post_meta($id, 'ugcd_laporan_nama', true);
            $email = get_post_meta($id, 'ugcd_laporan_email', true);
            $username = get_post_meta($id, 'ugcd_laporan_username', true);
            echo '<strong>' . esc_html($nama ?: '-') . '</strong>';
            if ($username) {
                echo '<br><a href="' . esc_url($this->situs() . '/author/' . $username . '/') . '" target="_blank" rel="noopener">@' . esc_html($username) . '</a>';
            }
            if ($email) {
                echo '<br><a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
            }
            return;
        }
        if ($kolom === 'pesan') {
            $isi = get_post_field('post_content', $id);
            echo esc_html(wp_trim_words($isi, 24, '…'));
            return;
        }
        if ($kolom === 'status') {
            $status = get_post_meta($id, self::META_STATUS, true) ?: 'baru';
            if ($status === 'baru') {
                echo '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fde68a;color:#78350f;font-weight:600">Baru</span>';
            } else {
                echo '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#d1fae5;color:#065f46;font-weight:600">Ditangani</span>';
            }
        }
    }

    private function tautanStatus($id, $ke)
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=ugcd_laporan_status&id=' . (int) $id . '&ke=' . $ke),
            'ugcd_laporan_' . $id
        );
    }

    public function aksiBaris($aksi, $post)
    {
        if ($post->post_type === 'post' && get_post_meta($post->ID, self::META_UBAH_STATUS, true) === 'baru' && current_user_can('edit_post', $post->ID)) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=ugcd_ubah_selesai&post=' . $post->ID), 'ugcd_ubah_' . $post->ID);
            $aksi['ugcd_ubah'] = '<a href="' . esc_url($url) . '">Permintaan ubah selesai</a>';
            return $aksi;
        }
        if ($post->post_type !== self::CPT) {
            return $aksi;
        }
        unset($aksi['inline hide-if-no-js'], $aksi['view']);
        $artikel = (int) get_post_meta($post->ID, 'ugcd_laporan_post', true);
        $baru = [];
        if ($artikel) {
            $baru['artikel'] = '<a href="' . esc_url(get_permalink($artikel)) . '" target="_blank" rel="noopener">Buka artikel</a>';
            if (current_user_can('edit_post', $artikel)) {
                $baru['sunting_artikel'] = '<a href="' . esc_url(get_edit_post_link($artikel)) . '">Sunting artikel</a>';
            }
        }
        $status = get_post_meta($post->ID, self::META_STATUS, true) ?: 'baru';
        if ($status === 'baru') {
            $baru['tangani'] = '<a href="' . esc_url($this->tautanStatus($post->ID, 'ditangani')) . '">Tandai ditangani</a>';
        } else {
            $baru['buka_lagi'] = '<a href="' . esc_url($this->tautanStatus($post->ID, 'baru')) . '">Tandai baru</a>';
        }
        return array_merge($baru, $aksi);
    }

    public function ubahStatus()
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $ke = isset($_GET['ke']) && $_GET['ke'] === 'baru' ? 'baru' : 'ditangani';
        if (!$id || !current_user_can('edit_post', $id) || !check_admin_referer('ugcd_laporan_' . $id)) {
            wp_die('Tidak diizinkan.');
        }
        update_post_meta($id, self::META_STATUS, $ke);
        $this->segarkanHitungan();
        if ($ke === 'ditangani') {
            update_post_meta($id, 'ugcd_laporan_ditangani_oleh', get_current_user_id());
            update_post_meta($id, 'ugcd_laporan_ditangani_pada', current_time('mysql'));
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=' . self::CPT));
        exit;
    }

    public function aksiMassal($aksi)
    {
        $aksi['ugcd_tangani'] = 'Tandai ditangani';
        return $aksi;
    }

    public function jalankanAksiMassal($url, $aksi, $ids)
    {
        if ($aksi !== 'ugcd_tangani') {
            return $url;
        }
        foreach ($ids as $id) {
            if (current_user_can('edit_post', $id)) {
                update_post_meta($id, self::META_STATUS, 'ditangani');
                update_post_meta($id, 'ugcd_laporan_ditangani_oleh', get_current_user_id());
                update_post_meta($id, 'ugcd_laporan_ditangani_pada', current_time('mysql'));
            }
        }
        $this->segarkanHitungan();
        return add_query_arg('ugcd_ditangani', count($ids), $url);
    }

    public function pemberitahuan()
    {
        if (!empty($_GET['ugcd_ditangani'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . (int) $_GET['ugcd_ditangani'] . ' laporan ditandai ditangani.</p></div>';
        }
    }

    public function tampilanStatus($views)
    {
        $baru = $this->hitungBaru();
        $aktif = isset($_GET['laporan_status']) ? sanitize_key($_GET['laporan_status']) : '';
        $dasar = admin_url('edit.php?post_type=' . self::CPT);
        $views['laporan_baru'] = '<a href="' . esc_url(add_query_arg('laporan_status', 'baru', $dasar)) . '"' . ($aktif === 'baru' ? ' class="current"' : '') . '>Baru <span class="count">(' . $baru . ')</span></a>';
        $views['laporan_ditangani'] = '<a href="' . esc_url(add_query_arg('laporan_status', 'ditangani', $dasar)) . '"' . ($aktif === 'ditangani' ? ' class="current"' : '') . '>Ditangani</a>';
        return $views;
    }

    public function saringStatus($q)
    {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== self::CPT) {
            return;
        }
        $status = isset($_GET['laporan_status']) ? sanitize_key($_GET['laporan_status']) : '';
        if ($status === 'baru' || $status === 'ditangani') {
            $q->set('meta_key', self::META_STATUS);
            $q->set('meta_value', $status);
        }
    }

    public function kotakPelapor()
    {
        add_meta_box('ugcd_laporan_info', 'Detail laporan', [$this, 'isiKotakPelapor'], self::CPT, 'side', 'high');
    }

    public function isiKotakPelapor($post)
    {
        $artikel = (int) get_post_meta($post->ID, 'ugcd_laporan_post', true);
        $baris = [
            'Pelapor' => get_post_meta($post->ID, 'ugcd_laporan_nama', true),
            'Email' => get_post_meta($post->ID, 'ugcd_laporan_email', true),
            'Username' => get_post_meta($post->ID, 'ugcd_laporan_username', true),
            'IP' => get_post_meta($post->ID, 'ugcd_laporan_ip', true),
            'Status' => get_post_meta($post->ID, self::META_STATUS, true) ?: 'baru',
        ];
        echo '<table class="widefat striped" style="border:0"><tbody>';
        foreach ($baris as $label => $nilai) {
            echo '<tr><th style="width:38%;padding:6px 8px">' . esc_html($label) . '</th><td style="padding:6px 8px">' . esc_html($nilai ?: '-') . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($artikel) {
            echo '<p style="margin-top:10px"><a class="button" href="' . esc_url(get_permalink($artikel)) . '" target="_blank" rel="noopener">Buka artikel</a> ';
            if (current_user_can('edit_post', $artikel)) {
                echo '<a class="button" href="' . esc_url(get_edit_post_link($artikel)) . '">Sunting artikel</a>';
            }
            echo '</p>';
        }
        $status = $baris['Status'];
        $ke = $status === 'baru' ? 'ditangani' : 'baru';
        echo '<p><a class="button button-primary" href="' . esc_url($this->tautanStatus($post->ID, $ke)) . '">' . ($ke === 'ditangani' ? 'Tandai ditangani' : 'Tandai baru') . '</a></p>';
    }

    public function menuLaporan()
    {
        add_menu_page(
            'Laporan Tulisan',
            'Laporan Tulisan',
            'edit_others_posts',
            self::MENU,
            [$this, 'halamanKinerja'],
            'dashicons-chart-bar',
            26
        );
        add_submenu_page(self::MENU, 'Kinerja penulis', 'Kinerja penulis', 'edit_others_posts', self::MENU, [$this, 'halamanKinerja']);
    }

    private function rentangDari($dari, $sampai, $jenis = '', $penulis = 0)
    {
        $tanggal = function ($nilai, $bawaan) {
            return is_string($nilai) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) ? $nilai : $bawaan;
        };
        $dari = $tanggal($dari, wp_date('Y-m-01'));
        $sampai = $tanggal($sampai, wp_date('Y-m-d'));
        if ($dari > $sampai) {
            list($dari, $sampai) = [$sampai, $dari];
        }
        $jenis = sanitize_key((string) $jenis);
        return [
            'dari' => $dari,
            'sampai' => $sampai,
            'mulai' => get_gmt_from_date($dari . ' 00:00:00'),
            'akhir' => get_gmt_from_date($sampai . ' 23:59:59'),
            'sumber' => $jenis === 'warga' || in_array($jenis, $this->peranReporter(), true) ? $jenis : '',
            'penulis' => absint($penulis),
        ];
    }

    private function rentang()
    {
        $baca = function ($kunci) {
            return isset($_GET[$kunci]) ? sanitize_text_field(wp_unslash($_GET[$kunci])) : '';
        };
        return $this->rentangDari($baca('dari'), $baca('sampai'), $baca('sumber'), $baca('penulis'));
    }

    private function iso($gmt)
    {
        $t = $gmt ? strtotime((string) $gmt . ' UTC') : false;
        return $t ? wp_date('c', $t) : null;
    }

    public function apiRingkasan($dari = '', $sampai = '', $jenis = '')
    {
        return array_map(function ($b) {
            return [
                'penulis_id' => $b['id'],
                'nama' => $b['nama'],
                'jenis' => $b['jenis'],
                'terakhir_kirim' => $this->iso($b['terakhir']),
                'dikirim' => $b['dikirim'],
                'revisi' => $b['revisi'],
                'ditolak' => $b['ditolak'],
                'terbit' => $b['terbit'],
                'terjadwal' => $b['terjadwal'],
                'jadwal_terdekat' => $b['jadwal'] !== '' ? $this->iso(get_gmt_from_date($b['jadwal'])) : null,
                'menunggu' => $b['menunggu'],
                'persen_terbit' => $b['persen'],
            ];
        }, $this->ambilRingkasan($this->rentangDari($dari, $sampai, $jenis)));
    }

    public function apiRincian($dari = '', $sampai = '', $penulis = 0, $jenis = '', $sertakanOleh = false)
    {
        $r = $this->rentangDari($dari, $sampai, $jenis, $penulis);
        $ids = $r['penulis'] ? [$r['penulis']] : array_column($this->ambilRingkasan($r), 'id');
        return array_map(function ($b) use ($sertakanOleh) {
            $baris = [
                'waktu' => $this->iso($b['waktu']),
                'post_id' => (int) $b['post_id'],
                'judul' => html_entity_decode((string) $b['post_title'], ENT_QUOTES, 'UTF-8'),
                'penulis_id' => (int) $b['penulis_id'],
                'penulis' => $this->namaPengguna($b['penulis_id']),
                'jenis' => $this->jenisPenulis($b['penulis_id'])['label'],
                'kejadian' => $b['kejadian'],
                'label' => $this->labelKejadian($b['kejadian']),
                'alasan' => (string) $b['alasan'],
            ];
            if ($sertakanOleh) {
                $baris['oleh'] = $this->namaPengguna($b['oleh_id']);
            }
            return $baris;
        }, $this->ambilRincian($r, 5000, $ids));
    }

    public function apiJejakTulisan($postId, $sertakanOleh = false)
    {
        global $wpdb;
        $baris = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tabel()} WHERE post_id = %d ORDER BY waktu ASC, id ASC",
            (int) $postId
        ), ARRAY_A);
        return array_map(function ($b) use ($sertakanOleh) {
            $hasil = [
                'waktu' => $this->iso($b['waktu']),
                'kejadian' => $b['kejadian'],
                'label' => $this->labelKejadian($b['kejadian']),
                'alasan' => (string) $b['alasan'],
            ];
            if ($sertakanOleh) {
                $hasil['oleh'] = $this->namaPengguna($b['oleh_id']);
            }
            return $hasil;
        }, $baris);
    }

    private function saringanSql($r, $alias = '')
    {
        global $wpdb;
        $a = $alias !== '' ? $alias . '.' : '';
        $sql = $wpdb->prepare(" AND {$a}waktu BETWEEN %s AND %s", $r['mulai'], $r['akhir']);
        if ($r['sumber'] !== '') {
            $sql .= $wpdb->prepare(" AND {$a}sumber = %s", $r['sumber'] === 'warga' ? 'warga' : 'reporter');
        }
        if (!empty($r['penulis'])) {
            $sql .= $wpdb->prepare(" AND {$a}penulis_id = %d", $r['penulis']);
        }
        return $sql;
    }

    private function namaPengguna($id)
    {
        static $simpan = [];
        $id = (int) $id;
        if (!$id) {
            return '';
        }
        if (!isset($simpan[$id])) {
            $u = get_userdata($id);
            $simpan[$id] = $u ? $u->display_name : 'Pengguna #' . $id;
        }
        return $simpan[$id];
    }

    private function idPenulisRedaksi($peran = '')
    {
        $daftar = $peran !== '' && $peran !== 'warga' ? [$peran] : $this->peranReporter();
        return array_map('intval', get_users(['role__in' => $daftar, 'fields' => 'ID']));
    }

    private function terbitSemua($r)
    {
        global $wpdb;
        $mulai = $r['dari'] . ' 00:00:00';
        $akhir = $r['sampai'] . ' 23:59:59';
        $hasil = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT post_author AS uid, COUNT(*) AS jumlah FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish' AND post_date BETWEEN %s AND %s
            GROUP BY post_author",
            $mulai,
            $akhir
        ), ARRAY_A) as $b) {
            $hasil[(int) $b['uid']] = (int) $b['jumlah'];
        }
        if (!$this->kelasAuthor()) {
            return $hasil;
        }
        $perTerm = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT CAST(tm.meta_value AS UNSIGNED) AS uid, COUNT(DISTINCT p.ID) AS jumlah
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'author'
            INNER JOIN {$wpdb->termmeta} tm ON tm.term_id = tt.term_id AND tm.meta_key = 'user_id'
            WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_date BETWEEN %s AND %s
            GROUP BY uid",
            $mulai,
            $akhir
        ), ARRAY_A) as $b) {
            if ((int) $b['uid']) {
                $perTerm[(int) $b['uid']] = (int) $b['jumlah'];
            }
        }
        return $perTerm + $hasil;
    }

    private function terjadwalSemua()
    {
        global $wpdb;
        $hasil = [];
        $kini = current_time('mysql');
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT post_author AS uid, COUNT(*) AS jumlah, MIN(post_date) AS terdekat FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'future' AND post_date > %s
            GROUP BY post_author",
            $kini
        ), ARRAY_A) as $b) {
            $hasil[(int) $b['uid']] = ['jumlah' => (int) $b['jumlah'], 'terdekat' => (string) $b['terdekat']];
        }
        if (!$this->kelasAuthor()) {
            return $hasil;
        }
        $perTerm = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT CAST(tm.meta_value AS UNSIGNED) AS uid, COUNT(DISTINCT p.ID) AS jumlah, MIN(p.post_date) AS terdekat
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'author'
            INNER JOIN {$wpdb->termmeta} tm ON tm.term_id = tt.term_id AND tm.meta_key = 'user_id'
            WHERE p.post_type = 'post' AND p.post_status = 'future' AND p.post_date > %s
            GROUP BY uid",
            $kini
        ), ARRAY_A) as $b) {
            if ((int) $b['uid']) {
                $perTerm[(int) $b['uid']] = ['jumlah' => (int) $b['jumlah'], 'terdekat' => (string) $b['terdekat']];
            }
        }
        return $perTerm + $hasil;
    }

    private function jamRingan($lokal)
    {
        $t = strtotime((string) $lokal);
        if (!$t) {
            return '';
        }
        $jam = (int) gmdate('G', $t);
        $menit = (int) gmdate('i', $t);
        $bagian = $jam < 4 ? 'malam' : ($jam < 11 ? 'pagi' : ($jam < 15 ? 'siang' : ($jam < 19 ? 'sore' : 'malam')));
        $jam12 = $jam % 12 === 0 ? 12 : $jam % 12;
        $teksJam = $jam12 . ($menit ? '.' . sprintf('%02d', $menit) : '') . ' ' . $bagian;
        $hari = gmdate('Y-m-d', $t);
        $kini = current_time('Y-m-d');
        if ($hari === $kini) {
            return 'Hari ini, ' . $teksJam;
        }
        if ($hari === gmdate('Y-m-d', strtotime($kini . ' +1 day'))) {
            return 'Besok, ' . $teksJam;
        }
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $tahun = gmdate('Y', $t) !== substr($kini, 0, 4) ? ' ' . gmdate('Y', $t) : '';
        return (int) gmdate('j', $t) . ' ' . $bulan[(int) gmdate('n', $t) - 1] . $tahun . ', ' . $teksJam;
    }


    private function terjadwalSitus($uid)
    {
        $q = new WP_Query($this->argPenulis($uid) + [
            'post_type' => 'post',
            'post_status' => 'future',
            'date_query' => [['after' => current_time('mysql'), 'inclusive' => false]],
            'fields' => 'ids',
            'posts_per_page' => 200,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        return array_map('intval', $q->posts);
    }

    private function terbitSitus($uid, $r, $ambil = false)
    {
        $q = new WP_Query($this->argPenulis($uid) + [
            'post_type' => 'post',
            'post_status' => 'publish',
            'date_query' => [['after' => $r['dari'] . ' 00:00:00', 'before' => $r['sampai'] . ' 23:59:59', 'inclusive' => true]],
            'fields' => 'ids',
            'posts_per_page' => $ambil ? 1000 : 1,
            'no_found_rows' => $ambil,
            'ignore_sticky_posts' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        return $ambil ? array_map('intval', $q->posts) : (int) $q->found_posts;
    }

    private function ambilRingkasan($r)
    {
        global $wpdb;
        $t = $this->tabel();
        $saring = $this->saringanSql($r);
        $baris = (array) $wpdb->get_results(
            "SELECT penulis_id, MAX(sumber) AS sumber,
                SUM(kejadian = 'kirim') AS dikirim,
                SUM(kejadian = 'revisi') AS revisi,
                SUM(kejadian = 'tolak') AS ditolak,
                COUNT(DISTINCT CASE WHEN kejadian = 'terbit' THEN post_id END) AS terbit_www,
                MAX(CASE WHEN kejadian IN ('kirim', 'kirim_ulang') THEN waktu END) AS terakhir
            FROM {$t} WHERE 1=1 {$saring} GROUP BY penulis_id",
            OBJECT_K
        );
        $menunggu = (array) $wpdb->get_results(
            "SELECT j.penulis_id, COUNT(DISTINCT j.post_id) AS jumlah
            FROM {$t} j INNER JOIN {$wpdb->posts} p ON p.ID = j.post_id
            WHERE j.kejadian IN ('kirim', 'kirim_ulang') AND p.post_status = 'pending' " . $this->saringanSql($r, 'j') . "
            GROUP BY j.penulis_id",
            OBJECT_K
        );
        $ids = array_map('intval', array_keys($baris));
        if ($r['sumber'] !== 'warga' && empty($r['penulis'])) {
            $ids = array_merge($ids, $this->idPenulisRedaksi($r['sumber']));
        }
        $terbit = $this->terbitSemua($r);
        $terjadwal = $this->terjadwalSemua();
        $hasil = [];
        foreach (array_unique($ids) as $id) {
            $jenis = $this->jenisPenulis($id);
            if ($r['sumber'] !== '' && $jenis['kunci'] !== $r['sumber']) {
                continue;
            }
            $b = $baris[$id] ?? null;
            $dikirim = $b ? (int) $b->dikirim : 0;
            $terbitWww = $b ? (int) $b->terbit_www : 0;
            $hasil[] = [
                'id' => $id,
                'nama' => $this->namaPengguna($id),
                'sumber' => !$b || $b->sumber === 'reporter' ? 'reporter' : 'warga',
                'jenis' => $jenis['label'],
                'terakhir' => $b && $b->terakhir ? (string) $b->terakhir : null,
                'dikirim' => $dikirim,
                'revisi' => $b ? (int) $b->revisi : 0,
                'ditolak' => $b ? (int) $b->ditolak : 0,
                'terbit' => max($terbitWww, $terbit[$id] ?? 0),
                'terjadwal' => $terjadwal[$id]['jumlah'] ?? 0,
                'jadwal' => $terjadwal[$id]['terdekat'] ?? '',
                'menunggu' => isset($menunggu[$id]) ? (int) $menunggu[$id]->jumlah : 0,
                'persen' => $dikirim ? min(100, (int) round($terbitWww / $dikirim * 100)) : null,
            ];
        }
        usort($hasil, function ($a, $b) {
            return [$b['dikirim'], $b['terbit'], $a['nama']] <=> [$a['dikirim'], $a['terbit'], $b['nama']];
        });
        return $hasil;
    }

    private function ambilRincian($r, $batas = 300, $ids = null)
    {
        global $wpdb;
        $t = $this->tabel();
        $baris = (array) $wpdb->get_results(
            "SELECT j.*, p.post_title FROM {$t} j LEFT JOIN {$wpdb->posts} p ON p.ID = j.post_id
            WHERE 1=1 " . $this->saringanSql($r, 'j') . ' ORDER BY j.waktu DESC, j.id DESC LIMIT ' . (int) $batas,
            ARRAY_A
        );
        if ($ids === null) {
            $ids = $r['penulis'] ? [(int) $r['penulis']] : [];
        }
        foreach ($ids as $uid) {
            $sumber = $this->adalahReporter(get_user_by('id', $uid)) ? 'reporter' : 'warga';
            if ($r['sumber'] !== '' && $this->jenisPenulis($uid)['kunci'] !== $r['sumber']) {
                continue;
            }
            foreach ($this->terbitSitus($uid, $r, true) as $postId) {
                if (get_post_meta($postId, self::META_NASKAH, true)) {
                    continue;
                }
                $post = get_post($postId);
                $baris[] = [
                    'post_id' => $postId,
                    'penulis_id' => $uid,
                    'sumber' => $sumber,
                    'kejadian' => 'terbit_cms',
                    'oleh_id' => 0,
                    'alasan' => '',
                    'waktu' => $post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt : get_gmt_from_date($post->post_date),
                    'post_title' => $post->post_title,
                ];
            }
            foreach ($this->terjadwalSitus($uid) as $postId) {
                $post = get_post($postId);
                $baris[] = [
                    'post_id' => $postId,
                    'penulis_id' => $uid,
                    'sumber' => $sumber,
                    'kejadian' => 'terjadwal',
                    'oleh_id' => 0,
                    'alasan' => '',
                    'waktu' => $post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt : get_gmt_from_date($post->post_date),
                    'post_title' => $post->post_title,
                ];
            }
        }
        usort($baris, function ($a, $b) {
            return strcmp((string) $b['waktu'], (string) $a['waktu']);
        });
        return array_slice($baris, 0, (int) $batas);
    }

    private function sorotan($ringkasan)
    {
        $hasil = ['ditolak' => null, 'revisi' => null, 'jarang' => null];
        foreach ($ringkasan as $b) {
            if ($b['ditolak'] > 0 && (!$hasil['ditolak'] || $b['ditolak'] > $hasil['ditolak']['ditolak'])) {
                $hasil['ditolak'] = $b;
            }
            if ($b['revisi'] > 0 && (!$hasil['revisi'] || $b['revisi'] > $hasil['revisi']['revisi'])) {
                $hasil['revisi'] = $b;
            }
            if ($b['dikirim'] >= 3) {
                $rasio = $b['revisi'] / $b['dikirim'];
                $kini = $hasil['jarang'];
                if (!$kini || $rasio < $kini['revisi'] / $kini['dikirim'] || ($rasio === $kini['revisi'] / $kini['dikirim'] && $b['dikirim'] > $kini['dikirim'])) {
                    $hasil['jarang'] = $b;
                }
            }
        }
        return $hasil;
    }

    private function labelKejadian($k)
    {
        $label = [
            'kirim' => 'Dikirim',
            'kirim_ulang' => 'Dikirim ulang',
            'revisi' => 'Perlu revisi',
            'tolak' => 'Ditolak',
            'terbit' => 'Terbit',
            'terbit_cms' => 'Terbit (input redaksi)',
            'terjadwal' => 'Terjadwal tayang',
        ];
        return $label[$k] ?? $k;
    }

    private function labelPeran($slug)
    {
        $nama = wp_roles()->get_names();
        return isset($nama[$slug]) ? translate_user_role($nama[$slug]) : $slug;
    }

    private function jenisPenulis($uid)
    {
        static $simpan = [];
        $uid = (int) $uid;
        if (isset($simpan[$uid])) {
            return $simpan[$uid];
        }
        $user = get_user_by('id', $uid);
        $hasil = ['kunci' => 'warga', 'label' => 'Warga'];
        if ($user && !get_user_meta($uid, self::META_USER, true)) {
            $cocok = array_values(array_intersect($this->peranReporter(), (array) $user->roles));
            if ($cocok) {
                $hasil = ['kunci' => $cocok[0], 'label' => $this->labelPeran($cocok[0])];
            } else {
                $hasil = ['kunci' => 'lain', 'label' => 'Redaksi'];
            }
        }
        $simpan[$uid] = $hasil;
        return $hasil;
    }

    private function waktuLokal($gmt, $format)
    {
        $t = strtotime((string) $gmt . ' UTC');
        return $t ? wp_date($format, $t) : '';
    }

    private function teksRentang($dari, $sampai)
    {
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $f = function ($t, $tahun) use ($bulan) {
            list($y, $m, $d) = array_map('intval', explode('-', $t));
            return $d . ' ' . $bulan[$m - 1] . ($tahun ? ' ' . $y : '');
        };
        if ($dari === $sampai) {
            return $f($dari, true);
        }
        return $f($dari, substr($dari, 0, 4) !== substr($sampai, 0, 4)) . ' – ' . $f($sampai, true);
    }

    public function skripLaporan($hook)
    {
        if ($hook !== 'toplevel_page_' . self::MENU) {
            return;
        }
        wp_enqueue_script('ugcd-laporan', plugins_url('laporan.js', __FILE__), [], self::VERSI, true);
    }

    private function urlLaporan($r, $tambah = [])
    {
        $dasar = [
            'page' => self::MENU,
            'dari' => $r['dari'],
            'sampai' => $r['sampai'],
        ];
        if ($r['sumber'] !== '') {
            $dasar['sumber'] = $r['sumber'];
        }
        return add_query_arg(array_merge($dasar, $tambah), admin_url('admin.php'));
    }

    private function urlUnduh($r, $format)
    {
        $arg = [
            'action' => 'ugcd_laporan_unduh',
            'format' => $format,
            'dari' => $r['dari'],
            'sampai' => $r['sampai'],
        ];
        if ($r['sumber'] !== '') {
            $arg['sumber'] = $r['sumber'];
        }
        if ($r['penulis']) {
            $arg['penulis'] = $r['penulis'];
        }
        return wp_nonce_url(add_query_arg($arg, admin_url('admin-post.php')), 'ugcd_laporan_unduh');
    }

    public function halamanKinerja()
    {
        if (!current_user_can('edit_others_posts')) {
            return;
        }
        $r = $this->rentang();
        $lihatOleh = $this->bolehLihatKeputusan();
        ?>
        <div class="wrap ugcd-laporan">
            <style>
                .ugcd-laporan{max-width:1200px}
                .ugcd-laporan .alat{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:12px 0 16px}
                .ugcd-laporan .alat .button,.ugcd-laporan .alat select{min-height:30px;height:30px;line-height:28px;font-size:13px;padding:0 10px;margin:0}
                .ugcd-laporan .alat select{padding-right:26px}
                .ugcd-laporan .rentang{position:relative}
                .ugcd-laporan .rentang-tombol{display:inline-flex;align-items:center;gap:6px}
                .ugcd-laporan .rentang-tombol .dashicons{font-size:16px;width:16px;height:16px;color:#646970}
                .ugcd-laporan .unduh{margin-left:auto;display:flex;gap:6px;align-items:center;font-size:12px;color:#646970}
                .ugcd-laporan .ringkas{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:0 0 16px}
                .ugcd-laporan .ringkas>div{background:#fff;border:1px solid #dcdcde;padding:10px 12px;min-width:0}
                .ugcd-laporan .ringkas span{display:block;font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em}
                .ugcd-laporan .ringkas strong{display:block;font-size:15px;margin:4px 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                .ugcd-laporan .ringkas em{font-style:normal;font-size:12px;color:#646970}
                .ugcd-laporan .gulir{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #dcdcde;background:#fff}
                .ugcd-laporan .gulir table{border:0;min-width:820px}
                .ugcd-laporan .gulir th,.ugcd-laporan .gulir td{white-space:nowrap;padding:8px 10px;vertical-align:top}
                .ugcd-laporan .gulir td.panjang{white-space:normal;min-width:220px}
                .ugcd-laporan .angka{text-align:right}
                .ugcd-laporan td.jadwal{white-space:nowrap;color:#50575e}
                .ugcd-laporan td.jadwal span{margin-left:4px;color:#8c8f94;font-size:12px}
                .ugcd-laporan .gulir .nomor{width:1%;text-align:right;color:#646970}
                .ugcd-laporan .catatan{color:#646970;font-size:12px;margin:8px 0 0}
                .ugcd-laporan .kembali{display:inline-block;margin:0 0 6px;font-size:13px;text-decoration:none}
                .ugcd-laporan h2{margin:0 0 12px;font-size:16px}
                .ugcd-laporan h2 small{font-weight:400;color:#646970;margin-left:6px}
                .rentang-panel{position:absolute;z-index:100;top:calc(100% + 6px);left:0;display:flex;width:max-content;max-width:calc(100vw - 32px);background:#fff;border:1px solid #c3c4c7;box-shadow:0 8px 24px rgba(0,0,0,.12)}
                .rentang-panel[hidden]{display:none}
                .rentang-preset{display:flex;flex-direction:column;gap:2px;padding:8px;border-right:1px solid #f0f0f1;min-width:104px}
                .rentang-preset button{background:none;border:0;text-align:left;padding:6px 8px;font-size:13px;cursor:pointer;color:#1d2327;border-radius:2px}
                .rentang-preset button:hover{background:#f0f0f1}
                .rentang-kalender{padding:10px 12px;width:252px}
                .rentang-kepala{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;font-size:13px;font-weight:600}
                .rentang-kepala button{background:none;border:0;cursor:pointer;font-size:18px;line-height:1;padding:2px 8px;color:#50575e}
                .rentang-kepala button:disabled{color:#dcdcde;cursor:default}
                .rentang-grid{display:grid;grid-template-columns:repeat(7,1fr);text-align:center}
                .rentang-grid span{font-size:11px;color:#8c8f94;padding:4px 0}
                .rentang-grid button{background:none;border:0;height:32px;font-size:12px;cursor:pointer;color:#1d2327}
                .rentang-grid button:hover{background:#f0f0f1}
                .rentang-grid button.di{background:#f0f6fc}
                .rentang-grid button.ujung{background:#2271b1;color:#fff}
                .rentang-grid button.kini{font-weight:700}
                .rentang-grid button:disabled{color:#c3c4c7;background:none;cursor:default}
                .rentang-kaki{margin-top:8px;font-size:12px;color:#646970}
                @media (max-width:782px){
                    .ugcd-laporan .ringkas{grid-template-columns:repeat(2,minmax(0,1fr))}
                    .ugcd-laporan .unduh{margin-left:0;width:100%}
                    .ugcd-laporan .alat .button,.ugcd-laporan .alat select{min-height:32px;height:32px;line-height:30px}
                    .rentang-panel{flex-direction:column}
                    .rentang-preset{flex-direction:row;flex-wrap:wrap;border-right:0;border-bottom:1px solid #f0f0f1}
                    .rentang-kalender{width:auto}
                }
            </style>
            <h1>Laporan Tulisan</h1>
            <form method="get" class="alat">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU); ?>">
                <?php if ($r['penulis']) : ?>
                    <input type="hidden" name="penulis" value="<?php echo (int) $r['penulis']; ?>">
                <?php endif; ?>
                <div class="rentang" data-hari-ini="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
                    <button type="button" class="button rentang-tombol" aria-haspopup="dialog" aria-expanded="false">
                        <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                        <span class="rentang-teks"><?php echo esc_html($this->teksRentang($r['dari'], $r['sampai'])); ?></span>
                    </button>
                    <input type="hidden" name="dari" value="<?php echo esc_attr($r['dari']); ?>">
                    <input type="hidden" name="sampai" value="<?php echo esc_attr($r['sampai']); ?>">
                </div>
                <select name="sumber" aria-label="Jenis penulis">
                    <option value="">Semua</option>
                    <?php foreach ($this->peranReporter() as $slug) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($r['sumber'], $slug); ?>><?php echo esc_html($this->labelPeran($slug)); ?></option>
                    <?php endforeach; ?>
                    <option value="warga" <?php selected($r['sumber'], 'warga'); ?>>Warga</option>
                </select>
                <noscript><button class="button">Terapkan</button></noscript>
                <span class="unduh">
                    Unduh
                    <a class="button" href="<?php echo esc_url($this->urlUnduh($r, 'xlsx')); ?>">Excel</a>
                    <a class="button" href="<?php echo esc_url($this->urlUnduh($r, 'csv')); ?>">CSV</a>
                </span>
            </form>
            <?php
            if ($r['penulis']) {
                $this->tampilRincian($r, $lihatOleh);
            } else {
                $this->tampilRingkasan($r);
            }
            ?>
        </div>
        <?php
    }

    private function tampilRingkasan($r)
    {
        $ringkasan = $this->ambilRingkasan($r);
        $sorot = $this->sorotan($ringkasan);
        $total = ['dikirim' => 0, 'terbit' => 0, 'terjadwal' => 0, 'menunggu' => 0];
        foreach ($ringkasan as $b) {
            foreach ($total as $k => $v) {
                $total[$k] += $b[$k];
            }
        }
        $kartu = [
            ['Paling banyak ditolak', $sorot['ditolak'], 'ditolak', 'Belum ada'],
            ['Paling banyak revisi', $sorot['revisi'], 'revisi', 'Belum ada'],
            ['Paling jarang revisi', $sorot['jarang'], 'revisi', 'Minimal 3 kiriman'],
        ];
        ?>
        <div class="ringkas">
            <div>
                <span>Dikirim</span>
                <strong><?php echo (int) $total['dikirim']; ?></strong>
                <em><?php echo (int) $total['terbit']; ?> terbit · <?php echo (int) $total['terjadwal']; ?> terjadwal · <?php echo (int) $total['menunggu']; ?> menunggu</em>
            </div>
            <?php foreach ($kartu as $k) : ?>
                <div>
                    <span><?php echo esc_html($k[0]); ?></span>
                    <?php if ($k[1]) : ?>
                        <strong><a href="<?php echo esc_url($this->urlLaporan($r, ['penulis' => $k[1]['id']])); ?>"><?php echo esc_html($k[1]['nama']); ?></a></strong>
                        <em><?php echo (int) $k[1][$k[2]] . ' ' . ($k[2] === 'ditolak' ? 'ditolak' : 'revisi') . ' dari ' . (int) $k[1]['dikirim'] . ' kiriman'; ?></em>
                    <?php else : ?>
                        <strong>–</strong>
                        <em><?php echo esc_html($k[3]); ?></em>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="gulir">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th class="nomor">No.</th>
                        <th>Penulis</th>
                        <th>Jenis</th>
                        <th>Terakhir kirim</th>
                        <th class="angka">Dikirim</th>
                        <th class="angka">Revisi</th>
                        <th class="angka">Ditolak</th>
                        <th class="angka">Terbit</th>
                        <th>Terjadwal</th>
                        <th class="angka">Menunggu</th>
                        <th class="angka">% Terbit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$ringkasan) : ?>
                        <tr><td colspan="11">Belum ada data di rentang ini.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ringkasan as $i => $b) : ?>
                        <tr>
                            <td class="nomor"><?php echo (int) $i + 1; ?></td>
                            <td><a href="<?php echo esc_url($this->urlLaporan($r, ['penulis' => $b['id']])); ?>"><strong><?php echo esc_html($b['nama']); ?></strong></a></td>
                            <td><?php echo esc_html($b['jenis']); ?></td>
                            <td><?php echo $b['terakhir'] ? esc_html($this->waktuLokal($b['terakhir'], 'j M Y H.i')) : '–'; ?></td>
                            <td class="angka"><?php echo (int) $b['dikirim']; ?></td>
                            <td class="angka"><?php echo (int) $b['revisi']; ?></td>
                            <td class="angka"><?php echo (int) $b['ditolak']; ?></td>
                            <td class="angka"><?php echo (int) $b['terbit']; ?></td>
                            <td class="jadwal">
                                <?php if ($b['terjadwal']) : ?>
                                    <?php echo esc_html($this->jamRingan($b['jadwal'])); ?>
                                    <?php if ($b['terjadwal'] > 1) : ?>
                                        <span>+<?php echo (int) $b['terjadwal'] - 1; ?> lagi</span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    –
                                <?php endif; ?>
                            </td>
                            <td class="angka"><?php echo (int) $b['menunggu']; ?></td>
                            <td class="angka"><?php echo $b['persen'] === null ? '–' : (int) $b['persen'] . '%'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="catatan">Dikirim, revisi, dan ditolak dihitung dari naskah yang masuk lewat situs depan, sesuai tanggal naskah dikirim. Terbit menghitung semua artikel yang tayang di rentang ini, termasuk yang diinput redaksi. Terjadwal menampilkan jadwal tayang terdekat dari artikel yang sedang dijadwalkan, tanpa melihat rentang.</p>
        <?php
    }

    private function tampilRincian($r, $lihatOleh)
    {
        $baris = $this->ambilRincian($r, 2000);
        ?>
        <a class="kembali" href="<?php echo esc_url($this->urlLaporan($r)); ?>">&larr; Semua penulis</a>
        <h2><?php echo esc_html($this->namaPengguna($r['penulis'])); ?><small><?php echo esc_html($this->jenisPenulis($r['penulis'])['label']); ?></small></h2>
        <div class="gulir">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th class="nomor">No.</th>
                        <th>Waktu</th>
                        <th>Tulisan</th>
                        <th>Kejadian</th>
                        <th>Alasan</th>
                        <?php if ($lihatOleh) : ?>
                            <th>Oleh</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$baris) : ?>
                        <tr><td colspan="<?php echo $lihatOleh ? 6 : 5; ?>">Belum ada catatan di rentang ini.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($baris as $i => $b) : ?>
                        <?php
                        $sunting = get_edit_post_link((int) $b['post_id']);
                        $judul = html_entity_decode((string) $b['post_title'], ENT_QUOTES, 'UTF-8') ?: 'Tulisan #' . (int) $b['post_id'];
                        ?>
                        <tr>
                            <td class="nomor"><?php echo (int) $i + 1; ?></td>
                            <td><?php echo esc_html($this->waktuLokal($b['waktu'], 'j M Y H.i')); ?></td>
                            <td class="panjang">
                                <?php if ($sunting) : ?>
                                    <a href="<?php echo esc_url($sunting); ?>"><?php echo esc_html($judul); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($judul); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($this->labelKejadian($b['kejadian'])); ?></td>
                            <td class="panjang"><?php echo (string) $b['alasan'] !== '' ? nl2br(esc_html($b['alasan'])) : '–'; ?></td>
                            <?php if ($lihatOleh) : ?>
                                <td><?php echo esc_html($this->namaPengguna($b['oleh_id']) ?: '–'); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function barisUnduh($r, $lihatOleh)
    {
        $data = $this->ambilRingkasan($r);
        $ringkasan = [['No.', 'Penulis', 'Jenis', 'Terakhir kirim', 'Dikirim', 'Revisi', 'Ditolak', 'Terbit', 'Terjadwal', 'Jadwal terdekat', 'Menunggu', '% Terbit']];
        foreach ($data as $i => $b) {
            $ringkasan[] = [
                $i + 1,
                $b['nama'],
                $b['jenis'],
                $b['terakhir'] ? $this->waktuLokal($b['terakhir'], 'Y-m-d H:i') : '',
                $b['dikirim'],
                $b['revisi'],
                $b['ditolak'],
                $b['terbit'],
                $b['terjadwal'],
                $b['jadwal'] !== '' ? substr($b['jadwal'], 0, 16) : '',
                $b['menunggu'],
                $b['persen'] === null ? '' : $b['persen'],
            ];
        }
        $kepala = ['No.', 'Waktu', 'Penulis', 'Jenis', 'Tulisan', 'Kejadian', 'Alasan'];
        if ($lihatOleh) {
            $kepala[] = 'Oleh';
        }
        $kepala[] = 'ID tulisan';
        $rincian = [$kepala];
        $ids = $r['penulis'] ? [(int) $r['penulis']] : array_column($data, 'id');
        foreach ($this->ambilRincian($r, 20000, $ids) as $i => $b) {
            $baris = [
                $i + 1,
                $this->waktuLokal($b['waktu'], 'Y-m-d H:i'),
                $this->namaPengguna($b['penulis_id']),
                $this->jenisPenulis($b['penulis_id'])['label'],
                html_entity_decode((string) $b['post_title'], ENT_QUOTES, 'UTF-8'),
                $this->labelKejadian($b['kejadian']),
                (string) $b['alasan'],
            ];
            if ($lihatOleh) {
                $baris[] = $this->namaPengguna($b['oleh_id']);
            }
            $baris[] = (int) $b['post_id'];
            $rincian[] = $baris;
        }
        return ['Ringkasan' => $ringkasan, 'Rincian' => $rincian];
    }

    public function unduhLaporan()
    {
        if (!current_user_can('edit_others_posts')) {
            wp_die('Tidak diizinkan.');
        }
        check_admin_referer('ugcd_laporan_unduh');
        $r = $this->rentang();
        $format = isset($_GET['format']) && $_GET['format'] === 'xlsx' ? 'xlsx' : 'csv';
        $lembar = $this->barisUnduh($r, $this->bolehLihatKeputusan());
        if ($r['penulis']) {
            unset($lembar['Ringkasan']);
        }
        $nama = 'laporan-tulisan-' . $r['dari'] . '-' . $r['sampai'];
        if ($r['penulis']) {
            $nama .= '-' . sanitize_title($this->namaPengguna($r['penulis']));
        }
        if ($format === 'xlsx' && class_exists('ZipArchive')) {
            $berkas = $this->buatXlsx($lembar);
            if ($berkas) {
                nocache_headers();
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $nama . '.xlsx"');
                header('Content-Length: ' . filesize($berkas));
                readfile($berkas);
                @unlink($berkas);
                exit;
            }
        }
        $data = $r['penulis'] ? $lembar['Rincian'] : $lembar['Ringkasan'];
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nama . '.csv"');
        $keluar = fopen('php://output', 'w');
        fwrite($keluar, "\xEF\xBB\xBF");
        foreach ($data as $baris) {
            fputcsv($keluar, array_map(function ($v) {
                return $this->amanCsv($v);
            }, $baris), ',', '"', '\\');
        }
        fclose($keluar);
        exit;
    }

    private function amanCsv($nilai)
    {
        if (is_string($nilai) && $nilai !== '' && strpbrk($nilai[0], '=+-@') !== false) {
            return "'" . $nilai;
        }
        return $nilai;
    }

    private function kolomXlsx($i)
    {
        $nama = '';
        $i++;
        while ($i > 0) {
            $sisa = ($i - 1) % 26;
            $nama = chr(65 + $sisa) . $nama;
            $i = (int) (($i - $sisa - 1) / 26);
        }
        return $nama;
    }

    private function teksXml($nilai)
    {
        $nilai = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $nilai);
        return htmlspecialchars((string) $nilai, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function buatXlsx($lembar)
    {
        $berkas = wp_tempnam('laporan-tulisan');
        if (!$berkas) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($berkas, ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $nama = array_keys($lembar);
        $tipe = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $buku = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $relasi = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($nama as $i => $judul) {
            $n = $i + 1;
            $tipe .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $buku .= '<sheet name="' . $this->teksXml($judul) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $relasi .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $isi = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
            foreach ($lembar[$judul] as $r => $baris) {
                $isi .= '<row r="' . ($r + 1) . '">';
                foreach (array_values($baris) as $k => $sel) {
                    $ref = $this->kolomXlsx($k) . ($r + 1);
                    $gaya = $r === 0 ? ' s="1"' : '';
                    if ($r > 0 && (is_int($sel) || is_float($sel))) {
                        $isi .= '<c r="' . $ref . '"><v>' . $sel . '</v></c>';
                    } else {
                        $isi .= '<c r="' . $ref . '" t="inlineStr"' . $gaya . '><is><t xml:space="preserve">' . $this->teksXml($sel) . '</t></is></c>';
                    }
                }
                $isi .= '</row>';
            }
            $isi .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $isi);
        }
        $tipe .= '</Types>';
        $buku .= '</sheets></workbook>';
        $relasi .= '<Relationship Id="rId' . (count($nama) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('[Content_Types].xml', $tipe);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $buku);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $relasi);
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();
        return $berkas;
    }

    public function menuPengaturan()
    {
        add_submenu_page(
            self::MENU,
            'Pengaturan WP UGC Desk',
            'Pengaturan',
            'manage_options',
            'ugcd-kontributor',
            [$this, 'halamanPengaturan']
        );
    }

    public function daftarPengaturan()
    {
        register_setting('ugcd_kontributor', self::OPSI_RAHASIA, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ugcd_kontributor', self::OPSI_EMAIL, ['sanitize_callback' => 'sanitize_email']);
        register_setting('ugcd_kontributor', self::OPSI_SITUS, ['sanitize_callback' => 'esc_url_raw']);
        register_setting('ugcd_kontributor', self::OPSI_REPORTER, [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => function ($nilai) {
                return is_array($nilai) ? array_values(array_unique(array_filter(array_map('sanitize_key', $nilai)))) : [];
            },
        ]);
        register_setting('ugcd_kontributor', self::OPSI_LIHAT, [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => function ($nilai) {
                return is_array($nilai) ? array_values(array_unique(array_filter(array_map('intval', $nilai)))) : [];
            },
        ]);
    }

    public function halamanPengaturan()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $env = getenv('UGC_DESK_SECRET');
        $kolumnis = $this->idKategoriKolumnis();
        ?>
        <div class="wrap">
            <h1>WP UGC Desk</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ugcd_kontributor'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ugcd_akun_secret">Kunci</label></th>
                        <td>
                            <?php if ($env) : ?>
                                <code>UGC_DESK_SECRET</code> dari server
                            <?php else : ?>
                                <input type="password" id="ugcd_akun_secret" name="<?php echo esc_attr(self::OPSI_RAHASIA); ?>" value="<?php echo esc_attr(get_option(self::OPSI_RAHASIA, '')); ?>" class="regular-text" autocomplete="off">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ugcd_laporan_email">Email redaksi</label></th>
                        <td>
                            <input type="email" id="ugcd_laporan_email" name="<?php echo esc_attr(self::OPSI_EMAIL); ?>" value="<?php echo esc_attr(get_option(self::OPSI_EMAIL, '')); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ugcd_situs">Alamat situs</label></th>
                        <td>
                            <input type="url" id="ugcd_situs" name="<?php echo esc_attr(self::OPSI_SITUS); ?>" value="<?php echo esc_attr(get_option(self::OPSI_SITUS, '')); ?>" class="regular-text" placeholder="https://www.contoh.com">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Lihat pemberi keputusan</th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::OPSI_LIHAT); ?>[]" value="0">
                            <?php
                            $pilihan = $this->daftarPelihat();
                            $redaksi = get_users(['role__in' => ['editor', 'author'], 'orderby' => 'display_name', 'fields' => ['ID', 'display_name']]);
                            foreach ($redaksi as $u) :
                                ?>
                                <label style="display:block;margin:0 0 6px">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPSI_LIHAT); ?>[]" value="<?php echo (int) $u->ID; ?>" <?php checked(in_array((int) $u->ID, $pilihan, true)); ?>>
                                    <?php echo esc_html($u->display_name); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Administrator selalu bisa melihat.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Peran penulis tambahan</th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::OPSI_REPORTER); ?>[]" value="">
                            <?php
                            $dipilih = $this->peranTambahan();
                            $semuaPeran = array_diff_key(wp_roles()->get_names(), array_flip(self::PERAN_INTI));
                            foreach ($semuaPeran as $slug => $label) :
                                ?>
                                <label style="display:block;margin:0 0 6px">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPSI_REPORTER); ?>[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $dipilih, true)); ?>>
                                    <?php echo esc_html(translate_user_role($label)); ?>
                                </label>
                            <?php endforeach; ?>
                            <?php if (!$semuaPeran) : ?>
                                <p class="description">Belum ada peran khusus.</p>
                            <?php endif; ?>
                            <p class="description">Penulis (Author) selalu menulis lewat situs depan. Centang peran lain yang juga menulis lewat sana.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Kategori Kolumnis</th>
                        <td><?php echo $kolumnis ? 'Ada' : '<span style="color:#b32d2e">Belum ada</span>'; ?></td>
                    </tr>
                </table>
                <?php submit_button('Simpan'); ?>
            </form>
        </div>
        <?php
    }
}

UGC_Desk::mulai();

function ugcd_laporan_ringkasan($dari = '', $sampai = '', $jenis = '')
{
    return UGC_Desk::mulai()->apiRingkasan($dari, $sampai, $jenis);
}

function ugcd_laporan_rincian($dari = '', $sampai = '', $penulis = 0, $jenis = '', $sertakanOleh = false)
{
    return UGC_Desk::mulai()->apiRincian($dari, $sampai, $penulis, $jenis, $sertakanOleh);
}

function ugcd_jejak_tulisan($postId, $sertakanOleh = false)
{
    return UGC_Desk::mulai()->apiJejakTulisan($postId, $sertakanOleh);
}

final class UGC_Desk_Push
{
    const VERSI = '1.0.0';
    const META = 'ugcd_push';
    const META_TERKIRIM = 'ugcd_push_terkirim';
    const META_GAGAL = 'ugcd_push_gagal';
    const FIELD = 'ugcd_push_info';

    private static $instance;

    public static function mulai()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'daftarMeta']);
        add_action('rest_api_init', [$this, 'daftarField']);
        add_action('enqueue_block_editor_assets', [$this, 'skripEditor']);
        add_action('wp_after_insert_post', [$this, 'setelahSimpan'], 20, 4);
    }

    private function rahasia()
    {
        $env = getenv('UGC_DESK_SECRET');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return (string) get_option('ugcd_akun_secret', '');
    }

    private function situs()
    {
        $situs = untrailingslashit((string) get_option('ugcd_situs', ''));
        return $situs !== '' ? $situs : untrailingslashit(home_url());
    }

    public function daftarMeta()
    {
        register_post_meta('post', self::META, [
            'type' => 'boolean',
            'single' => true,
            'default' => false,
            'show_in_rest' => true,
            'auth_callback' => function ($boleh, $kunci, $postId) {
                return current_user_can('edit_post', $postId);
            },
        ]);
    }

    public function daftarField()
    {
        register_rest_field('post', self::FIELD, [
            'get_callback' => function ($data) {
                $id = (int) $data['id'];
                $terkirim = json_decode((string) get_post_meta($id, self::META_TERKIRIM, true), true);
                return [
                    'terkirim' => is_array($terkirim) ? $terkirim : null,
                    'gagal' => (string) get_post_meta($id, self::META_GAGAL, true),
                ];
            },
            'schema' => [
                'type' => 'object',
                'context' => ['edit'],
                'readonly' => true,
            ],
        ]);
    }

    public function skripEditor()
    {
        $layar = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$layar || $layar->post_type !== 'post' || !current_user_can('edit_others_posts')) {
            return;
        }
        wp_enqueue_script(
            'ugcd-notifikasi',
            plugins_url('notifikasi.js', __FILE__),
            ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data'],
            self::VERSI,
            true
        );
    }

    public function setelahSimpan($postId, $post, $update, $sebelum)
    {
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if (!get_post_meta($postId, self::META, true) || get_post_meta($postId, self::META_TERKIRIM, true)) {
            return;
        }
        if (!current_user_can('edit_others_posts') && !wp_doing_cron()) {
            return;
        }
        $kunci = 'ugcd_push_' . $postId;
        if (get_transient($kunci)) {
            return;
        }
        set_transient($kunci, 1, 60);
        $this->kirim($post);
        delete_transient($kunci);
    }

    private function teks($html)
    {
        $teks = html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $teks));
    }

    private function ringkasan($post)
    {
        if (has_excerpt($post)) {
            return $this->teks($post->post_excerpt);
        }
        return $this->teks(wp_trim_words(strip_shortcodes($post->post_content), 30, '…'));
    }

    private function tautan($post)
    {
        $permalink = get_permalink($post);
        return $permalink ? str_replace(untrailingslashit(home_url()), $this->situs(), $permalink) : $this->situs() . '/';
    }

    private function gambar($post)
    {
        $url = get_the_post_thumbnail_url($post, 'medium_large');
        return is_string($url) && strpos($url, 'https://') === 0 ? $url : null;
    }

    private function kategori($post)
    {
        $ids = [];
        foreach (wp_get_post_categories($post->ID) as $id) {
            $ids[] = (int) $id;
            foreach (get_ancestors($id, 'category', 'taxonomy') as $induk) {
                $ids[] = (int) $induk;
            }
        }
        return array_values(array_unique($ids));
    }

    private function gagal($postId, $pesan)
    {
        update_post_meta($postId, self::META_GAGAL, $pesan);
    }

    private function kirim($post)
    {
        $rahasia = $this->rahasia();
        if ($rahasia === '') {
            $this->gagal($post->ID, 'Kunci belum diisi di pengaturan WP UGC Desk.');
            return;
        }
        $body = [
            'post_id' => (int) $post->ID,
            'judul' => $this->teks($post->post_title),
            'ringkasan' => $this->ringkasan($post),
            'url' => $this->tautan($post),
            'gambar' => $this->gambar($post),
            'kategori' => $this->kategori($post),
        ];
        $res = wp_remote_post($this->situs() . '/api/push/kirim/', [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-UGC-Desk-Secret' => $rahasia,
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($res)) {
            $this->gagal($post->ID, 'Gagal menghubungi situs depan.');
            return;
        }
        $kode = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($kode === 200 || $kode === 409) {
            $pengguna = wp_get_current_user();
            update_post_meta($post->ID, self::META_TERKIRIM, wp_json_encode([
                'waktu' => wp_date('j M Y H.i'),
                'target' => is_array($data) && isset($data['target']) ? (int) $data['target'] : 0,
                'oleh' => $pengguna && $pengguna->exists() ? $pengguna->display_name : '',
            ]));
            delete_post_meta($post->ID, self::META_GAGAL);
            return;
        }
        $pesan = is_array($data) && !empty($data['pesan']) ? (string) $data['pesan'] : 'Gagal mengirim (kode ' . $kode . ').';
        $this->gagal($post->ID, $pesan);
    }
}

UGC_Desk_Push::mulai();
