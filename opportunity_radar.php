<?php
/**
 * Opportunity Radar V2.2: conservative, end-of-day correction watch.
 *
 * Research signals only. No brokerage integration or trading orders.
 * A curated watchlist is NOT a substitute for financial due diligence.
 */
declare(strict_types=1);
date_default_timezone_set('UTC');

const OP_STATE = __DIR__ . '/opportunity_state.json';
const OP_WATCHLIST = __DIR__ . '/opportunity_watchlist.json';
const OP_MIN_PRICE = 10.0;
const OP_MIN_DOLLAR_VOLUME = 20000000.0;
const OP_MIN_DRAWDOWN = 15.0;
const OP_MAX_DRAWDOWN = 45.0;
const OP_MAX_ITEMS = 15;
const OP_MAX_NOTIFICATIONS = 4;

function opPercent(float $new, float $old): float {
    return $old > 0.0 ? ($new / $old - 1.0) * 100.0 : -100.0;
}

function opSymbol(string $symbol): bool {
    return preg_match('/^[A-Z][A-Z0-9.\-]{0,11}$/D', $symbol) === 1;
}

/** @return array<string,mixed>|null */
function opAnalyze(array $raw, DateTimeImmutable $now): ?array {
    $bars = [];
    foreach ($raw as $item) {
        if (!is_array($item)) continue;
        $date = (string)($item['date'] ?? '');
        $close = (float)($item['close'] ?? 0);
        $high = (float)($item['high'] ?? 0);
        $low = (float)($item['low'] ?? 0);
        $volume = (float)($item['volume'] ?? 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ||
            !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)) ||
            !is_finite($close) || !is_finite($high) || !is_finite($low) || !is_finite($volume) ||
            $close <= 0 || $low <= 0 || $close < $low || $high < max($low, $close) || $volume <= 0) continue;
        $bars[$date] = ['date'=>$date,'close'=>$close,'high'=>$high,'low'=>$low,'volume'=>$volume];
    }
    krsort($bars);
    $bars = array_values($bars);
    if (count($bars) < 60) return null;
    $localNow = $now->setTimezone(new DateTimeZone('America/New_York'));
    $today = $localNow->format('Y-m-d');
    $age = (int)(new DateTimeImmutable($bars[0]['date'] . ' 00:00:00', new DateTimeZone('America/New_York')))
        ->diff(new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('America/New_York')))->format('%r%a');
    if ($age < 0 || $age > 4) return null; // stale/future EOD data must never look current
    $latest = $bars[0];
    if ($latest['close'] < OP_MIN_PRICE) return null;

    $high60 = max(array_column(array_slice($bars, 0, 60), 'high'));
    $drawdown = -opPercent($latest['close'], $high60);
    $previous20 = array_slice($bars, 1, 20);
    $turnover = array_sum(array_map(
        static fn(array $b): float => $b['close'] * $b['volume'], $previous20
    )) / count($previous20);
    $previousVolume = array_sum(array_column($previous20, 'volume')) / count($previous20);
    $volumeRatio = $previousVolume > 0 ? $latest['volume'] / $previousVolume : 0.0;
    $ma5 = array_sum(array_column(array_slice($bars, 0, 5), 'close')) / 5;
    $prevClose = $bars[1]['close'];
    $rangePosition = $latest['high'] > $latest['low']
        ? ($latest['close'] - $latest['low']) / ($latest['high'] - $latest['low']) : 0.0;
    $rebound = $latest['close'] >= $prevClose * 1.02
        && $latest['close'] > $ma5 && $volumeRatio >= 1.20 && $rangePosition >= 0.65;

    return [
        'date'=>$latest['date'], 'close'=>$latest['close'],
        'drawdown'=>$drawdown, 'high60'=>$high60, 'dollar_volume'=>$turnover,
        'volume_ratio'=>$volumeRatio, 'ma5'=>$ma5,
        'day_change'=>opPercent($latest['close'], $prevClose),
        'rebound'=>$rebound,
        'candidate'=>$drawdown >= OP_MIN_DRAWDOWN && $drawdown <= OP_MAX_DRAWDOWN
            && $turnover >= OP_MIN_DOLLAR_VOLUME,
    ];
}

function opDecide(array $analysis, array &$record, string $today): ?string {
    if (!$analysis['candidate']) {
        if ($analysis['drawdown'] < 10.0) $record = [];
        return null;
    }
    // A rebound may follow a previously notified watch signal; send each phase once.
    if ($analysis['rebound'] && !($record['rebound_sent'] ?? false)) return 'REBOUND';
    if (!isset($record['watch_date']) ||
        (strtotime($today) - strtotime((string)$record['watch_date'])) >= 14 * 86400) {
        return 'WATCH';
    }
    return null;
}

function opMarkSent(array &$record, string $kind, string $today): void {
    if ($kind === 'REBOUND') $record['rebound_sent'] = true;
    if ($kind === 'WATCH') $record['watch_date'] = $today;
    $record['last_sent'] = $today;
    $record['last_kind'] = $kind;
}

function opMarketRegime(array $raw, DateTimeImmutable $now): string {
    $data = opAnalyze($raw, $now);
    if ($data === null) return 'non déterminé (SPY indisponible)';
    return $data['close'] >= $data['ma5'] ? 'SPY au-dessus de sa MM5' : 'SPY sous sa MM5, prudence';
}

