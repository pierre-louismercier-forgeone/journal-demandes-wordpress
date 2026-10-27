# Recette du journal

À faire sur la copie de test, puis à refaire sur le site public après accord. Navigation privée, déconnecté de WordPress. Identités avec le mot TEST (exemple « TEST Journal 27 octobre »), adresse de réception de test contrôlée par la personne. Avant le premier envoi : lister les destinataires des notifications et de l'accusé de réception, et obtenir l'accord.

## Cas à exécuter

| N° | Cas | Comment | Attendu dans le journal |
|---|---|---|---|
| 1 | Campagne et navigation interne | Ouvrir `/page-a/?utm_source=instagram&utm_medium=social&utm_campaign=testjournal1`, accepter la mesure, visiter deux autres pages, envoyer le formulaire 1 | Canal Instagram, page d'arrivée `/page-a/`, campagne testjournal1, page du formulaire correcte, étape Contrôle |
| 2 | Deuxième formulaire ou quiz | Autre page, `utm_source=linkedin&utm_medium=social&utm_campaign=testjournal2`, terminer le parcours complet | Canal LinkedIn, sa page d'arrivée, sa campagne |
| 3 | Refus de mesure | Nouvelle fenêtre privée, refuser, envoyer | Demande présente, provenance absente, raison « non autorisée » |
| 4 | Retrait | Accepter, visiter deux pages, retirer l'accord depuis le bandeau, envoyer | Provenance absente |
| 5 | Bandeau lent | Recharger une page interne alors que l'accord existe déjà | Provenance d'origine conservée (pas de bascule en Direct) |
| 6 | Source inconnue | Arriver sans paramètre ni référent | Direct ou inconnu |
| 7 | Expiration | Facultatif : attendre plus de trente minutes entre deux pages | Nouvelle arrivée |
| 8 | Double clic | Cliquer deux fois sur Envoyer | Seconde ligne signalée « Doublon possible », jamais bloquée |
| 9 | Erreur de validation | Envoyer avec un champ obligatoire vide | Aucune ligne |
| 10 | Accès | Compte non administrateur (ou déconnecté) sur `wp-admin/admin.php?page=jd-journal` et sur l'export | Accès refusé |
| 11 | Export | Exporter le CSV, l'ouvrir | Colonnes complètes, aucune cellule commençant par `=` exécutée |
| 12 | Email | Vérifier la boîte destinataire | Réception confirmée seulement si le message est vu dans la boîte ; sinon « non vérifiée » |

Preuves possibles : sortie de `wp_api.py entries`, capture de l'écran du journal, capture de la boîte de réception avec l'identité TEST, sortie de `verify-page`. Ne déclare jamais un email reçu sans preuve dans la boîte destinataire. « Accepté par le transport » veut seulement dire que WordPress a passé le message au serveur d'envoi.

## Modèle de RECETTE.md

```
# Recette du journal des demandes, <site>, <date>
Environnement : copie de test ou site public. Version de l'extension : <version lue>.
| N° | Cas | Attendu | Observé | Preuve | Statut |
Défauts trouvés et correction appliquée.
Non vérifié et pourquoi.
Les canaux ont été simulés par paramètres de campagne ; aucun clic réel depuis une plateforme n'a été testé.
```

Statuts autorisés : Réussi, Échec, Non vérifié. Après la recette, toutes les lignes TEST restent en Contrôle avec une note pour les exclure des analyses.
