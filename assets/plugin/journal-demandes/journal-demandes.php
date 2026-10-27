<?php
/**
 * Plugin Name: Journal des demandes
 * Description: Relie chaque demande de formulaire enregistrée à sa page d’arrivée, son canal, sa campagne et son suivi commercial.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ForgeOne
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }

final class JD_Journal {
    const VERSION = '1.0.0';
    const CPT = 'jd_demande';
    const FIELD = 'jd_prov';
    const CHANNELS = array('Direct ou inconnu','Google Ads','Instagram payant','Instagram','Meta payant','Facebook','LinkedIn payant','LinkedIn','Recherche payante','SEO','Campagne renseignée','Email','Campagne payante','Assistant IA','Site référent');
    const STAGES = array('Nouveau','À qualifier','Qualifié','Rendez vous','Devis envoyé','Signé','Sans suite','Contrôle');
    const RECEIVED = array('Non vérifiée','Confirmée','Non reçue');
    const ADAPTERS = array(
        'cf7' => array('Contact Form 7', 'wpcf7_submit', 'on_cf7', 2),
        'wpforms' => array('WPForms', 'wpforms_process_complete', 'on_wpforms', 4),
        'gravity' => array('Gravity Forms', 'gform_after_submission', 'on_gravity', 2),
        'elementor' => array('Elementor Pro', 'elementor_pro/forms/new_record', 'on_elementor', 2),
        'fluent' => array('Fluent Forms', 'fluentform/submission_inserted', 'on_fluent', 3),
        'formidable' => array('Formidable Forms', 'frm_after_create_entry', 'on_formidable', 2),
        'ninja' => array('Ninja Forms', 'ninja_forms_after_submission', 'on_ninja', 1),
    );
    private static $cfg = array();
    private static $created = array();
    private static $mail = null;

    public static function boot() {
        $file = __DIR__ . '/config.php';
        $cfg = file_exists($file) ? include $file : array();
        self::$cfg = is_array($cfg) ? $cfg : array();
        add_action('init', array(__CLASS__, 'register'));
        add_filter('wp_consent_api_registered_' . plugin_basename(__FILE__), '__return_true');
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 100);
        foreach (self::adapters() as $key) {
            $a = self::ADAPTERS[$key];
            add_action($a[1], array(__CLASS__, $a[2]), 20, $a[3]);
        }
        foreach (self::post_types() as $type => $label) {
            add_action('save_post_' . $type, function ($id, $post, $update) use ($label) { self::on_post($id, $post, $update, $label); }, 20, 3);
        }
        // Raccordement d’un formulaire codé sur mesure : do_action('journal_demandes_record', array('form' => 'Quiz', 'values' => $donnees));
        add_action('journal_demandes_record', array(__CLASS__, 'record'), 10, 1);
        add_action('wp_mail_succeeded', array(__CLASS__, 'mail_ok'));
        add_action('wp_mail_failed', array(__CLASS__, 'mail_failed'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_jd_journal_update', array(__CLASS__, 'update'));
        add_action('admin_post_jd_journal_export', array(__CLASS__, 'export'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('rest_api_init', array(__CLASS__, 'rest'));
    }

    // Lecture seule réservée aux comptes autorisés, pour contrôler la recette. Aucune collecte publique.
    public static function rest() {
        register_rest_route('journal-demandes/v1', '/demandes', array(
            'methods' => 'GET',
            'permission_callback' => function () { return current_user_can(self::capability()); },
            'callback' => function ($request) {
                $limit = min(50, max(1, absint($request->get_param('limit') ?: 10)));
                $rows = array();
                foreach (self::query(1, $limit)->posts as $p) {
                    $d = get_post_meta($p->ID, '_jd', true);
                    $rows[] = array('id' => $p->ID, 'date' => get_the_date('c', $p)) + (is_array($d) ? $d : array());
                }
                return array('version' => self::VERSION, 'demandes' => $rows);
            },
        ));
    }

    private static function adapters() {
        $list = isset(self::$cfg['adapters']) && is_array(self::$cfg['adapters']) ? self::$cfg['adapters'] : array();
        return array_values(array_intersect($list, array_keys(self::ADAPTERS)));
    }
    private static function post_types() {
        $types = isset(self::$cfg['post_types']) && is_array(self::$cfg['post_types']) ? self::$cfg['post_types'] : array();
        unset($types[self::CPT]);
        return $types;
    }
    private static function capability() {
        return !empty(self::$cfg['capability']) ? (string) self::$cfg['capability'] : 'manage_options';
    }

    public static function register() {
        register_post_type(self::CPT, array('label' => 'Journal des demandes', 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'exclude_from_search' => true, 'supports' => array('title'), 'capability_type' => 'post', 'map_meta_cap' => true));
    }

    public static function assets() {
        if (is_admin() || (is_user_logged_in() && empty(self::$cfg['track_logged_in']))) { return; }
        $consent = isset(self::$cfg['consent']) && is_array(self::$cfg['consent']) ? self::$cfg['consent'] : array('mode' => 'wp_consent_api');
        $deps = wp_script_is('wp-consent-api', 'registered') ? array('wp-consent-api') : array();
        wp_enqueue_script('jd-journal', plugins_url('assets/attribution.js', __FILE__), $deps, self::VERSION, true);
        wp_add_inline_script('jd-journal', 'window.JDJournal=' . wp_json_encode(array('consent' => $consent)) . ';', 'before');
    }

    private static function cut($value, $length) {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
    private static function short($value, $length = 160) {
        return is_scalar($value) ? self::cut(sanitize_text_field((string) $value), $length) : '';
    }
    private static function path($value) {
        $value = is_scalar($value) ? (string) $value : '';
        if (substr($value, 0, 1) !== '/' || substr($value, 0, 2) === '//') { return ''; }
        return self::cut((string) strtok($value, '?#'), 400);
    }
    private static function touch($value) {
        if (!is_array($value)) { return array(); }
        $out = array();
        foreach (array('source','medium','campaign','content','evidence','at') as $key) { $out[$key] = self::short($value[$key] ?? ''); }
        $channel = self::short($value['channel'] ?? '');
        $out['channel'] = in_array($channel, self::CHANNELS, true) ? $channel : 'Direct ou inconnu';
        $out['landing'] = self::path($value['landing'] ?? '');
        $out['referrer'] = preg_replace('/[^a-z0-9.\-]/i', '', self::short($value['referrer'] ?? ''));
        return $out;
    }
    private static function decode($raw) {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 12000) { return array(); }
        $data = json_decode($raw, true);
        if (!is_array($data)) { return array(); }
        return array(
            'first' => self::touch($data['first'] ?? array()),
            'last' => self::touch($data['last'] ?? array()),
            'conversion' => self::path($data['conversion'] ?? ''),
            'unavailable_reason' => self::short($data['unavailable_reason'] ?? ''),
            'request_id' => preg_replace('/[^a-zA-Z0-9\-]/', '', self::short($data['request_id'] ?? '', 80)),
        );
    }

    // Ordre de confiance : valeur transmise par l’adaptateur, champ caché du formulaire, cookie de session.
    private static function context($explicit) {
        $candidates = array();
        if (is_string($explicit)) { $candidates[] = array($explicit, 'Champ du formulaire'); }
        if (isset($_POST[self::FIELD]) && is_string($_POST[self::FIELD])) { $candidates[] = array(wp_unslash($_POST[self::FIELD]), 'Champ du formulaire'); }
        if (isset($_COOKIE[self::FIELD]) && is_string($_COOKIE[self::FIELD])) { $candidates[] = array(wp_unslash($_COOKIE[self::FIELD]), 'Cookie de session'); }
        foreach ($candidates as $c) {
            $d = self::decode($c[0]);
            if ($d) { $d['transport'] = $c[1]; return $d; }
        }
        return array('first' => array(), 'last' => array(), 'conversion' => '', 'request_id' => '', 'transport' => 'Aucun', 'unavailable_reason' => 'Mesure non autorisée, non disponible ou script absent');
    }

    private static function flatten($values, &$out, $depth = 0) {
        if ($depth > 4 || count($out) > 300) { return; }
        if (is_object($values)) { $values = get_object_vars($values); }
        if (is_array($values)) { foreach ($values as $v) { self::flatten($v, $out, $depth + 1); } return; }
        if (is_scalar($values) && $values !== '') { $out[] = (string) $values; }
    }
    private static function find_email($values) {
        $flat = array(); self::flatten($values, $flat);
        foreach ($flat as $v) { $v = trim($v); if (strlen($v) <= 190 && is_email($v)) { return sanitize_email($v); } }
        return '';
    }
    private static function looks_test($values) {
        $flat = array(); self::flatten($values, $flat);
        foreach ($flat as $v) { if (preg_match('/\bTEST\b/', $v)) { return true; } }
        return false;
    }

    private static function duplicate_of($request_id, $contact, $form) {
        $base = array('post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true);
        if ($request_id !== '') {
            $found = get_posts($base + array('meta_query' => array(array('key' => '_jd_request_id', 'value' => $request_id))));
            if ($found) { return (int) $found[0]; }
        }
        if ($contact !== '') {
            $found = get_posts($base + array('date_query' => array(array('after' => '10 minutes ago')), 'meta_query' => array(array('key' => '_jd_contact', 'value' => strtolower($contact)), array('key' => '_jd_form', 'value' => $form))));
            if ($found) { return (int) $found[0]; }
        }
        return 0;
    }

    // Point d’entrée unique : chaque adaptateur l’appelle après l’acceptation de la demande par le serveur.
    public static function record($args) {
        $args = is_array($args) ? $args : array();
        $values = $args['values'] ?? array();
        $form = self::short($args['form'] ?? 'Formulaire') ?: 'Formulaire';
        $ctx = self::context($args['prov'] ?? null);
        $contact = self::short($args['contact'] ?? self::find_email($values), 190);
        $test = is_user_logged_in() || self::looks_test($values);
        $dup = self::duplicate_of($ctx['request_id'], $contact, $form);
        $id = wp_insert_post(array('post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $form . ' ' . current_time('d/m/Y H:i')), true);
        if (is_wp_error($id) || !$id) { return 0; }
        $d = array(
            'form' => $form,
            'tool' => self::short($args['tool'] ?? ''),
            'ref' => self::short($args['ref'] ?? '', 200),
            'ref_url' => esc_url_raw($args['ref_url'] ?? ''),
            'contact' => $contact,
            'first' => $ctx['first'],
            'last' => $ctx['last'],
            'conversion' => $ctx['conversion'],
            'request_id' => $ctx['request_id'],
            'transport' => $ctx['transport'],
            'unavailable_reason' => $ctx['unavailable_reason'],
            'quality' => empty($ctx['first']['landing']) ? 'Provenance absente' : 'Provenance navigateur, non certifiée',
            'duplicate' => $dup ? 'Doublon possible de la demande ' . $dup : '',
            'stage' => $test ? 'Contrôle' : 'Nouveau',
            'mail' => self::$mail !== null ? self::$mail : 'Envoi non observé',
            'received' => 'Non vérifiée',
            'declared_source' => '',
            'note' => $test ? 'Essai identifié automatiquement' : '',
            // Le serveur fait foi pour la date et l’existence de la demande, pas pour la provenance déclarée par le navigateur.
            'recorded_at' => current_time('mysql'),
        );
        update_post_meta($id, '_jd', $d);
        update_post_meta($id, '_jd_channel', $d['first']['channel'] ?? 'Direct ou inconnu');
        update_post_meta($id, '_jd_request_id', $d['request_id']);
        update_post_meta($id, '_jd_contact', strtolower($contact));
        update_post_meta($id, '_jd_form', $form);
        self::$created[] = $id;
        return $id;
    }

    public static function on_cf7($contact_form, $result) {
        $status = is_array($result) ? ($result['status'] ?? '') : '';
        if (!in_array($status, array('mail_sent', 'mail_failed'), true)) { return; }
        $submission = class_exists('WPCF7_Submission') ? WPCF7_Submission::get_instance() : null;
        $values = $submission ? $submission->get_posted_data() : array();
        $title = is_object($contact_form) && method_exists($contact_form, 'title') ? $contact_form->title() : 'Contact Form 7';
        $fid = is_object($contact_form) && method_exists($contact_form, 'id') ? $contact_form->id() : 0;
        self::record(array('form' => $title, 'tool' => 'Contact Form 7', 'ref' => 'Formulaire ' . $fid, 'values' => $values));
    }
    public static function on_wpforms($fields, $entry, $form_data, $entry_id) {
        $values = is_array($fields) ? wp_list_pluck($fields, 'value') : array();
        $title = $form_data['settings']['form_title'] ?? 'WPForms';
        $ref = $entry_id ? 'Entrée ' . absint($entry_id) : 'Formulaire ' . absint($form_data['id'] ?? 0);
        $url = $entry_id ? admin_url('admin.php?page=wpforms-entries&view=details&entry_id=' . absint($entry_id)) : '';
        self::record(array('form' => $title, 'tool' => 'WPForms', 'ref' => $ref, 'ref_url' => $url, 'values' => $values));
    }
    public static function on_gravity($entry, $form) {
        if (!is_array($entry) || (($entry['status'] ?? '') === 'spam')) { return; }
        $eid = absint($entry['id'] ?? 0); $fid = absint($form['id'] ?? 0);
        $url = $eid ? admin_url('admin.php?page=gf_entries&view=entry&id=' . $fid . '&lid=' . $eid) : '';
        self::record(array('form' => $form['title'] ?? 'Gravity Forms', 'tool' => 'Gravity Forms', 'ref' => 'Entrée ' . $eid, 'ref_url' => $url, 'values' => $entry));
    }
    public static function on_elementor($record, $handler) {
        if (!is_object($record) || !method_exists($record, 'get')) { return; }
        $fields = $record->get('fields');
        $values = is_array($fields) ? wp_list_pluck($fields, 'value') : array();
        $title = method_exists($record, 'get_form_settings') ? $record->get_form_settings('form_name') : 'Elementor';
        self::record(array('form' => $title ?: 'Elementor', 'tool' => 'Elementor Pro', 'values' => $values));
    }
    public static function on_fluent($insert_id, $form_data, $form) {
        $prov = is_array($form_data) && isset($form_data[self::FIELD]) && is_string($form_data[self::FIELD]) ? wp_unslash($form_data[self::FIELD]) : null;
        $title = is_object($form) && isset($form->title) ? $form->title : 'Fluent Forms';
        self::record(array('form' => $title, 'tool' => 'Fluent Forms', 'ref' => 'Entrée ' . absint($insert_id), 'values' => $form_data, 'prov' => $prov));
    }
    public static function on_formidable($entry_id, $form_id) {
        $title = 'Formidable';
        if (class_exists('FrmForm')) { $f = FrmForm::getOne($form_id); if (is_object($f) && !empty($f->name)) { $title = $f->name; } }
        $values = isset($_POST['item_meta']) && is_array($_POST['item_meta']) ? wp_unslash($_POST['item_meta']) : array();
        self::record(array('form' => $title, 'tool' => 'Formidable Forms', 'ref' => 'Entrée ' . absint($entry_id), 'values' => $values));
    }
    public static function on_ninja($form_data) {
        $fields = is_array($form_data) && isset($form_data['fields']) && is_array($form_data['fields']) ? $form_data['fields'] : array();
        $values = array();
        foreach ($fields as $f) { if (is_array($f) && isset($f['value'])) { $values[] = $f['value']; } }
        $title = $form_data['settings']['title'] ?? 'Ninja Forms';
        self::record(array('form' => $title, 'tool' => 'Ninja Forms', 'values' => $values));
    }
    private static function on_post($id, $post, $update, $label) {
        if ($update || wp_is_post_revision($id) || wp_is_post_autosave($id) || (is_object($post) && $post->post_status === 'auto-draft')) { return; }
        $values = array(is_object($post) ? $post->post_title : '', get_post_meta($id));
        self::record(array('form' => $label, 'tool' => 'Stockage existant', 'ref' => 'Demande ' . absint($id), 'ref_url' => admin_url('post.php?post=' . absint($id) . '&action=edit'), 'values' => $values));
    }

    private static function targets_business($data) {
        $target = isset(self::$cfg['notification_email']) ? strtolower(trim((string) self::$cfg['notification_email'])) : '';
        if ($target === '') { return true; }
        $to = is_array($data) ? ($data['to'] ?? array()) : array();
        if (!is_array($to)) { $to = explode(',', (string) $to); }
        foreach ($to as $r) { if (strpos(strtolower((string) $r), $target) !== false) { return true; } }
        return false;
    }
    private static function mark_mail($data, $status) {
        if (!self::targets_business($data)) { return; }
        self::$mail = $status;
        // Une seule demande créée dans la requête : sinon l’email ne peut pas être attribué avec certitude.
        if (count(self::$created) !== 1) { return; }
        $id = self::$created[0]; $d = get_post_meta($id, '_jd', true);
        if (!is_array($d)) { return; }
        $d['mail'] = $status; update_post_meta($id, '_jd', $d);
    }
    public static function mail_ok($data) { self::mark_mail($data, 'Accepté par le transport'); }
    public static function mail_failed($error) {
        $data = is_object($error) && method_exists($error, 'get_error_data') ? $error->get_error_data() : array();
        self::mark_mail(is_array($data) ? $data : array(), 'Échec technique observé');
    }

    public static function menu() {
        add_menu_page('Journal des demandes', 'Journal des demandes', self::capability(), 'jd-journal', array(__CLASS__, 'render'), 'dashicons-chart-line', 26);
    }
    public static function notice() {
        if (!current_user_can(self::capability())) { return; }
        $checks = array('cf7' => class_exists('WPCF7'), 'wpforms' => function_exists('wpforms'), 'gravity' => class_exists('GFForms'), 'elementor' => defined('ELEMENTOR_PRO_VERSION'), 'fluent' => defined('FLUENTFORM'), 'formidable' => class_exists('FrmForm'), 'ninja' => class_exists('Ninja_Forms'));
        $missing = array();
        foreach (self::adapters() as $key) { if (empty($checks[$key])) { $missing[] = self::ADAPTERS[$key][0]; } }
        foreach (self::post_types() as $type => $label) { if (!post_type_exists($type)) { $missing[] = $label; } }
        if ($missing) { echo '<div class="notice notice-warning"><p>Journal des demandes : outil de formulaire non détecté, ' . esc_html(implode(', ', $missing)) . '. Les demandes de cet outil ne seront pas journalisées.</p></div>'; }
        if ((self::$cfg['consent']['mode'] ?? '') === 'wp_consent_api' && !function_exists('wp_has_consent')) { echo '<div class="notice notice-warning"><p>Journal des demandes : WP Consent API est absente. Aucune provenance ne sera mémorisée tant que le consentement ne peut pas être lu.</p></div>'; }
    }

    private static function filter_channel() {
        $c = self::short(wp_unslash($_GET['canal'] ?? ''));
        return in_array($c, self::CHANNELS, true) ? $c : '';
    }
    private static function query($page, $per_page) {
        $args = array('post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => $per_page, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC');
        $c = self::filter_channel();
        if ($c !== '') { $args['meta_query'] = array(array('key' => '_jd_channel', 'value' => $c)); }
        return new WP_Query($args);
    }
    private static function text($value) { return esc_html(is_scalar($value) ? (string) $value : ''); }

    public static function render() {
        if (!current_user_can(self::capability())) { return; }
        $page = max(1, absint($_GET['paged'] ?? 1));
        $q = self::query($page, 25);
        echo '<div class="wrap"><h1>Journal des demandes</h1><p>Demandes acceptées par le serveur. Une provenance absente reste inconnue. Un email accepté par le transport ne prouve pas sa réception.</p>';
        echo '<p><strong>Première arrivée observée :</strong> dans la session de l’onglet, conservée pendant trente minutes d’inactivité, après autorisation de la mesure. Ce suivi ne reconstitue pas le premier contact historique.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="jd-journal"><label>Canal <select name="canal"><option value="">Tous</option>';
        foreach (self::CHANNELS as $c) { echo '<option value="' . esc_attr($c) . '" ' . selected(self::filter_channel(), $c, false) . '>' . self::text($c) . '</option>'; }
        echo '</select></label> <button class="button">Filtrer</button></form>';
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=jd_journal_export'), 'jd_journal_export')) . '">Exporter en CSV</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Date et demande</th><th>Canal et page d’arrivée</th><th>Dernière provenance</th><th>Page du formulaire et notification</th><th>Suivi</th></tr></thead><tbody>';
        foreach ($q->posts as $post) {
            $id = $post->ID; $d = get_post_meta($id, '_jd', true); $d = is_array($d) ? $d : array();
            $first = $d['first'] ?? array(); $last = $d['last'] ?? array();
            $ref = self::text($d['ref'] ?? '');
            if (!empty($d['ref_url'])) { $ref = '<a href="' . esc_url($d['ref_url']) . '">' . $ref . '</a>'; }
            echo '<tr><td>' . self::text(get_the_date('d/m/Y H:i', $post)) . '<br><strong>' . self::text($d['form'] ?? '') . '</strong><br>' . self::text($d['contact'] ?? '') . '<br>' . self::text($d['tool'] ?? '') . ' ' . $ref . '<br>' . self::text($d['duplicate'] ?? '') . '</td>';
            echo '<td><strong>' . self::text($first['channel'] ?? 'Inconnu') . '</strong><br>' . self::text($first['landing'] ?? 'Page inconnue') . '<br>' . self::text($first['referrer'] ?? '') . '<br>' . self::text(!empty($first['evidence']) ? $first['evidence'] : ($d['unavailable_reason'] ?? '')) . '<br>Campagne : ' . self::text($first['campaign'] ?? '') . '</td>';
            echo '<td>' . self::text($last['channel'] ?? 'Inconnue') . '<br>' . self::text($last['landing'] ?? '') . '<br>' . self::text($last['campaign'] ?? '') . '</td>';
            echo '<td>Page : ' . self::text(($d['conversion'] ?? '') ?: 'Inconnue') . '<br>Provenance transmise par : ' . self::text($d['transport'] ?? 'Aucun') . '<br>' . self::text($d['mail'] ?? 'Envoi non observé') . '</td>';
            echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jd_journal_update"><input type="hidden" name="lead_id" value="' . absint($id) . '">';
            wp_nonce_field('jd_journal_' . $id);
            echo '<label>Étape <select name="stage">';
            foreach (self::STAGES as $s) { echo '<option ' . selected($d['stage'] ?? 'Nouveau', $s, false) . '>' . self::text($s) . '</option>'; }
            echo '</select></label><br><label>Réception <select name="received">';
            foreach (self::RECEIVED as $r) { echo '<option ' . selected($d['received'] ?? 'Non vérifiée', $r, false) . '>' . self::text($r) . '</option>'; }
            echo '</select></label><br><label>Origine déclarée <input name="declared_source" value="' . esc_attr($d['declared_source'] ?? '') . '" maxlength="160"></label><br><label>Note <textarea name="note" maxlength="2000">' . esc_textarea($d['note'] ?? '') . '</textarea></label><br><button class="button">Enregistrer</button></form></td></tr>';
        }
        if (!$q->posts) { echo '<tr><td colspan="5">Aucune demande dans cette sélection. Aucune donnée de démonstration n’est ajoutée.</td></tr>'; }
        echo '</tbody></table><p>' . self::text($q->found_posts) . ' demande(s).</p>';
        $links = paginate_links(array('base' => add_query_arg('paged', '%#%'), 'total' => max(1, $q->max_num_pages), 'current' => $page));
        if ($links) { echo wp_kses_post($links); }
        echo '</div>';
    }

    public static function update() {
        if (!current_user_can(self::capability())) { wp_die('Accès refusé', '', array('response' => 403)); }
        $id = absint($_POST['lead_id'] ?? 0); check_admin_referer('jd_journal_' . $id);
        if (get_post_type($id) !== self::CPT) { wp_die('Demande introuvable', '', array('response' => 404)); }
        $d = get_post_meta($id, '_jd', true); $d = is_array($d) ? $d : array();
        $stage = self::short(wp_unslash($_POST['stage'] ?? '')); $received = self::short(wp_unslash($_POST['received'] ?? ''));
        if (!in_array($stage, self::STAGES, true) || !in_array($received, self::RECEIVED, true)) { wp_die('Valeur invalide', '', array('response' => 400)); }
        $d['stage'] = $stage; $d['received'] = $received;
        $d['declared_source'] = self::short(wp_unslash($_POST['declared_source'] ?? ''));
        $d['note'] = self::cut(sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')), 2000);
        update_post_meta($id, '_jd', $d);
        wp_safe_redirect(admin_url('admin.php?page=jd-journal')); exit;
    }

    public static function csv_cell($value) {
        $v = is_scalar($value) ? (string) $value : '';
        return preg_match('/^[\s]*[=+@\-\t\r]/u', $v) ? "'" . $v : $v;
    }
    public static function export() {
        if (!current_user_can(self::capability())) { wp_die('Accès refusé', '', array('response' => 403)); }
        check_admin_referer('jd_journal_export');
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="journal-demandes.csv"');
        $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, array('Date','Formulaire','Contact','Outil','Référence','Canal observé','Page arrivée','Référent','Source campagne','Support campagne','Campagne','Dernier canal','Dernière page arrivée','Page formulaire','Provenance transmise par','Qualité','Doublon','Notification','Réception','Étape','Origine déclarée','Note'));
        $page = 1;
        do {
            $q = new WP_Query(array('post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 100, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC'));
            foreach ($q->posts as $p) {
                $d = get_post_meta($p->ID, '_jd', true); $d = is_array($d) ? $d : array();
                $fi = $d['first'] ?? array(); $la = $d['last'] ?? array();
                $row = array(get_the_date('c', $p), $d['form'] ?? '', $d['contact'] ?? '', $d['tool'] ?? '', $d['ref'] ?? '', $fi['channel'] ?? 'Inconnu', $fi['landing'] ?? '', $fi['referrer'] ?? '', $fi['source'] ?? '', $fi['medium'] ?? '', $fi['campaign'] ?? '', $la['channel'] ?? '', $la['landing'] ?? '', $d['conversion'] ?? '', $d['transport'] ?? '', $d['quality'] ?? '', $d['duplicate'] ?? '', $d['mail'] ?? 'Envoi non observé', $d['received'] ?? 'Non vérifiée', $d['stage'] ?? 'Nouveau', $d['declared_source'] ?? '', $d['note'] ?? '');
                fputcsv($f, array_map(array(__CLASS__, 'csv_cell'), $row));
            }
            $page++;
        } while ($page <= $q->max_num_pages);
        fclose($f); exit;
    }
}
JD_Journal::boot();