function opMessage(string $symbol, array $a, string $kind, string $regime): string {
    $heading = $kind === 'REBOUND'
        ? '🟢 OPPORTUNITY | REBOND TECHNIQUE'
        : '🔎 OPPORTUNITY | CORRECTION À SURVEILLER';
    $s = htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<b>' . $heading . ': ' . $s . '</b>'
        . "\nDonnées <b>fin de séance " . $a['date'] . "</b> (pas de cotation instantanée)"
        . "\nClôture : <b>" . number_format($a['close'], 2, '.', '') . " USD</b>"
        . "\nRepli par rapport au plus haut des 60 dernières séances : <b>-"
        . number_format($a['drawdown'], 1, '.', '') . "%</b>"
        . "\nVariation séance : " . sprintf('%+.1f', $a['day_change']) . '%'
        . ' | Volume / 20j : ' . number_format($a['volume_ratio'], 2, '.', '') . 'x'
        . "\nContexte : " . htmlspecialchars($regime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . "\n<b>Statut : " . ($kind === 'REBOUND' ? 'rebond à vérifier' : 'observation, pas de confirmation') . '</b>'
        . "\nListe de suivi choisie manuellement. Aucune analyse fondamentale automatique."
        . "\nCe n'est pas une recommandation d'achat. Vérifier résultats, dette et actualité.";
}

function opApi(string $symbol, string $apiKey, DateTimeImmutable $now): array {
    $from = $now->modify('-120 days')->format('Y-m-d');
    $url = 'https://financialmodelingprep.com/stable/historical-price-eod/full?'
        . http_build_query(['symbol'=>$symbol, 'from'=>$from, 'to'=>$now->format('Y-m-d'), 'apikey'=>$apiKey]);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>25,
        CURLOPT_USERAGENT=>'MomentumAlert/2.2 (opportunity research)'
    ]);
    $body = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $code !== 200) throw new RuntimeException("FMP HTTP $code for $symbol");
    $data = json_decode($body, true);
    if (!is_array($data) || !array_is_list($data)) throw new RuntimeException("FMP unusable history for $symbol");
    return $data;
}

function opSend(string $token, string $chatId, string $message): bool {
    $curl = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($curl, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15,
        CURLOPT_POSTFIELDS=>['chat_id'=>$chatId, 'text'=>$message, 'parse_mode'=>'HTML']
    ]);
    $body = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $result = json_decode(is_string($body) ? $body : '', true);
    return $code === 200 && is_array($result) && ($result['ok'] ?? false) === true;
}

function opLoadJson(string $path): array {
    if (!is_file($path)) throw new RuntimeException("Missing data file " . basename($path));
    $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException("Invalid data file " . basename($path));
    return $value;
}

function opSaveState(array $state): void {
    $file = tempnam(__DIR__, '.opportunity-');
    if (!$file) throw new RuntimeException('Cannot save opportunity state');
    try {
        if (file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
            || !rename($file, OP_STATE)) throw new RuntimeException('Cannot persist opportunity state');
    } finally {
        if (is_file($file)) unlink($file);
    }
}

function opMain(): void {
    $key = getenv('FMP_API_KEY') ?: '';
    $token = getenv('TG_BOT_TOKEN') ?: '';
    $chat = getenv('TG_CHAT_ID') ?: '';
    if (!$key || !$token || !$chat) throw new RuntimeException('FMP/Telegram environment variables missing');
    $symbols = opLoadJson(OP_WATCHLIST);
    $state = opLoadJson(OP_STATE);
    if (!array_is_list($symbols) || count($symbols) > OP_MAX_ITEMS) throw new RuntimeException('Invalid opportunity watchlist');
    $symbols = array_values(array_unique($symbols));
    foreach ($symbols as $symbol) {
        if (!is_string($symbol) || !opSymbol($symbol)) throw new RuntimeException('Invalid opportunity symbol');
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $today = $now->format('Y-m-d');
    // EOD only: never classify unfinished same-day candles as confirmed closes.
    if ((int)$now->format('Hi') < 1630 || (int)$now->format('N') > 5) {
        echo "Outside EOD processing window; no alerts\n";
        return;
    }
    $regime = 'non déterminé (SPY indisponible)';
    try { $regime = opMarketRegime(opApi('SPY', $key, $now), $now); }
    catch (Throwable $e) { fwrite(STDERR, "[SPY] " . $e->getMessage() . "\n"); }
    $count = ['watched'=>count($symbols), 'usable'=>0, 'candidates'=>0, 'sent'=>0, 'errors'=>0];
    foreach ($symbols as $symbol) {
        try {
            $a = opAnalyze(opApi($symbol, $key, $now), $now);
            if ($a === null || $a['date'] !== $today) continue;
            $count['usable']++;
            if ($a['candidate']) $count['candidates']++;
            $record = $state[$symbol] ?? [];
            if (!is_array($record)) $record = [];
            $kind = opDecide($a, $record, $today);
            if (!$kind || $count['sent'] >= OP_MAX_NOTIFICATIONS) {
                $state[$symbol] = $record;
                continue;
            }
            if (!opSend($token, $chat, opMessage($symbol, $a, $kind, $regime))) {
                $count['errors']++;
                fwrite(STDERR, "Telegram delivery failure for $symbol\n");
                continue;
            }
            opMarkSent($record, $kind, $today);
            $state[$symbol] = $record;
            $count['sent']++;
        } catch (Throwable $e) {
            $count['errors']++;
            fwrite(STDERR, "[$symbol] " . $e->getMessage() . "\n");
        }
    }
    opSaveState($state);
    echo json_encode($count, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if ($count['errors'] > 0) throw new RuntimeException('One or more radar checks failed (see sanitized logs)');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { opMain(); }
    catch (Throwable $e) { fwrite(STDERR, "[OPPORTUNITY] " . $e->getMessage() . "\n"); exit(1); }
}
