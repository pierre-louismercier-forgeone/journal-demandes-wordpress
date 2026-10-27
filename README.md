# Journal des demandes WordPress

Un skill Claude Code qui installe sur votre site WordPress un journal privé : pour chaque demande envoyée par un formulaire, vous voyez la page d'arrivée, le canal (SEO, Instagram, LinkedIn, Google Ads, email, assistant IA), la campagne, la page du formulaire, puis vous suivez la demande jusqu'à la vente.

Vous lancez le skill, vous répondez à quelques questions, Claude fait le reste : inspection du site, génération de l'extension adaptée à vos formulaires et à votre bandeau cookies, liens de suivi, installation et tests. Rien n'est modifié sur votre site sans votre accord.

## Installation (1 minute)

Il faut Claude Code et Python 3 (présent sur Mac).

```
git clone https://github.com/pierre-louismercier-forgeone/journal-demandes-wordpress.git ~/.claude/skills/journal-demandes-wordpress
```

Ouvrez une nouvelle session Claude Code et tapez :

```
/journal-demandes-wordpress
```

Ou simplement : « je veux savoir d'où viennent les demandes de mon site WordPress ».

## Ce qu'il vous faudra

1. L'adresse de votre site et un compte administrateur WordPress.
2. Une clé API WordPress (un « mot de passe d'application »). Le skill vous montre où la créer et vous la faites coller dans un fichier protégé sur votre ordinateur : elle ne passe jamais par la conversation.
3. Idéalement une copie de test de votre site, sinon une sauvegarde récente.

## Compatible avec

Formulaires : Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Formidable Forms, Ninja Forms, et les formulaires ou quiz codés sur mesure.

Bandeaux cookies : tous ceux qui utilisent WP Consent API (Complianz, CookieYes, Real Cookie Banner), Cookiebot, CookieYes, Axeptio, tarteaucitron et les autres bandeaux lisibles par leur cookie.

## Ce que le journal garde, et ce qu'il ne garde pas

La provenance n'est mémorisée qu'après accord de mesure d'audience. Aucune adresse IP, aucun identifiant publicitaire, aucune chaîne de requête complète. Aucun point de collecte public ajouté. Désactiver l'extension suffit pour revenir en arrière : rien n'est supprimé.

La provenance vient du navigateur : elle n'est pas certifiée, ne suit pas un visiteur sur un autre appareil et reste inconnue pour les anciennes demandes.

## Mise à jour

```
cd ~/.claude/skills/journal-demandes-wordpress && git pull
```

Créé par ForgeOne. Licence GPL 2.0 ou ultérieure.
