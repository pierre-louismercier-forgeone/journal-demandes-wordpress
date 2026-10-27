---
name: journal-demandes-wordpress
description: Installe sur un site WordPress un journal privé qui relie chaque demande de formulaire à sa page d'arrivée, son canal (SEO, Instagram, LinkedIn, Google Ads, email, IA), sa campagne et son suivi commercial. Le skill guide la personne de bout en bout, pose les questions utiles, l'aide à créer sa clé API WordPress (mot de passe d'application) sans la coller dans le chat, inspecte le site, génère l'extension adaptée à ses formulaires et à son bandeau cookies, prépare les liens de suivi, puis conduit l'installation et la recette avec preuves. Utiliser dès que quelqu'un dit « journal des demandes », « savoir d'où viennent mes demandes », « tracking des formulaires WordPress », « provenance des leads », « quel canal amène mes contacts », « attribution des formulaires », « suivi des leads WordPress », « UTM et formulaires », ou lance /journal-demandes-wordpress. Ne pas utiliser pour Shopify, Wix, Webflow ou un site sans WordPress.
---

# Journal des demandes WordPress

Objectif : pour chaque demande envoyée par un formulaire, garder dans WordPress la date, le formulaire, la première page d'arrivée observée, le canal, la campagne, la page de conversion, puis un suivi commercial (statut, note, origine déclarée). La personne lance le skill, répond à quelques questions, et repart avec une extension installée, testée et des liens de suivi prêts.

Tu fais tout ce que tes outils permettent. Tu ne demandes à la personne que ce qu'elle seule peut faire : créer sa clé API, donner son accord, téléverser l'archive si tu n'as pas de navigateur.

## Règles non négociables

1. Le secret ne passe jamais par le chat. La clé API va dans un fichier local protégé que la personne remplit elle même. Si elle la colle quand même dans la conversation, écris la dans le fichier, ne la répète jamais, et recommande de la révoquer puis d'en recréer une à la fin.
2. Lecture seule d'abord. Aucune modification du site public, aucune activation, aucun email de test sans accord explicite donné pour cette action précise. Un accord pour la copie de test ne vaut pas pour le site public.
3. Rien d'inventé. Une extension, une version, un mécanisme de consentement ou un résultat de test se prouve (sortie de commande, page lue, capture). Sinon tu écris « non vérifié ». Les anciennes demandes gardent une provenance inconnue.
4. Une demande enregistrée n'est ni un email reçu, ni une personne unique, ni une vente. Le journal sépare ces informations.
5. Un clic simulé par paramètres de campagne n'est pas un clic réel venu de la plateforme. Dis le dans chaque rapport de recette.
6. Garde un relevé : `RELEVE.md` dans le dossier de livraison, mis à jour à chaque étape (preuve, décision, accord donné, date).

Dossier de livraison : `~/Desktop/journal-demandes-<domaine>/`. Scripts du skill : `~/.claude/skills/journal-demandes-wordpress/scripts/` (noté `$S` ci dessous).

## Étape 1. Cadrage

Demande l'adresse du site en une phrase si elle n'est pas donnée. Puis pose en une seule fois avec l'outil de questions à choix :

1. Copie de test : « J'ai une copie de test (staging) » (recommandé), « Je n'en ai pas, on travaille sur le site public avec sauvegarde », « Je ne sais pas ».
2. Accès : « Je suis administrateur du site », « Quelqu'un d'autre l'est (développeur, agence) ».
3. Email qui reçoit les notifications de formulaires : choix « Je te le donne », « Je ne sais pas ».

Si la personne n'a pas de copie de test, explique en deux lignes le risque (l'extension ne modifie aucune donnée existante, le retour arrière se fait par désactivation) et demande une sauvegarde récente avant l'installation.

## Étape 2. Lecture publique (sans clé)

```bash
python3 $S/wp_api.py detect --site https://exemple.fr --pages /contact/ /devis/
```

