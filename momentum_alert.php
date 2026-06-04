<?php
/**
 * momentum_alert.php  —  V2 (radar marche + suivi revente)
 * -------------------------------------------------------------
 * DETECTION (entree) :
 *   - US  : FMP /biggest-gainers -> plus fortes hausses du jour (1 appel).
 *   - EU  : watchlist optionnelle -> hausse sur N jours via Twelve Data.
 * SUIVI (revente) :
 *   - Pour chaque position en RIDING : prix en direct via Twelve Data,
 *     suivi du pic et alerte VEND si repli >= TRAILING_STOP.
 *
 * Cles via Secrets GitHub : TD_API_KEY, FMP_API_KEY, TG_BOT_TOKEN, TG_CHAT_ID
 * Etat dans state.json (commite dans le depot).
 * -------------------------------------------------------------
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

// ============================================================
// 1) CONFIG
// ============================================================
define('TD_API_KEY',   getenv('TD_API_KEY')   ?: '');
define('FMP_API_KEY',  getenv('FMP_API_KEY')  ?: '');
define('TG_BOT_TOKEN', getenv('TG_BOT_TOKEN') ?: '');
define('TG_CHAT_ID',   getenv('TG_CHAT_ID')   ?: '');

// --- Detection US (FMP) ---
const GAINER_THRESHOLD = 25.0;   // hausse du jour min pour alerter (10=sensible, 30=rare)
const MIN_PRICE        = 5.0;    // ignore les penny stocks sous ce prix (anti-bruit)

// --- Detection EU (watchlist optionnelle) ---
// Laisse vide [] si tu ne veux que les US. Sinon : ['symbol'=>'MC','mic_code'=>'XPAR']
// Codes : XPAR=Paris, XAMS=Amsterdam, XBRU=Bruxelles, XETR=Francfort, XMIL=Milan, XMAD=Madrid, XLON=Londres
const EU_WATCHLIST = [
    // ['symbol' => 'MC',   'mic_code' => 'XPAR'],  // LVMH
    // ['symbol' => 'ASML', 'mic_code' => 'XAMS'],  // ASML
];
const ENTRY_GAIN_PCT_EU = 30.0;  // hausse sur N jours pour les titres EU
const LOOKBACK_DAYS     = 5;     // fenetre EU (jours de bourse)

// --- Suivi / revente (commun US + EU) ---
const TRAILING_STOP = 12.0;      // % de repli depuis le pic -> "VEND"
const REQ_SLEEP_SEC = 8;         // pause entre appels Twelve Data (free ~8/min)

const STATE_PATH = __DIR__ . '/state.json';

if (TD_API_KEY === '' || FMP_API_KEY === '' || TG_BOT_TOKEN === '' || TG_CHAT_ID === '') {
    fwrite(STDERR, "Cles manquantes (TD_API_KEY / FMP_API_KEY / TG_BOT_TOKEN / TG_CHAT_ID).\n");
    exit(1);
}

// ============================================================
// 2) ETAT (JSON)
// ============================================================
function loadAllState(): array {
    if (!is_file(STATE_PATH)) return [];
    $data = json_decode(file_get_contents(STATE_PATH) ?: '[]', true);
    return is_array($data) ? $data : [];
}
function saveAllState(array $s): void {
    file_put_contents(STATE_PATH, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ============================================================
// 3) HTTP helper
// ============================================================
function httpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp === false ? null : $resp;
}

// ============================================================
// 4) DETECTION US — FMP biggest gainers (1 appel)
// ============================================================
function fetchTopGainers(): array {
    $resp = httpGet('https://financialmodelingprep.com/stable/biggest-gainers?apikey=' . FMP_API_KEY);
    if ($resp === null) { fwrite(STDERR, "[FMP] pas de reponse\n"); return []; }
    $data = json_decode($resp, true);
    if (!is_array($data) || isset($data['Error Message']) || isset($data['error'])) {
        $msg = $data['Error Message'] ?? $data['error'] ?? 'reponse invalide';
        fwrite(STDERR, "[FMP] erreur : $msg\n");
        return [];
    }
    return $data; // [{symbol, name, price, change, changesPercentage, exchange}, ...]
}

function parsePct($v): float {
    return (float) str_replace(['%', '+', ' '], '', (string) $v);
}

// ============================================================
// 5) PRIX EN DIRECT — Twelve Data /price (suivi des positions)
// ============================================================
function fetchPrice(string $symbol, ?string $mic): ?float {
    $params = ['symbol' => $symbol, 'apikey' => TD_API_KEY];
    if ($mic) $params['mic_code'] = $mic;
    $resp = httpGet('https://api.twelvedata.com/price?' . http_build_query($params));
    if ($resp === null) return null;
    $data = json_decode($resp, true);
    if (!isset($data['price'])) {
        $label = $mic ? "$symbol ($mic)" : $symbol;
        fwrite(STDERR, "[$label] prix indispo : " . ($data['message'] ?? 'n/a') . "\n");
        return null;
    }
    return (float) $data['price'];
}

// ============================================================
// 6) HISTORIQUE — Twelve Data (detection EU sur N jours)
// ============================================================
function fetchCloses(string $symbol, ?string $mic, int $days): ?array {
    $params = ['symbol' => $symbol, 'interval' => '1day', 'outputsize' => $days + 1, 'apikey' => TD_API_KEY];
    if ($mic) $params['mic_code'] = $mic;
    $resp = httpGet('https://api.twelvedata.com/time_series?' . http_build_query($params));
    if ($resp === null) return null;
    $data = json_decode($resp, true);
    if (!isset($data['values']) || !is_array($data['values'])) return null;
    return array_map(fn($v) => (float) $v['close'], $data['values']);
}

// ============================================================
// 7) NOTIFICATION (Telegram) — true si le message part
// ============================================================
function sendTelegram(string $text): bool {
    $ch = curl_init('https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => ['chat_id' => TG_CHAT_ID, 'text' => $text, 'parse_mode' => 'HTML'],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fwrite(STDOUT, "[Telegram] HTTP $http | reponse=$resp\n");
    $d = json_decode($resp ?: '', true);
    return $http === 200 && is_array($d) && ($d['ok'] ?? false) === true;
}

// ============================================================
// 8) MACHINE A ETATS
// ============================================================
function stateKey(string $symbol, ?string $mic): string {
    return $mic ? "$symbol.$mic" : $symbol;
}

// Entree : passe en RIDING si on etait IDLE et si l'alerte part
function maybeEnter(string $symbol, ?string $mic, float $price, float $pct, array &$allState): void {
    $key = stateKey($symbol, $mic);
    if (($allState[$key]['state'] ?? 'IDLE') !== 'IDLE') return; // deja suivi
    $label = $mic ? "$symbol ($mic)" : $symbol;
    $sent = sendTelegram(sprintf(
        "\xF0\x9F\x9A\x80 <b>%s</b> devient interessante\n".
        "Hausse <b>+%.1f%%</b>\n".
        "Prix : <b>%.2f</b>\n".
        "\xF0\x9F\x91\x89 Suivi du repli active (sortie si -%.0f%% du pic).",
        $label, $pct, $price, TRAILING_STOP
    ));
    if ($sent) {
        $allState[$key] = [
            'state' => 'RIDING', 'symbol' => $symbol, 'mic' => $mic,
            'entry_px' => $price, 'peak_px' => $price, 'updated_at' => date('c'),
        ];
    }
}

// Suivi : met a jour le pic, alerte VEND si repli, repasse en IDLE
function trackRiding(string $key, array &$allState): void {
    $s = $allState[$key];
    $price = fetchPrice($s['symbol'], $s['mic'] ?? null);
    if ($price === null) return;

    $peak = max((float) $s['peak_px'], $price);
    $drop = (($peak - $price) / $peak) * 100.0;
    $label = ($s['mic'] ?? null) ? "{$s['symbol']} ({$s['mic']})" : $s['symbol'];

    if ($drop >= TRAILING_STOP) {
        $entry = (float) $s['entry_px'];
        $pl = (($price - $entry) / $entry) * 100.0;
        $sent = sendTelegram(sprintf(
            "\xF0\x9F\x94\xBB <b>%s</b> — VEND\n".
            "Repli de <b>-%.1f%%</b> depuis le pic (%.2f -> %.2f)\n".
            "Resultat depuis l'alerte : <b>%+.1f%%</b>",
            $label, $drop, $peak, $price, $pl
        ));
        if ($sent) {
            $allState[$key] = [
                'state' => 'IDLE', 'symbol' => $s['symbol'], 'mic' => $s['mic'] ?? null,
                'entry_px' => null, 'peak_px' => null, 'updated_at' => date('c'),
            ];
        }
    } else {
        $allState[$key]['peak_px'] = $peak;
        $allState[$key]['updated_at'] = date('c');
    }
}

// ============================================================
// 9) ORCHESTRATION
// ============================================================
$allState = loadAllState();

// --- (A) Detection US via FMP : 1 seul appel ---
foreach (fetchTopGainers() as $g) {
    $sym = $g['symbol'] ?? '';
    if ($sym === '') continue;
    $pct   = parsePct($g['changesPercentage'] ?? 0);
    $price = (float) ($g['price'] ?? 0);
    if ($pct >= GAINER_THRESHOLD && $price >= MIN_PRICE) {
        maybeEnter($sym, null, $price, $pct, $allState);
    }
}

// --- (B) Detection EU via watchlist (Twelve Data, N jours) ---
foreach (EU_WATCHLIST as $e) {
    $closes = fetchCloses($e['symbol'], $e['mic_code'] ?? null, LOOKBACK_DAYS);
    if ($closes && count($closes) >= 2) {
        $cur = $closes[0]; $base = end($closes);
        if ($base > 0) {
            $pct = (($cur - $base) / $base) * 100.0;
            if ($pct >= ENTRY_GAIN_PCT_EU) {
                maybeEnter($e['symbol'], $e['mic_code'] ?? null, $cur, $pct, $allState);
            }
        }
    }
    sleep(REQ_SLEEP_SEC);
}

// --- (C) Suivi de TOUTES les positions en RIDING (US + EU) ---
foreach (array_keys($allState) as $key) {
    if (($allState[$key]['state'] ?? '') !== 'RIDING') continue;
    trackRiding($key, $allState);
    sleep(REQ_SLEEP_SEC);
}

saveAllState($allState);
