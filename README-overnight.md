# Overnight Momentum — extension expérimentale

**Statut : testé localement sur données synthétiques, non connecté aux API, non déployé.**

Ce module ne donne **aucun ordre de trading**. Il ne modifie ni `momentum_alert.php` ni `state.json` : son historique distinct est `overnight_state.json`.

## Intention

Repérer les actions américaines dont la hausse se forme dans les 90 dernières minutes de la séance, progresse après 16 h ET, est soutenue en préouverture le lendemain et, éventuellement, s'accélère après l'ouverture. Le système n'affirme pas prédire les gaps, il décrit des mouvements constatés.

| Phase (heure New York, ET) | Conditions préliminaires | Notification |
| --- | --- | --- |
| 14 h 30–16 h | Progression ≥ 2 % depuis le premier instantané valide de cette fin de séance | 🔎 Radar fin de séance |
| 16 h–20 h | Progression ≥ 2,5 % par rapport au dernier cours observé de la séance normale | 🌙 Boost after-hours |
| 7 h–9 h 30 (lendemain de séance) | Signal close ou after-hours préalable, hausse ≥ 4 % depuis la référence de fin de séance et accélération ≥ 1 % en préouverture | 🌅 Préouverture à examiner |
| 9 h 30–10 h 15 | Préouverture validée, prix ≥ 0,5 % au-dessus du pic prémarket et accélération à l'ouverture ≥ 0,5 % | 🚀 Ouverture confirmée |

Les seuils sont **hypothèses de test**, pas des seuils optimisés ni une promesse de rendement.

Toutes les données utilisées proviennent du flux **Alpaca IEX gratuit**, dont la couverture de l'ensemble du marché américain est partielle. Le système refuse un cours, un carnet ou une barre d'une autre session (ce qui évite de confondre une clôture de 15 h 59 avec une véritable hausse de 16 h 07), les données de plus de 10 min, un spread supérieur à 1,5 % et un volume inférieur à 25 actions sur la dernière minute IEX. **Ce volume n'est pas le volume global du marché**. Un filtre aussi strict peut exclure de nombreux titres en extended hours.

La découverte des titres repose sur les plus fortes hausses FMP à partir de +2 % (pas sur la totalité des titres US), plus une watchlist facultative `overnight_watchlist.json`. Un titre gagnant après la clôture, mais absent des deux, ne sera pas repéré. Le fichier d'état conserve au maximum 60 symboles et expire les anciens signaux après quatre jours calendaires. Les horaires partiels, les jours fériés et les données manquantes doivent être testés avant toute utilisation réelle.

## Installation après revue

1. Garder le bot V2.1 existant sur `main` sans changement. Ajouter les six fichiers de cette extension, dont son état initial `{}` et son workflow séparé.
2. Créer un compte Alpaca donnant accès à **Market Data** avec la source `iex`. Ajouter ses identifiants dans GitHub **Settings → Secrets and variables → Actions** sous les noms **`ALPACA_KEY_ID`** et **`ALPACA_SECRET_KEY`**. Ne jamais les copier dans du code, un commit ou un chat.
3. Réutiliser `FMP_API_KEY`, `TG_BOT_TOKEN` et `TG_CHAT_ID` existants. Vérifier qu'ils sont encore valables et que le plan FMP autorise `biggest-gainers`.
4. Lancer d'abord `php tests/overnight_test.php` (aucun accès réseau), puis effectuer une première séance en test manuel via `workflow_dispatch` pendant une phase suivie. Les sessions sans transaction IEX suffisamment récente peuvent n'envoyer aucune alerte : cela ne prouve pas l'absence de mouvements.
5. Pour l'automatiser **seulement après validation**, créer la variable GitHub Actions `OVERNIGHT_ENABLED=true`. Sinon, les déclenchements `schedule` sont **désactivés au niveau du job**.
6. Observer les résultats pendant une à deux semaines. Une fois l'intérêt vérifié, envisager un flux SIP consolidé ou un abonnement adapté pour une meilleure couverture, puis effectuer des backtests sans biais de sélection.

## Calendrier GitHub et limites

Le cron est basé sur `America/New_York` : GitHub gère l'heure d'été. Il cible 7:07, 7:22, 7:37, 7:52, etc. dans les fenêtres 7–10 h et 14–19 h ET, sans certitude sur la minute réelle de lancement. Les cours sont toujours contrôlés pour leur fraîcheur.

Les marchés peuvent être fermés les jours fériés, ouverts plus tôt que notre surveillance prémarket, ou clôturer exceptionnellement à 13 h ET. **Le prototype ne gère pas encore correctement toutes ces exceptions.** Ce module est donc un prototype de recherche et de test, non une surveillance suffisante pour gérer un portefeuille réel.

## Tests et provenance technique

- `php -l overnight_momentum.php`
- `php tests/overnight_test.php` (21 assertions)
- Schéma Alpaca `/v2/stocks/snapshots?symbols=...&feed=iex` documenté dans https://docs.alpaca.markets/us/reference/stocksnapshots-1
- Horaires pré- et post-marché documentés dans https://www.nasdaq.com/market-activity/stock-market-holiday-schedule
- Plan Basic IEX : https://docs.alpaca.markets/us/docs/about-market-data-api
