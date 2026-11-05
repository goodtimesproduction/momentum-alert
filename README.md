# Momentum Alert — fonctionnement et maintenance

Radar boursier **informatif** pour les hausses US (FMP), avec suivi des replis (Twelve Data) et messages Telegram. **Aucun ordre de courtage n'est exécuté**.

## Situation vérifiée le 23 octobre 2026

- Le workflow `momentum-alert` tourne sur `main`; 740 exécutions enregistrées à la date de l'audit.
- Dernier workflow examiné : [exécution 740](https://github.com/goodtimesproduction/momentum-alert/actions/runs/39994064231), terminée en succès avec six notifications Telegram acceptées.
- `state.json` contenait 618 titres dont **97 suivis** (`RIDING`). Quatre suivis dépassaient sept jours sans mise à jour en raison d'erreurs de cotation.
- Chaque titre suivi déclenche une requête Twelve Data suivie de 8 secondes d'attente : un cycle avec 97 titres nécessite de l'ordre de treize minutes.
- Des erreurs de données Twelve Data sont actuellement traitées sans échec du job : **« Success » ne garantit pas des cours valides**.
- Le calendrier « toutes les 30 minutes » peut être retardé/ignoré par l'ordonnanceur GitHub : **ne pas considérer le cron comme une garantie de fréquence**.

## Ce que change cette branche (préparation à une future V3)

- `actions/checkout@v4` passe à `@v6` pour retirer l'avertissement Node.js 20 et profiter du support Node.js 24.
- Le cron est décalé sur les minutes **07 et 37** (UTC) pour éviter les minutes 00 et 30, plus chargées.
- Un plafond de **25 minutes** est ajouté pour éviter les exécutions indéfiniment bloquées.

**Aucun changement du script PHP ni du fichier d'état n'est introduit ici.** Les seuils (15–60 % de hausse, 10 $ minimum, repli de 12 %) et la logique Telegram restent identiques.

## Prochaine étape technique

Préparer et tester une migration à partir des 97 suivis existants, sans les réinitialiser par erreur. Distinguer : titre en observation / position réellement achetée, panne de cours / repli confirmé, et timestamp de donnée de marché / heure du scan. Un suivi ne doit pas générer de recommandation automatique d'achat ou vente.

Les tokens Telegram, Twelve Data et FMP doivent rester dans les **GitHub Actions Secrets** ; ne jamais les placer dans les commits ou les logs.
