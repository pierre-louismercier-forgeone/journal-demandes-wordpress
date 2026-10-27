# Liens de suivi et analyse hebdomadaire

## Paramètres

Trois paramètres suffisent : `utm_source` (la plateforme), `utm_medium` (le type de trafic), `utm_campaign` (l'emplacement ou l'opération). Tout en minuscules, sans espace ni accent. Garder les mêmes noms dans la durée. Jamais de paramètres sur les liens internes du site : ils écraseraient la vraie provenance.

| Emplacement | Lien type | Canal attendu |
|---|---|---|
| Profil Instagram | `https://SITE/page/?utm_source=instagram&utm_medium=social&utm_campaign=profil` | Instagram |
| Publication LinkedIn | `https://SITE/page/?utm_source=linkedin&utm_medium=social&utm_campaign=sujet` | LinkedIn |
| Newsletter | `https://SITE/page/?utm_source=newsletter&utm_medium=email&utm_campaign=mois` | Email |
| Fiche Google Business Profile | `https://SITE/?utm_source=google&utm_medium=organic&utm_campaign=fiche` | SEO |
| Publicité Meta | `https://SITE/page/?utm_source=facebook&utm_medium=paid_social&utm_campaign=nom` | Meta payant |
| Publicité Google | Marquage automatique gclid activé dans Google Ads | Google Ads |

Règles de classement appliquées par l'extension : marqueur Google Ads, puis paramètres de campagne (support payant si `utm_medium` vaut cpc, ppc, paid, paid_social, paid_search, display, cpm, retargeting), puis référent observable (moteurs, réseaux, assistants IA), sinon Direct ou inconnu. Un paramètre déclare une source, il ne la prouve pas.

Pour chaque lien livré : vérifier que la page de destination répond (code 200, sans redirection vers l'accueil), puis l'écrire dans `LIENS-SUIVI.md` avec l'emplacement et le canal attendu. Ne publier ni remplacer aucun lien sans accord.

## Routine hebdomadaire (15 minutes)

1. Ouvrir Journal des demandes, qualifier chaque Nouveau, mettre à jour les étapes, noter l'origine déclarée quand le prospect la donne.
2. Une fois par mois, exporter le CSV et demander à Claude :

> Analyse cet export du journal des demandes. Exclue les lignes Contrôle, indique la période et distingue demandes, contacts uniques quand c'est possible, demandes qualifiées, devis et ventes. Compare les canaux et les pages d'arrivée avec les effectifs. Affiche les provenances inconnues. Ne calcule pas de taux de conversion du trafic sans données de visites compatibles sur la même période, et ne conclus pas qu'un canal est rentable sans coûts ni revenus. Propose trois actions justifiées par les chiffres et précise les limites.

Sur de petits volumes (moins de 30 demandes par canal), parler de tendance, jamais de conclusion.
