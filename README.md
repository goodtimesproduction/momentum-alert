# Momentum Alert V2.2

Trois radars indépendants pour Telegram. **Aucun ordre de trading.** Les états GitHub signalent un titre observé, pas une position détenue.

## 1. BREAKOUT | Forte hausse

- Source : FMP biggest-gainers (actions US) et Twelve Data (/price) pour le suivi.
- Déclencheur : hausse de **+15 % à +60 %** sur la séance, prix >= 10 USD et exclusions basiques de produits spéculatifs.
- Notification clairement intitulée « BREAKOUT ».
- Repli observé depuis le plus haut suivi >= 12 % : notification **REPLI À VÉRIFIER**, jamais « VEND ».
- Limite : au plus **8 suivis Twelve Data par exécution**, sélectionnés par ancienneté du dernier contrôle pour respecter les limites API. Avec 121 titres déjà suivis, la rotation peut durer plusieurs heures, notamment lorsque GitHub retarde les déclenchements. Ce n'est **pas** un stop-loss, pas un cours garanti frais, pas un système de gestion de risque en temps réel.
- Chaque notification de repli réarme une nouvelle recherche uniquement après un délai de 48 h si le titre redevient éligible.

## 2. MOMENTUM | Fin de séance / after-hours / prémarket / ouverture

- Sources : FMP pour la liste initiale et Alpaca **IEX**, flux à couverture US partielle.
- Quatre phases : close (+2 %), after-hours (+2,5 %), préouverture (gap >= 4 % et accélération >= 1 %), ouverture confirmée.
- Le suivi des préouvertures exige un signal préalable, avec horodatage, spread et volume IEX.
- Les seuils sont expérimentaux, **non optimisés** ni backtestés.
- L'IEX peut exclure des titres activement négociés ailleurs. Si aucun des titres n'a de données valides en after-hours, un **bilan technique** distinct peut être envoyé une seule fois par journée suivie. Le silence ne prouve jamais qu'aucune action ne monte.
- L'horaire GitHub Actions est indicatif : le planificateur peut démarrer très tard ou supprimer une exécution. Cela **ne garantit pas** une surveillance toutes les 15 minutes.

## 3. OPPORTUNITY | Corrections après clôture

- Univers limité à une liste manuelle dans opportunity_watchlist.json ; huit valeurs initiales (MSFT, NVDA, ASML, GOOGL, AMZN, META, AVGO, AMD). **Ce ne sont pas des conseils d'achat.**
- Source : FMP historical-price-eod/full, consolidé **uniquement après la clôture**. Le scanner requiert au moins 60 séances et une barre clôturée à la date de traitement NY.
- Signal WATCH : repli de **15 à 45 %** par rapport au plus haut des 60 dernières séances, prix >= 10 USD, moyenne 20 séances du volume en dollars >= 20 MUSD.
- Signal REBOUND : la condition WATCH **plus** clôture >= +2 % par rapport à la veille, cours > moyenne mobile 5 jours, volume journalier >= 1,2x la moyenne des 20 séances précédentes, clôture dans les 35 % supérieurs de sa fourchette du jour.
- État indépendant : opportunity_state.json ; déduplication quotidienne par titre ; rappel WATCH au maximum tous les 14 jours, REBOUND une fois tant que la correction ne s'est pas résorbée.
- Max 4 nouveaux signaux par exécution. À défaut de signal, un bilan quotidien décrit jusqu'à trois corrections parmi les valeurs surveillées si au moins trois cotations sont utilisables.
- SPY / MM5 fournit seulement un contexte de marché. **Aucun score de solidité financière n'est calculé.** Les bilans, la dette, le calendrier des résultats, la liquidité réelle et les actualités doivent être vérifiés avant toute décision.

## Exécution, coûts et confidentialité

- BREAKOUT : workflow momentum-alert.yml (historique state.json conservé).
- MOMENTUM : workflow overnight-momentum.yml (historique overnight_state.json conservé).
- OPPORTUNITY : workflow opportunity-radar.yml (après 16h47, heure New York, en semaine, par GitHub Actions).
- Tests : php -l et tests/overnight_test.php, tests/opportunity_test.php, via momentum-v22-tests.yml.
- Secrets GitHub Actions : FMP_API_KEY, TD_API_KEY, ALPACA_KEY_ID, ALPACA_SECRET_KEY, TG_BOT_TOKEN, TG_CHAT_ID, à fournir seulement dans les paramètres du dépôt. Ne jamais les versionner.
- Le droit d'accès de l'abonnement FMP au point historical-price-eod/full doit être **confirmé sur une exécution live**. Un code vert aux tests unitaires ne prouve pas l'accès à l'API.
- Les crédits API, les retards d'exécution GitHub et la couverture des bourses limitent la fiabilité. Les journaux de workflows doivent être contrôlés ; un statut GitHub « success » peut signifier simplement « aucune donnée utilisable ».
- Le projet n'accède à aucun courtier et ne connaît aucune transaction effectivement exécutée par l'utilisateur.
