<?php
// Banc de test isolé : fonctions WordPress simulées, aucune connexion, aucun envoi.
// Usage : php tests/test_plugin.php [dossier de l’extension générée]
define('ABSPATH', '/');
$src = $argv[1] ?? __DIR__ . '/../assets/plugin/journal-demandes';
// Copie temporaire : le banc ne touche jamais à l’extension testée.
$dir = sys_get_temp_dir() . '/jd-test-' . getmypid();
@mkdir($dir);
copy($src . '/journal-demandes.php', $dir . '/journal-demandes.php');
$GLOBALS['hooks'] = array(); $GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['caps'] = false; $GLOBALS['logged'] = false; $GLOBALS['checks'] = 0;
function add_action($h, $cb, $p = 10, $n = 1) { $GLOBALS['hooks'][$h][] = $cb; }
function add_filter($h, $cb, $p = 10, $n = 1) { $GLOBALS['hooks'][$h][] = $cb; }
function fire($h, ...$args) { foreach ($GLOBALS['hooks'][$h] ?? array() as $cb) { call_user_func_array($cb, $args); } }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function sanitize_text_field($v) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $v))); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_email($v) { return strtolower(trim($v)); }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function wp_is_post_revision($id) { return false; }
function wp_is_post_autosave($id) { return false; }
function current_time($k) { return $k === 'mysql' ? '2026-10-27 12:00:00' : '27/10/2026 12:00'; }
function is_user_logged_in() { return $GLOBALS['logged']; }
function current_user_can($c) { return $GLOBALS['caps']; }
function wp_insert_post($a, $e = false) { $id = count($GLOBALS['posts']) + 100; $GLOBALS['posts'][$id] = $a; return $id; }
function is_wp_error($v) { return false; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; }
function get_post_meta($id, $k = '', $s = false) { return $k === '' ? ($GLOBALS['meta'][$id] ?? array()) : ($GLOBALS['meta'][$id][$k] ?? ''); }
function get_posts($a) {
    $out = array();
    foreach ($GLOBALS['meta'] as $id => $m) {
        $match = true;
        foreach ($a['meta_query'] ?? array() as $q) { if (($m[$q['key']] ?? null) !== $q['value']) { $match = false; } }
        if ($match && isset($GLOBALS['posts'][$id])) { $out[] = $id; }
    }
    return $out;
}
function esc_url_raw($v) { return (string) $v; }
function admin_url($p = '') { return 'https://exemple.fr/wp-admin/' . $p; }
function absint($v) { return abs((int) $v); }
function wp_list_pluck($l, $f) { return array_map(function ($x) use ($f) { return is_array($x) ? ($x[$f] ?? null) : null; }, $l); }
function wp_die($m, $t = '', $a = array()) { throw new Exception($m, $a['response'] ?? 0); }
function check_admin_referer($a) { return true; }
function check($cond, $label) { if (!$cond) { fwrite(STDERR, "ÉCHEC : $label\n"); exit(1); } $GLOBALS['checks']++; }

file_put_contents($dir . '/config.php', "<?php\nreturn array('adapters' => array('cf7','wpforms','gravity','elementor','fluent','formidable','ninja'), 'post_types' => array('lead' => 'Quiz'), 'consent' => array('mode' => 'wp_consent_api'), 'notification_email' => 'contact@exemple.fr', 'capability' => 'manage_options');\n");
require $dir . '/journal-demandes.php';

foreach (array('wpcf7_submit','wpforms_process_complete','gform_after_submission','elementor_pro/forms/new_record','fluentform/submission_inserted','frm_after_create_entry','ninja_forms_after_submission','save_post_lead','journal_demandes_record','wp_mail_succeeded','wp_mail_failed') as $h) { check(!empty($GLOBALS['hooks'][$h]), "crochet $h branché"); }

$prov = json_encode(array('first' => array('channel' => 'Instagram', 'landing' => '/renovation/?email=x@y.fr', 'campaign' => 'profil', 'referrer' => 'l.instagram.com'), 'last' => array('channel' => 'Inventé', 'landing' => 'https://evil.tld/x'), 'conversion' => '/contact/', 'request_id' => 'abc-123'));

