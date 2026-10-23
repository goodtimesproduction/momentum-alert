<?php
/** Standalone, alert-only overnight radar. No broker API or trade execution. */
declare(strict_types=1);
date_default_timezone_set('UTC');

const OM_STATE = __DIR__ . '/overnight_state.json';
const OM_NY = 'America/New_York';
const OM_MIN_PRICE = 10.0;
const OM_MIN_VOLUME = 25; // IEX minute bar only; not consolidated market volume.
const OM_MAX_SPREAD_PERCENT = 1.5;
const OM_MAX_QUOTE_AGE = 600;
const OM_MAX_SYMBOLS = 60;

function omNy(DateTimeImmutable $now): DateTimeImmutable { return $now->setTimezone(new DateTimeZone(OM_NY)); }
function omPhase(DateTimeImmutable $now): string {
    $n = omNy($now); $hm = (int)$n->format('Hi');
    if ((int)$n->format('N') > 5) return 'off';
    if ($hm >= 1430 && $hm < 1600) return 'close';
    if ($hm >= 1600 && $hm < 2000) return 'after';
    if ($hm >= 700 && $hm < 930) return 'pre';
    if ($hm >= 930 && $hm < 1016) return 'open';
    return 'off';
}
function omPct(float $new, float $old): float { return $old > 0 ? ($new / $old - 1.0) * 100.0 : -100.0; }
function omSymbol(string $s): bool { return preg_match('/^[A-Z][A-Z0-9.\-]{0,12}$/D', $s) === 1; }
function omDt(string $str): ?DateTimeImmutable {
    try { return new DateTimeImmutable($str); } catch (Throwable $e) { return null; }
}
function omSnapshot(array $snapshot, DateTimeImmutable $now): ?array {
    $trade = $snapshot['latestTrade'] ?? [];
    $quote = $snapshot['latestQuote'] ?? [];
    $bar = $snapshot['minuteBar'] ?? [];
    $price = (float)($trade['p'] ?? 0);
    $bid = (float)($quote['bp'] ?? 0);
    $ask = (float)($quote['ap'] ?? 0);
    $ts = omDt((string)($trade['t'] ?? ''));
    $qt = omDt((string)($quote['t'] ?? ''));
    $bt = omDt((string)($bar['t'] ?? ''));
    if (!$ts || !$qt || !$bt || $price < OM_MIN_PRICE || $bid <= 0 || $ask < $bid) return null;
    foreach ([$ts, $qt, $bt] as $d) {
        $age = $now->getTimestamp() - $d->getTimestamp();
        if ($age < -30 || $age > OM_MAX_QUOTE_AGE) return null;
        if (omNy($d)->format('Y-m-d') !== omNy($now)->format('Y-m-d') || omPhase($d) !== omPhase($now)) return null;
    }
    $spread = omPct($ask, ($ask + $bid) / 2);
    $spread *= 2; // approximately full relative bid/ask spread in percent
    $volume = (int)($bar['v'] ?? 0);
    if ($spread > OM_MAX_SPREAD_PERCENT || $volume < OM_MIN_VOLUME) return null;
    return ['price'=>$price, 'spread'=>$spread, 'minute_volume'=>$volume, 'timestamp'=>$ts->format(DATE_ATOM)];
}
function omCloseAgeDays(string $closed, DateTimeImmutable $now): int {
    $n = omNy($now); $d = omDt($closed . ' 12:00:00 America/New_York');
    if (!$d) return 999;
    return (int)$d->setTime(0,0)->diff($n->setTime(0,0))->format('%r%a');
}
function omEvaluate(array &$row, string $phase, array $tick, DateTimeImmutable $now): array {
    $day = omNy($now)->format('Y-m-d'); $price = $tick['price'];
    if (!isset($row['signals']) || !is_array($row['signals'])) $row['signals'] = [];
    $out = [];
    if ($phase === 'close') {
        if (($row['close_date'] ?? '') !== $day) {
            $row = ['close_date'=>$day, 'early_price'=>$price, 'close_price'=>$price, 'signals'=>[]];
        }
        $row['close_price'] = $price;
        $base = (float)$row['early_price'];
        if (omPct($price, $base) >= 2.0 && !isset($row['signals']['close'])) {
            $out[] = ['phase'=>'close', 'gain'=>omPct($price,$base), 'reference'=>$base];
        }
    } elseif ($phase === 'after') {
        if (($row['close_date'] ?? '') !== $day || !isset($row['close_price'])) return [];
        $ref = (float)$row['close_price'];
        if (omPct($price, $ref) >= 2.5 && !isset($row['signals']['after'])) {
            $out[] = ['phase'=>'after', 'gain'=>omPct($price,$ref), 'reference'=>$ref];
        }
        $row['after_price'] = $price;
    } elseif ($phase === 'pre') {
        $age = omCloseAgeDays((string)($row['close_date'] ?? ''), $now);
        if ($age < 1 || $age > 4 || !isset($row['close_price'])) return [];
        if (($row['pre_date'] ?? '') !== $day) {
            $row['pre_date'] = $day; $row['pre_first'] = $price; $row['pre_peak'] = $price;
        }
        $row['pre_peak'] = max((float)$row['pre_peak'], $price);
        $gap = omPct($price, (float)$row['close_price']);
        $accel = omPct($price, (float)$row['pre_first']);
        $setup = isset($row['signals']['close']) || isset($row['signals']['after']);
        if ($setup && $gap >= 4.0 && $accel >= 1.0 && !isset($row['signals']['pre'])) {
            $out[] = ['phase'=>'pre', 'gain'=>$gap, 'acceleration'=>$accel, 'reference'=>$row['close_price']];
        }
    } elseif ($phase === 'open') {
        if (($row['pre_date'] ?? '') !== $day || !isset($row['signals']['pre'])) return [];
        if (!isset($row['open_first'])) $row['open_first'] = $price;
        $higherThanPremarket = omPct($price, (float)($row['pre_peak'] ?? 0));
        $openingAcceleration = omPct($price, (float)$row['open_first']);
        if ($higherThanPremarket >= 0.5 && $openingAcceleration >= 0.5 && !isset($row['signals']['open'])) {
            $out[] = ['phase'=>'open', 'gain'=>omPct($price,(float)$row['close_price']),
                'acceleration'=>$openingAcceleration, 'reference'=>$row['close_price']];
        }
    }
    return $out;
}
function omApi(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>22,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_USERAGENT=>'momentum-alert-overnight/0.1']);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException('API HTTP '.$code.' '.substr($err,0,80));
    $value = json_decode($body, true);
    if (!is_array($value)) throw new RuntimeException('Réponse JSON invalide');
    return $value;
}
function omNotify(string $token, string $chat, string $message): bool {
    $url = 'https://api.telegram.org/bot'.$token.'/sendMessage';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15,
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>['chat_id'=>$chat, 'text'=>$message, 'parse_mode'=>'HTML']]);
    $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($body ?: '',true);
    return $status === 200 && ($data['ok'] ?? false) === true;
}
function omRead(): array {
    if (!is_file(OM_STATE)) return [];
    $state = json_decode((string)file_get_contents(OM_STATE),true);
    if (!is_array($state)) throw new RuntimeException('overnight_state.json invalide, conservation de la version précédente');
    return $state;
}
function omSave(array $state): void {
    $tmp = tempnam(__DIR__,'.overnight-');
    if (!$tmp) throw new RuntimeException('Impossible de créer un fichier temporaire');
    $json = json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($tmp,$json) === false || !rename($tmp,OM_STATE)) throw new RuntimeException('Sauvegarde refusée');
}
function omMain(): void {
    $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $phase = omPhase($now);
    if ($phase === 'off') { echo "Hors fenêtre New York\n"; return; }
    $alpacaId = getenv('ALPACA_KEY_ID') ?: '';
    $alpacaSecret = getenv('ALPACA_SECRET_KEY') ?: '';
    $tg = getenv('TG_BOT_TOKEN') ?: '';
    $chat = getenv('TG_CHAT_ID') ?: '';
    if (!$alpacaId || !$alpacaSecret || !$tg || !$chat) throw new RuntimeException('Secrets Alpaca/Telegram absents; monitoring non activé');
    $state = omRead(); $day=omNy($now)->format('Y-m-d');
    $symbols=[];
    if ($phase === 'close') {
        $fmp = getenv('FMP_API_KEY') ?: '';
        if (!$fmp) throw new RuntimeException('FMP_API_KEY manquant');
        $list = omApi('https://financialmodelingprep.com/stable/biggest-gainers?apikey='.rawurlencode($fmp));
        if (!array_is_list($list)) throw new RuntimeException('FMP : liste des hausses invalide');
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $s = strtoupper((string)($item['symbol']??''));
            $p=(float)($item['price']??0);$gain=(float)($item['changesPercentage']??0);
            $name=strtolower((string)($item['name']??''));
            if (omSymbol($s) && $p>=OM_MIN_PRICE && $gain>=2.0 && $gain<=60.0 &&
                !preg_match('/warrant|acquisition corp|merger corp|leveraged|inverse|2x |3x |short etf/', $name)) $symbols[$s]=true;
        }
    }
    // Explicit universe addition for companies not present among the regular-session top gainers.
    $extraPath=__DIR__.'/overnight_watchlist.json';
    $extra=is_file($extraPath)?json_decode((string)file_get_contents($extraPath),true):[];
    if (!is_array($extra)) throw new RuntimeException('Watchlist overnight invalide');
    foreach ($extra as $s) if(is_string($s) && omSymbol($s))$symbols[$s]=true;
    foreach ($state as $s=>$row) {
        if (!omSymbol((string)$s) || !is_array($row)) continue;
        $age=omCloseAgeDays((string)($row['close_date']??''), $now);
        if ($age>=0 && $age<=4) $symbols[$s]=true;
        elseif ($age>4) unset($state[$s]);
    }
    $symbols=array_slice(array_keys($symbols),0,OM_MAX_SYMBOLS);
    if (!$symbols) { echo "Aucun candidat overnight; aucune alerte\n"; omSave($state); return; }
    $headers=['APCA-API-KEY-ID: '.$alpacaId,'APCA-API-SECRET-KEY: '.$alpacaSecret];
    $snapshot = [];
    foreach (array_chunk($symbols,30) as $chunk) {
        $data=omApi('https://data.alpaca.markets/v2/stocks/snapshots?'.http_build_query(['symbols'=>implode(',',$chunk),'feed'=>'iex']),$headers);
        $snapshot=array_merge($snapshot,$data);
    }
    $qualified=0; $sent=0; $stale=0; $errors=0;
    foreach ($symbols as $s) {
        $tick = omSnapshot($snapshot[$s] ?? [],$now);
        if (!$tick) { $stale++; continue; }
        $qualified++;
        $row = $state[$s] ?? [];
        $signals = omEvaluate($row,$phase,$tick,$now);
        foreach ($signals as $signal) {
            $tag=['close'=>'🔎 RADAR FIN DE SÉANCE','after'=>'🌙 BOOST AFTER-HOURS','pre'=>'🌅 PRÉOUVERTURE À EXAMINER','open'=>'🚀 OUVERTURE CONFIRMÉE'][$signal['phase']];
            $name=htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $msg="<b>$tag : $name</b>\nCours IEX observé : <b>".number_format($tick['price'],2,'.','')." $</b>".
                "\nHausse sur la référence : <b>+".number_format($signal['gain'],1,'.','')." %</b>".
                "\nSpread IEX : ".number_format($tick['spread'],2,'.','')." % | Vol. dernière minute IEX : ".$tick['minute_volume'].
                "\nCours horodaté : ".$tick['timestamp'].
                "\nDonnées IEX partielles. Pas d'ordre d'achat; confirmer sur ton courtier avant toute décision.";
            if (isset($signal['acceleration'])) $msg.="\nAccélération : +".number_format($signal['acceleration'],1,'.','')." %";
            if (!omNotify($tg,$chat,$msg)) { $errors++; continue; }
            $row['signals'][$signal['phase']]=$day; $sent++;
        }
        $state[$s]=$row;
    }
    omSave($state);
    echo json_encode(['phase'=>$phase,'universe'=>count($symbols),'usable'=>$qualified,'stale_or_illiquid'=>$stale,'telegram_ok'=>$sent,'errors'=>$errors], JSON_UNESCAPED_UNICODE)."\n";
    if ($errors) throw new RuntimeException('Notifications Telegram non remises');
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'') === __FILE__) {
    try { omMain(); } catch(Throwable $e) {fwrite(STDERR,'[OVERNIGHT ERROR] '.$e->getMessage()."\n"); exit(1);}
}