Ajoute aux pages les URL de formulaires trouvées dans le menu du site (lis l'accueil). Note dans le relevé : formulaires repérés, outil de consentement repéré, extensions visibles. Cette lecture ne prouve pas ce qui est actif côté serveur.

## Étape 3. Clé API WordPress (mot de passe d'application)

Lis `references/cle-api.md` avant cette étape. Séquence :

1. Crée le fichier protégé : `python3 $S/wp_api.py init --site https://exemple.fr`.
2. Donne à la personne le chemin exact pour créer la clé : Utilisateurs, Profil, section « Mots de passe d'application », nom « Journal des demandes », bouton « Ajouter un mot de passe d'application ». Si tu as un navigateur connecté, ouvre toi même `https://exemple.fr/wp-admin/profile.php#application-passwords-section` et vérifie que la section existe avant de la décrire.
3. Ouvre le fichier pour elle (`open -e <fichier>` sur Mac, sinon donne le chemin) : elle colle son identifiant de connexion après `WP_USER=` et le mot de passe affiché après `WP_APP_PASSWORD=`, puis enregistre.
4. Attends sa confirmation par une question à choix : « C'est enregistré », « Je ne trouve pas la section », « J'ai une erreur ».
5. Vérifie : `python3 $S/wp_api.py check --site https://exemple.fr`. Il faut `connexion: true` et `activate_plugins: true`. En cas d'échec, applique le dépannage de `references/cle-api.md`.

Si la section n'existe pas (désactivée par une extension de sécurité ou site en HTTP), propose dans cet ordre : réactiver la fonction dans l'extension de sécurité, passer par un administrateur, ou continuer sans clé (tu génères l'archive et la personne l'installe à la main, la recette se fait par l'écran du journal).

## Étape 4. Inventaire vérifié

```bash
python3 $S/wp_api.py plugins --site https://exemple.fr
python3 $S/wp_api.py types --site https://exemple.fr
```

Croise avec l'étape 2 et `references/formulaires-consentement.md`. Présente un tableau court : formulaire, outil et version, où les demandes sont stockées, adaptateur prévu, statut (vérifié ou non vérifié). Pour un formulaire codé sur mesure ou un quiz, lis son code si tu y as accès, sinon demande le fichier exact ou un export à la personne ou à son développeur.

Puis une question à choix multiples : quels formulaires raccorder (tous cochés par défaut). Et une question sur le consentement : le mode détecté en premier (recommandé), les autres modes plausibles, « Pas de bandeau cookies ». Si la personne n'a pas de bandeau, explique que la mémoire de provenance relève de la mesure d'audience et recommande d'en installer un compatible WP Consent API (Complianz, CookieYes ou Real Cookie Banner) plutôt que le mode `none`, qui reste sous sa responsabilité.

Si le mode n'est pas certain, vérifie dans le navigateur (console : `typeof wp_has_consent`, `window.Cookiebot`, `typeof getCkyConsent`, `document.cookie` avant et après un accord puis un refus). Ne choisis jamais un mode par supposition.

## Étape 5. Colonnes et ligne d'exemple

Montre dans le chat seulement (rien dans WordPress) les colonnes du journal et une ligne fictive marquée EXEMPLE : date, formulaire, contact, première page d'arrivée, canal, campagne, page de conversion, notification, réception, étape, origine déclarée, note. Sépare observé (navigateur), déclaré (prospect, paramètres de lien) et inconnu.

## Étape 6. Génération de l'extension

Copie `assets/config.exemple.json` en `config.json` dans le dossier de livraison, puis renseigne `adapters` (outils de formulaire), `post_types`, `consent` et `notification_email` avec les valeurs vérifiées à l'étape 4.

Valeurs possibles et cas particuliers : `references/formulaires-consentement.md`. Un stockage existant de demandes (type de contenu maison) se raccorde par `post_types` (`{"lead": "Quiz"}`). Un formulaire codé sur mesure se raccorde par une ligne dans son gestionnaire serveur, après l'enregistrement réussi :

```php
do_action('journal_demandes_record', array('form' => 'Quiz', 'values' => $donnees_validees));
```

Génère puis teste :

```bash
python3 $S/build_plugin.py --config ~/Desktop/journal-demandes-<domaine>/config.json --out ~/Desktop/journal-demandes-<domaine>/
node ~/.claude/skills/journal-demandes-wordpress/tests/test_attribution.cjs ~/Desktop/journal-demandes-<domaine>/journal-demandes/assets/attribution.js
php ~/.claude/skills/journal-demandes-wordpress/tests/test_plugin.php ~/Desktop/journal-demandes-<domaine>/journal-demandes   # si PHP est installé
php -l ~/Desktop/journal-demandes-<domaine>/journal-demandes/journal-demandes.php
```

Montre les sorties. Si PHP n'est pas disponible, dis le : la syntaxe PHP sera validée par l'activation sur la copie de test, pas avant.

Ce que fait l'extension, à expliquer simplement : un script public mémorise la provenance dans la session de l'onglet après accord de mesure, pendant trente minutes d'inactivité ; à l'envoi il ajoute un champ caché et un cookie de session ; le serveur valide, limite et rattache ces valeurs à la demande que l'outil de formulaire a acceptée ; l'écran « Journal des demandes » (administrateurs) affiche, filtre par canal, permet le suivi et exporte un CSV protégé contre les formules. Aucune adresse IP, aucun identifiant publicitaire, aucune chaîne de requête complète. Aucun endpoint public de collecte. Désactiver l'extension suffit pour revenir en arrière ; rien n'est supprimé.

## Étape 7. Liens de suivi

Lis `references/liens-et-analyse.md`. Demande les emplacements prévus (question à choix multiples : profil Instagram, publications LinkedIn, newsletter, Google Business Profile, campagne payante, autre). Pour chacun, donne le lien complet vers une page existante du site (vérifie qu'elle répond 200), avec `utm_source`, `utm_medium`, `utm_campaign`, et le canal attendu dans le journal. Écris `LIENS-SUIVI.md`. Ne modifie aucun lien publié.

## Étape 8. Installation

Demande l'accord par une question à choix : « Installer sur la copie de test » (recommandé si elle existe), « Installer sur le site public », « Pas maintenant ».

1. Téléversement : l'API WordPress ne permet pas de téléverser une archive. Si tu as un navigateur connecté à l'administration, fais le toi même : Extensions, Ajouter une extension, Téléverser une extension, choisir `journal-demandes.zip`, Installer maintenant, sans activer. Sinon, guide la personne pas à pas avec le chemin exact du fichier.
2. Vérifie : `python3 $S/wp_api.py status --site …` (installée, inactive).
3. Active après accord : `python3 $S/wp_api.py activate --site … --yes`.
4. Vérifie en visiteur déconnecté chaque page de formulaire : `python3 $S/wp_api.py verify-page --site … --path /contact/`. Script absent alors que l'extension est active : vider le cache (extension de cache, hébergeur, CDN) puis revérifier.
5. Vérifie l'écran Journal des demandes (navigateur ou capture fournie) et l'API de lecture : `python3 $S/wp_api.py entries --site …`.

## Étape 9. Recette

Suis `references/recette.md`. Avant tout envoi, liste les destinataires des emails et demande l'accord. Identités contenant le mot TEST (classées Contrôle automatiquement), campagnes distinctes, navigation privée déconnectée. Pour chaque cas : attendu, observé, preuve. Écris `RECETTE.md`. Termine par les défauts à corriger. Une correction passe par `config.json` ou le modèle du skill, puis régénération, jamais par une retouche à la main sur le serveur.

Sur le site public, après accord : refais les parcours autorisés, vérifie le cache, puis classe les essais en Contrôle.

## Étape 10. Remise

Message final court : ce qui est installé et où, preuves (sorties, captures), ce qui reste non vérifié (par exemple la réception des notifications dans la boîte destinataire), les liens de suivi, le retour arrière, et la routine hebdomadaire de `references/liens-et-analyse.md`. Rappelle de révoquer la clé API si elle ne sert plus : Utilisateurs, Profil, Mots de passe d'application, Révoquer.

## Limites à annoncer

La provenance vient du navigateur et n'est pas certifiée. Elle exige l'accord de mesure et ne suit pas un visiteur sur un autre appareil ou un autre onglet. Les liens de profil sans référent (Instagram en application) ne sont reconnus qu'avec des liens marqués. Les appels et messages WhatsApp ne deviennent pas des demandes : ils se rapprochent à la main. Le journal ne déduplique pas les personnes : il signale les doublons probables sans bloquer un envoi.