// Contact Form 7 : email envoyé avant le crochet de soumission
class WPCF7_Submission { public static function get_instance() { return new self; } public function get_posted_data() { return array('your-name' => 'Marie', 'your-email' => 'Marie@Exemple.fr'); } }
class FakeCF7 { public function title() { return 'Contact'; } public function id() { return 7; } }
$_POST = array('jd_prov' => addslashes($prov));
fire('wp_mail_succeeded', array('to' => array('contact@exemple.fr')));
fire('wpcf7_submit', new FakeCF7, array('status' => 'mail_sent'));
$id = 100; $d = get_post_meta($id, '_jd');
check($d['form'] === 'Contact', 'formulaire CF7 nommé');
check($d['contact'] === 'marie@exemple.fr', 'contact extrait');
check($d['first']['channel'] === 'Instagram', 'canal conservé');
check($d['first']['landing'] === '/renovation/', 'chaîne de requête retirée');
check($d['last']['channel'] === 'Direct ou inconnu', 'canal inventé refusé');
check($d['last']['landing'] === '', 'adresse externe refusée');
check($d['conversion'] === '/contact/', 'page de conversion');
check($d['transport'] === 'Champ du formulaire', 'transport champ');
check($d['mail'] === 'Accepté par le transport', 'email antérieur rattaché');
check($d['received'] === 'Non vérifiée', 'réception non présumée');
check($d['stage'] === 'Nouveau', 'étape initiale');
check(get_post_meta($id, '_jd_channel') === 'Instagram', 'clé de filtre');

// Spam et erreur de validation ignorés
$before = count($GLOBALS['posts']);
fire('wpcf7_submit', new FakeCF7, array('status' => 'spam'));
fire('wpcf7_submit', new FakeCF7, array('status' => 'validation_failed'));
check(count($GLOBALS['posts']) === $before, 'spam et validation échouée ignorés');

// Double soumission : signalée, pas bloquée
fire('wpcf7_submit', new FakeCF7, array('status' => 'mail_sent'));
$d2 = get_post_meta(101, '_jd');
check(is_array($d2) && strpos($d2['duplicate'], '100') !== false, 'doublon signalé sans blocage');

// Ninja Forms : provenance par cookie seulement
$_POST = array(); $_COOKIE = array('jd_prov' => addslashes(json_encode(array('first' => array('channel' => 'SEO', 'landing' => '/'), 'conversion' => '/devis/'))));
fire('ninja_forms_after_submission', array('settings' => array('title' => 'Devis'), 'fields' => array(array('value' => 'TEST ForgeOne'), array('value' => 'test@exemple.fr'))));
$d3 = get_post_meta(102, '_jd');
check($d3['transport'] === 'Cookie de session', 'transport cookie');
check($d3['first']['channel'] === 'SEO', 'canal par cookie');
check($d3['stage'] === 'Contrôle', 'essai TEST classé Contrôle');

// Sans provenance : la demande existe quand même
$_COOKIE = array();
fire('wpforms_process_complete', array(array('type' => 'email', 'value' => 'paul@exemple.fr')), array(), array('id' => 3, 'settings' => array('form_title' => 'Rappel')), 0);
$d4 = get_post_meta(103, '_jd');
check($d4['quality'] === 'Provenance absente', 'provenance inconnue assumée');
check($d4['transport'] === 'Aucun', 'aucun transport');

// Stockage existant : nouvelle demande seulement
$post = (object) array('post_title' => 'Quiz Julie', 'post_status' => 'publish');
fire('save_post_lead', 55, $post, true);
check(count($GLOBALS['posts']) === 4, 'mise à jour ignorée');
fire('save_post_lead', 55, $post, false);
check(get_post_meta(104, '_jd')['form'] === 'Quiz', 'stockage existant raccordé');

// Écran et export réservés
$GLOBALS['caps'] = false;
try { JD_Journal::export(); check(false, 'export refusé'); } catch (Exception $e) { check($e->getCode() === 403, 'export refusé sans droit'); }
try { $_POST = array('lead_id' => 100); JD_Journal::update(); check(false, 'modification refusée'); } catch (Exception $e) { check($e->getCode() === 403, 'modification refusée sans droit'); }
check(JD_Journal::csv_cell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")", 'formule neutralisée');
check(JD_Journal::csv_cell(' +33 6') === "' +33 6", 'préfixe plus neutralisé');
check(JD_Journal::csv_cell('Instagram') === 'Instagram', 'texte normal intact');

unlink($dir . '/config.php'); unlink($dir . '/journal-demandes.php'); rmdir($dir);
echo $GLOBALS['checks'] . " contrôles PHP réussis.\n";
