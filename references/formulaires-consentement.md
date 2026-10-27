# Formulaires et consentement : quel réglage choisir

## Adaptateurs de formulaires

Chaque adaptateur écoute l'action serveur que l'outil déclenche quand il accepte un envoi. Le journal ne déduit jamais une demande d'une page de remerciement.

| Clé `adapters` | Outil | Action écoutée | Quand elle se déclenche | Provenance reçue par |
|---|---|---|---|---|
| `cf7` | Contact Form 7 | `wpcf7_submit` (statuts `mail_sent` et `mail_failed`) | Après validation et tentative d'envoi ; spam et erreurs de validation ignorés | Champ caché |
| `wpforms` | WPForms (Lite et Pro) | `wpforms_process_complete` | Après traitement réussi ; en Lite l'entrée n'est pas stockée, le journal la garde quand même | Champ caché |
| `gravity` | Gravity Forms | `gform_after_submission` | Après création de l'entrée ; entrées spam ignorées | Champ caché |
| `elementor` | Elementor Pro (widget Formulaire) | `elementor_pro/forms/new_record` | Après validation, avant les actions d'envoi | Champ caché |
| `fluent` | Fluent Forms 5 et plus | `fluentform/submission_inserted` | Après insertion de l'entrée | Données du formulaire, sinon cookie |
| `formidable` | Formidable Forms | `frm_after_create_entry` | Après création de l'entrée | Champ caché |
| `ninja` | Ninja Forms | `ninja_forms_after_submission` | Après traitement | Cookie de session (Ninja n'envoie pas les champs ajoutés au formulaire) |

Avant de valider un adaptateur, vérifie la version installée (`wp_api.py plugins`). Pour Fluent Forms avant 5.0, l'action s'appelait `fluentform_submission_inserted` : dans ce cas, ajoute dans `functions.php` du thème enfant, après accord, un relais `do_action('journal_demandes_record', …)`, ou mets à jour Fluent Forms.

## Stockage maison et quiz

1. Le site enregistre déjà chaque demande comme un type de contenu (exemple `lead`, `demande`) : `"post_types": {"lead": "Quiz"}`. Le journal crée sa ligne à la première création de ce contenu, pas à ses mises à jour.
2. Formulaire ou quiz codé sur mesure sans stockage : ajoute après l'enregistrement réussi, dans son gestionnaire serveur, `do_action('journal_demandes_record', array('form' => 'Quiz', 'values' => $donnees_validees));`. Les clés utiles : `form`, `values`, `contact` (sinon le premier email trouvé), `ref`, `ref_url`, `prov` (chaîne JSON reçue du navigateur si le quiz l'envoie lui même). Si l'envoi se fait en JSON par `fetch`, le cookie de session `jd_prov` suffit.
3. Le type de contenu n'apparaît pas dans `wp_api.py types` quand il n'est pas exposé à l'API : lis le code de l'extension ou du thème qui l'enregistre.

## Choix du mode de consentement

La provenance n'est mémorisée qu'après accord de la catégorie mesure d'audience. Un outil encore en chargement n'est pas un refus : rien n'est effacé tant que la décision n'est pas lisible. Un refus ou un retrait efface la mémoire et le cookie.

| Outil repéré | Mode conseillé | Réglage `consent` |
|---|---|---|
| WP Consent API présente (Complianz, CookieYes plugin, Real Cookie Banner, Cookiebot plugin récent l'alimentent) | `wp_consent_api` | `{"mode": "wp_consent_api", "category": "statistics"}` |
| CookieYes sans WP Consent API | `cookieyes` | `{"mode": "cookieyes", "category": "analytics"}` |
| Cookiebot sans WP Consent API | `cookiebot` | `{"mode": "cookiebot", "category": "statistics"}` |
| Axeptio | `axeptio` | `{"mode": "axeptio", "vendor": "NOM_DU_SERVICE"}` où le nom est celui du service de mesure déclaré dans le projet Axeptio (exemple `google_analytics`), lu dans la console : `_axcb.push(s => s.on('cookies:complete', c => console.log(c)))` |
| Complianz sans WP Consent API | `cookie` | `{"mode": "cookie", "cookie": "cmplz_statistics", "allow": "^allow$", "deny": "^deny$"}` |
| tarteaucitron | `cookie` | `{"mode": "cookie", "cookie": "tarteaucitron", "allow": "!gtag=true", "deny": "!gtag=false"}` en remplaçant `gtag` par le service de mesure réellement déclaré |
| Autre bandeau (Borlabs, Didomi, OneTrust, iubenda, maison) | `cookie` | Lire `document.cookie` après un accord puis après un refus, et écrire les deux expressions qui les distinguent |
| Aucun bandeau | `none` | Uniquement si la personne l'assume après ton avertissement ; noter sa décision dans le relevé |

Le mode `cookie` n'est jamais rempli de mémoire : relève les valeurs réelles dans le navigateur du site, avec un accord puis un refus, et colle les preuves dans le relevé.

## Visiteurs connectés

Le script ne se charge pas pour un utilisateur connecté (`track_logged_in: false`) : les essais se font en navigation privée. Une demande envoyée par un compte connecté, ou contenant le mot TEST, est classée Contrôle.
