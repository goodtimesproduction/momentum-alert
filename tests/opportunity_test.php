<?php
declare(strict_types=1);
require_once __DIR__ . '/../opportunity_radar.php';
$count = 0;
function expect(bool $ok, string $label): void {
    global $count;
    $count++;
    if (!$ok) throw new RuntimeException("Test $count failed: $label");
}
$now = new DateTimeImmutable('2026-11-06 18:00:00', new DateTimeZone('America/New_York'));
$bars = [];
$cursor = new DateTimeImmutable('2026-11-06', new DateTimeZone('America/New_York'));
while (count($bars) < 65) {
    if ((int)$cursor->format('N') <= 5) {
        $i = count($bars);
        $close = $i === 0 ? 80.0 : 76.0;
        $bars[] = [
            'date'=>$cursor->format('Y-m-d'), 'close'=>$close, 'high'=>$i===12?100.0:($i===0?82.0:79.0),
            'low'=>$i===0?75.0:74.0, 'volume'=>$i===0?2000000:1000000
        ];
    }
    $cursor = $cursor->modify('-1 day');
}
$a = opAnalyze($bars, $now);
expect(is_array($a), 'synthetic bars accepted');
expect($a['candidate'], 'liquid correction found');
expect($a['rebound'], 'rebound confirmed only with volume / range');
expect(abs($a['drawdown'] - 20.0) < 0.001, '60 day high drawdown');
expect(abs($a['volume_ratio'] - 2.0) < 0.001, 'volume ratio compares to 20 prior days');
expect($a['date']==='2026-11-06', 'EOD label accurate');
expect(opSymbol('GOOGL') && opSymbol('BRK.B'), 'valid symbols');
expect(!opSymbol('../X') && !opSymbol('EUR/USD'), 'invalid symbols rejected');
expect(str_contains(opMessage('NVDA',$a,'WATCH','SPY sous sa MM5'), 'pas de cotation instantanée'), 'EOD clearly labelled');
$state = [];
expect(opDecide($a, $state, '2026-11-06')==='REBOUND', 'first rebound');
opMarkSent($state,'REBOUND','2026-11-06');
expect(opDecide($a, $state, '2026-11-06')==='WATCH', 'watch can follow rebound');
opMarkSent($state,'WATCH','2026-11-06');
expect(opDecide($a, $state, '2026-11-07')===null, 'no duplicate next day');
expect(opDecide($a, $state, '2026-11-20')==='WATCH', '14 day rearm');
$down = $bars;
$down[0]['close'] = 77;
$down[0]['high'] = 82;
$down[0]['low'] = 74;
$down[0]['volume'] = 800000;
$b = opAnalyze($down,$now);
expect($b!==null && $b['candidate'] && !$b['rebound'], 'observation without rebound');
$c = $bars;
$c[0]['close'] = 9; $c[0]['high']=10; $c[0]['low']=8;
expect(opAnalyze($c,$now)===null, 'reject price below $10');
$d = $bars;
$d[0]['date']='2026-10-01';
expect(opAnalyze($d,$now)===null || opAnalyze($d,$now)['date']!=='2026-10-01','old duplicated dates are not promoted');
$e = array_slice($bars,0,19);
expect(opAnalyze($e,$now)===null, 'reject too few bars');
$f = $bars;
$f[0]['date']='2099-01-01';
expect(opAnalyze($f,$now)===null, 'reject future bar');
$g = $bars;
$g[0]['close'] = 95; $g[0]['high'] = 100;
$ga=opAnalyze($g,$now);
expect($ga!==null && !$ga['candidate'], 'ignore shallow corrections');
expect(opMarketRegime($bars,$now)==='SPY au-dessus de sa MM5', 'market regime above MA5');
echo "Opportunity radar: $count tests passed\n";
