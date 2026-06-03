<?php
/**
 * momentum_alert.php  —  version GitHub Actions
 * -------------------------------------------------------------
 * Différences vs version PC :
 *   - Les clés sont lues dans les variables d'environnement
 *     (GitHub Secrets), jamais en dur dans le code.
 *   - L'état est stocké dans state.json (commité dans le dépôt),
 *     car les runners GitHub sont éphémères.
 * -------------------------------------------------------------
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

// ============================================================
// 1) CONFIG
// ============================================================
// Clés : lues depuis l'environnement (Secrets GitHub).
// En local tu peux faire : TD_API_KEY=xxx php momentum_alert.php
define('TD_API_KEY',   getenv('TD_API_KEY')   ?: '');
define('TG_BOT_TOKEN', getenv('TG_BOT_TOKEN') ?: '');
define('TG_CHAT_ID',   getenv('TG_CHAT_ID')   ?: '');

// Tickers tradables sur Revolut à surveiller.
const WATCHLIST = [
    'AAPL', 'TSLA', 'NVDA', 'PLTR', 'AMD',
    'SOXL', 'TQQQ',
    // 'AIR:Euronext Paris',
];

// Paramètres de signal
const ENTRY_GAIN_PCT  = 35.0;   // hausse min pour l'alerte d'entrée
const LOOKBACK_DAYS   = 5;      // fenêtre de mesure (jours de bourse)
const TRAILING_STOP   = 12.0;   // % de repli depuis le pic -> "VEND"
const REQ_SLEEP_SEC   = 8;      // pause entre appels (free ~8 req/min)

const STATE_PATH = __DIR__ . '/state.json';

// Garde-fou : pas de clés -> on arrête proprement.
if (TD_API_KEY === '' || TG_BOT_TOKEN === '' || TG_CHAT_ID === '') {
    fwrite(STDERR, "Cles manquantes (TD_API_KEY / TG_BOT_TOKEN / TG_CHAT_ID).\n");
    exit(1);
}

// ============================================================
// 2) ETAT (fichier JSON)
// ============================================================
function loadAllState(): array {
    if (!is_file(STATE_PATH)) return [];
    $raw = file_get_contents(STATE_PATH);
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function saveAllState(array $state): void {
    file_put_contents(
        STATE_PATH,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

// ============================================================
// 3) DONNEES DE MARCHE (Twelve Data)
// ============================================================
function fetchCloses(string $symbol, int $days): ?array {
    $url = 'https://api.twelvedata.com/time_series?' . http_build_query([
        'symbol'     => $symbol,
        'interval'   => '1day',
        'outputsize' => $days + 1,
        'apikey'     => TD_API_KEY,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) return null;

    $data = json_decode($resp, true);
    if (!isset($data['values']) || !is_array($data['values'])) {
        $msg = $data['message'] ?? 'reponse invalide';
        fwrite(STDERR, "[$symbol] erreur API : $msg\n");
        return null;
    }
    // values[0] = jour le plus recent
    return array_map(fn($v) => (float) $v['close'], $data['values']);
}

// ============================================================
// 4) NOTIFICATION (Telegram)
// ============================================================
function sendTelegram(string $text): void {
    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => [
            'chat_id'    => TG_CHAT_ID,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ============================================================
// 5) MOTEUR — machine a etats par symbole
// ============================================================
function processSymbol(string $symbol, array &$allState): void {
    $closes = fetchCloses($symbol, LOOKBACK_DAYS);
    if ($closes === null || count($closes) < 2) return;

    $current = $closes[0];
    $base    = end($closes);
    if ($base <= 0) return;

    $gainPct = (($current - $base) / $base) * 100.0;
    $s = $allState[$symbol] ?? ['state' => 'IDLE', 'entry_px' => null, 'peak_px' => null];

    switch ($s['state']) {

        case 'IDLE':
            if ($gainPct >= ENTRY_GAIN_PCT) {
                $allState[$symbol] = [
                    'state' => 'RIDING', 'entry_px' => $current,
                    'peak_px' => $current, 'updated_at' => date('c'),
                ];
                sendTelegram(sprintf(
                    "\xF0\x9F\x9A\x80 <b>%s</b> devient interessante\n".
                    "Hausse <b>+%.1f%%</b> sur %d jours\n".
                    "Prix actuel : <b>%.2f</b>\n".
                    "\xF0\x9F\x91\x89 Surveillance du repli activee (sortie si -%.0f%% du pic).",
                    $symbol, $gainPct, LOOKBACK_DAYS, $current, TRAILING_STOP
                ));
            }
            break;

        case 'RIDING':
            $peak = max((float) $s['peak_px'], $current);
            $dropFromPeak = (($peak - $current) / $peak) * 100.0;

            if ($dropFromPeak >= TRAILING_STOP) {
                $entry = (float) $s['entry_px'];
                $pl    = (($current - $entry) / $entry) * 100.0;
                $allState[$symbol] = [
                    'state' => 'IDLE', 'entry_px' => null,
                    'peak_px' => null, 'updated_at' => date('c'),
                ];
                sendTelegram(sprintf(
                    "\xF0\x9F\x94\xBB <b>%s</b> — VEND\n".
                    "Repli de <b>-%.1f%%</b> depuis le pic (%.2f -> %.2f)\n".
                    "Resultat depuis l'alerte : <b>%+.1f%%</b>",
                    $symbol, $dropFromPeak, $peak, $current, $pl
                ));
            } else {
                $allState[$symbol]['peak_px']    = $peak;
                $allState[$symbol]['updated_at'] = date('c');
            }
            break;
    }
}

// ============================================================
// 6) BOUCLE PRINCIPALE
// ============================================================
$allState = loadAllState();

foreach (WATCHLIST as $i => $symbol) {
    processSymbol($symbol, $allState);
    if ($i < count(WATCHLIST) - 1) {
        sleep(REQ_SLEEP_SEC);
    }
}

saveAllState($allState);
