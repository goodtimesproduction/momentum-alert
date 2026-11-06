<?php
declare(strict_types=1);
require_once __DIR__ . '/../momentum_alert.php';
$checks = 0;
function checkBreakout(bool $value, string $reason): void {
    global $checks;
    $checks++;
    if (!$value) throw new RuntimeException("Breakout check #$checks: $reason");
}
$good = ['symbol'=>'TEST', 'name'=>'Example Company', 'price'=>25, 'changesPercentage'=>'20.4%'];
checkBreakout(isQualityGainer($good), 'legitimate +20% candidate');
checkBreakout(!isQualityGainer(array_merge($good,['price'=>9.99])), 'reject sub-$10');
checkBreakout(!isQualityGainer(array_merge($good,['changesPercentage'=>'14.9%'])), 'reject below +15%');
checkBreakout(!isQualityGainer(array_merge($good,['changesPercentage'=>'61%'])), 'reject already up >60%');
checkBreakout(!isQualityGainer(array_merge($good,['name'=>'Example leveraged ETF'])), 'reject leveraged products');
checkBreakout(abs(parsePct('+25.5%')-25.5)<0.0001, 'parse percentage');
checkBreakout(stateKey('MC','XPAR')==='MC.XPAR', 'MIC keys');
checkBreakout(MAX_TRACKED_PER_RUN === 8 && TRAILING_STOP === 12.0, 'quota/threshold constants');
checkBreakout(function_exists('breakoutMain'), 'orchestrator available without running');
echo "Breakout: $checks tests passed\n";
