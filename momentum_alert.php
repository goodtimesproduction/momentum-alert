<?php
/**
 * momentum_alert.php  —  V2.1 (radar marche + filtre qualite + suivi revente)
 * -------------------------------------------------------------
 * DETECTION (entree) :
 *   - US  : FMP /biggest-gainers (1 appel) -> FILTRE QUALITE -> candidats.
 *   - EU  : watchlist optionnelle -> hausse sur N jours via Twelve Data.
 * SUIVI (revente) :
 *   - Position en RIDING : prix en direct (Twelve Data), suivi du pic,
 *     alerte VEND si repli >= TRAILING_STOP.
 *
 * Cles via Secrets GitHub : TD_API_KEY, FMP_API_KEY, TG_BOT_TOKEN, TG_CHAT_ID
 * Etat dans state.json.
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

// --- Detection US (FMP) : zone de hausse + filtre qualite ---
const GAINER_THRESHOLD = 15.0;   // hausse du jour MIN pour alerter
const MAX_GAINER_PCT   = 60.0;   // hausse du jour MAX (au-dessus = pump deja explose)
const MIN_PRICE        = 10.0;   // sous ce prix = penny stock -> ignore
// Mots-cles a exclure dans le NOM du titre (warrants, SPAC, ETF a levier...)
const NAME_BLOCKLIST = [
    'warrant', 'unit', 'right', 'acquisition corp', 'merger corp',
    '2x', '3x', '-1x', 'short', 'leveraged', 'bear', 'inverse',
];

// --- Detection EU (watchlist optionnelle) ---
// ['symbol'=>'MC','mic_code'=>'XPAR'] ... XPAR=Paris XAMS=Amsterdam XETR=Francfort XMIL=Milan XLON=Londres
const EU_WATCHLIST = [
    // ['symbol' => 'MC',   'mic_code' => 'XPAR'],  // LVMH
    // ['symbol' => 'ASML', 'mic_code' => 'XAMS'],  // ASML
];
const ENTRY_GAIN_PCT_EU = 30.0;  // hausse sur N jours pour les titres EU
const LOOKBACK_DAYS     = 5;

// --- Suivi / revente (commun) ---
const TRAILING_STOP = 12.0;      // % de repli depuis le pic -> "VEND"
const REQ_SLEEP_SEC = 8;         // pause entre appels Twelve Data

const STATE_PATH = __DIR__ . '/state.json';

if (TD_API_KEY === '' || FMP_API_KEY === '' || TG_BOT_TOKEN === '' || TG_CHAT_ID === '') {
    fwrite(STDERR, "Cles manquantes (TD_API_KEY / FMP_API_KEY / TG_BOT_TOKEN / TG_CHAT_ID).\n");
    exit(1);
}

// ============================================================
// 2) ETAT
// ============================================================
function loadAllState(): array {
    if (!is_file(STATE_PATH)) return [];
    $d = json_decode(file_get_contents(STATE_PATH) ?: '[]', true);
    return is_array($d) ? $d : [];
}
function saveAllState(array $s): void {
    file_put_contents(STATE_PATH, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ============================================================
// 3) HTTP
// ============================================================
function httpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r === false ? null : $r;
}

// ============================================================
// 4) DETECTION US — FMP biggest gainers + FILTRE QUALITE
// ============================================================
function fetchTopGainers(): array {
    $resp = httpGet('https://financialmodelingprep.com/stable/biggest-gainers?apikey=' . FMP_API_KEY);
    if ($resp === null) { fwrite(STDERR, "[FMP] pas de reponse\n"); return []; }
    $data = json_decode($resp, true);
    if (!is_array($data) || isset($data['Error Message']) || isset($data['error'])) {
        fwrite(STDERR, "[FMP] erreur : " . ($data['Error Message'] ?? $data['error'] ?? 'invalide') . "\n");
        return [];
    }
    return $data;
}

function parsePct($v): float {
    return (float) str_replace(['%', '+', ' '], '', (string) $v);
}

// Vrai signal ? Filtre prix + zone de hausse + nom non junk
function isQualityGainer(array $g): bool {
    $price = (float) ($g['price'] ?? 0);
    $pct   = parsePct($g['changesPercentage'] ?? 0);

    if ($price < MIN_PRICE)         return false;  // penny stock
    if ($pct < GAINER_THRESHOLD)    return false;  // pas assez
    if ($pct > MAX_GAINER_PCT)      return false;  // pump deja explose

    $name = strtolower($g['name'] ?? '');
    foreach (NAME_BLOCKLIST as $bad) {
        if (str_contains($name, $bad)) return false;  // warrant / SPAC / ETF levier...
    }
    return true;
}

// ============================================================
// 5) PRIX EN DIRECT — Twelve Data /price (suivi)
// ============================================================
function fetchPrice(string $symbol, ?string $mic): ?float {
    $p = ['symbol' => $symbol, 'apikey' => TD_API_KEY];
    if ($mic) $p['mic_code'] = $mic;
    $resp = httpGet('https://api.twelvedata.com/price?' . http_build_query($p));
    if ($resp === null) return null;
    $d = json_decode($resp, true);
    if (!isset($d['price'])) {
        $label = $mic ? "$symbol ($mic)" : $symbol;
        fwrite(STDERR, "[$label] prix indispo : " . ($d['message'] ?? 'n/a') . "\n");
        return null;
    }
    return (float) $d['price'];
}

// ============================================================
// 6) HISTORIQUE — Twelve Data (detection EU)
// ============================================================
function fetchCloses(string $symbol, ?string $mic, int $days): ?array {
    $p = ['symbol' => $symbol, 'interval' => '1day', 'outputsize' => $days + 1, 'apikey' => TD_API_KEY];
    if ($mic) $p['mic_code'] = $mic;
    $resp = httpGet('https://api.twelvedata.com/time_series?' . http_build_query($p));
    if ($resp === null) return null;
    $d = json_decode($resp, true);
    if (!isset($d['values']) || !is_array($d['values'])) return null;
    return array_map(fn($v) => (float) $v['close'], $d['values']);
}

// ============================================================
// 7) TELEGRAM
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

function maybeEnter(string $symbol, ?string $mic, float $price, float $pct, array &$allState): void {
    $key = stateKey($symbol, $mic);
    if (($allState[$key]['state'] ?? 'IDLE') !== 'IDLE') return;
    $label = $mic ? "$symbol ($mic)" : $symbol;
    $sent = sendTelegram(sprintf(
        "\xF0\x9F\x9A\x80 <b>%s</b> devient interessante\n".
        "Hausse <b>+%.1f%%</b>\nPrix : <b>%.2f</b>\n".
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

// (A) US via FMP : 1 appel, puis filtre qualite
foreach (fetchTopGainers() as $g) {
    $sym = $g['symbol'] ?? '';
    if ($sym === '' || !isQualityGainer($g)) continue;
    maybeEnter($sym, null, (float) $g['price'], parsePct($g['changesPercentage'] ?? 0), $allState);
}

// (B) EU via watchlist (Twelve Data, N jours)
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

// (C) Suivi des positions en RIDING (US + EU)
foreach (array_keys($allState) as $key) {
    if (($allState[$key]['state'] ?? '') !== 'RIDING') continue;
    trackRiding($key, $allState);
    sleep(REQ_SLEEP_SEC);
}

saveAllState($allState);
