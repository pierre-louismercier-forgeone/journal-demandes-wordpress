# Clé API WordPress : mot de passe d'application

WordPress fournit depuis la version 5.6 des « mots de passe d'application ». Ils donnent accès à l'API sans révéler le mot de passe de connexion, se révoquent un par un, et ne permettent pas de se connecter à l'écran d'administration.

## Ce que la personne fait (2 minutes)

1. Se connecter à l'administration avec un compte administrateur.
2. Menu Utilisateurs, puis Profil. Adresse directe : `https://SITE/wp-admin/profile.php#application-passwords-section`.
3. Descendre à la section « Mots de passe d'application ».
4. Champ « Nom du nouveau mot de passe d'application » : écrire `Journal des demandes`.
5. Cliquer « Ajouter un mot de passe d'application ».
6. Copier le mot de passe affiché (24 caractères en groupes de 4, les espaces peuvent rester). Il ne sera plus jamais affiché.
7. Coller dans le fichier ouvert par Claude après `WP_APP_PASSWORD=`, et l'identifiant de connexion (pas l'email si le site demande l'identifiant) après `WP_USER=`. Enregistrer.

Les libellés peuvent varier selon la version et la langue. Si tu as un navigateur, vérifie l'écran avant de le décrire. Sinon, décris la section par sa position : tout en bas de la page Profil.

## Fichier d'identifiants

`~/.config/journal-demandes/<domaine>.env`, droits 600, créé par `wp_api.py init`. Les scripts le lisent et n'affichent jamais le mot de passe. Ne jamais faire `cat` de ce fichier dans la conversation. Pour vérifier qu'il est rempli, utiliser `wp_api.py check`.

## Dépannage de `check`

| Symptôme | Cause probable | Action |
|---|---|---|
| `api_publique: false`, code 404 | API REST désactivée ou permaliens simples | Réglages, Permaliens : choisir « Titre de la publication » ; vérifier qu'aucune extension ne bloque `/wp-json/` |
| `api_publique: false`, code 401 ou 403 | Extension de sécurité qui ferme l'API aux visiteurs | Autoriser l'API REST dans l'extension (Wordfence, Solid Security, All In One WP Security, Perfmatters) |
| `connexion: false`, code 401 `incorrect_password` | Mot de passe d'application mal copié ou identifiant erroné | Recréer le mot de passe ; essayer l'identifiant de connexion plutôt que l'email |
| `connexion: false`, code 401 `rest_not_logged_in` | L'hébergeur supprime l'en tête Authorization (Apache en CGI) | Demander à l'hébergeur ou ajouter dans `.htaccess` : `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` (modification serveur : accord requis) |
| Section absente du profil | Site sans HTTPS, ou fonction désactivée par une extension de sécurité ou par un filtre `wp_is_application_passwords_available` | Activer HTTPS, ou réactiver la fonction dans l'extension |
| `activate_plugins: false` | Compte non administrateur | Utiliser un compte administrateur ou passer par la personne qui l'est |
| Multisite | Activation réservée au super administrateur | Faire activer par le super administrateur |

## Sans clé API

Tout reste possible sauf la vérification automatique : tu génères l'archive, la personne la téléverse et l'active, puis tu vérifies les pages publiques (`verify-page`, sans clé) et l'écran du journal par capture d'écran.

## Fin de mission

Rappeler la révocation : Profil, Mots de passe d'application, bouton « Révoquer » sur la ligne « Journal des demandes ». Supprimer ensuite le fichier `.env` local si la personne le souhaite (suppression : demander confirmation).
