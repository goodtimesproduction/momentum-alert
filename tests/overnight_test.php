<?php
declare(strict_types=1);
require_once __DIR__.'/../overnight_momentum.php';
$pass=0;
function ok(bool $v, string $name): void { global $pass; if(!$v)throw new Exception('ECHEC : '.$name); $pass++; }
function at(string $datetime): DateTimeImmutable {return new DateTimeImmutable($datetime,new DateTimeZone('America/New_York'));}
function sample(string $time, float $price=110, float $bid=109.9, float $ask=110.1, int $vol=200): array {
    return ['latestTrade'=>['p'=>$price,'t'=>at($time)->format(DATE_ATOM)],
        'latestQuote'=>['bp'=>$bid,'ap'=>$ask,'t'=>at($time)->format(DATE_ATOM)],
        'minuteBar'=>['v'=>$vol,'t'=>at($time)->format(DATE_ATOM)]];
}
ok(omPhase(at('2026-10-23 15:37'))==='close','close');
ok(omPhase(at('2026-10-23 16:07'))==='after','after');
ok(omPhase(at('2026-10-26 07:22'))==='pre','pre Monday');
ok(omPhase(at('2026-10-26 09:37'))==='open','open Monday');
ok(omPhase(at('2026-10-25 09:37'))==='off','weekend');
ok(omSnapshot(sample('2026-10-23 15:37',110,109.9,110.1),at('2026-10-23 15:37'))!==null,'fresh snapshot');
ok(omSnapshot(sample('2026-10-23 15:07'),at('2026-10-23 15:37'))===null,'stale rejected');
ok(omSnapshot(sample('2026-10-23 15:37',110,100,120),at('2026-10-23 15:37'))===null,'spread rejected');
ok(omSnapshot(sample('2026-10-23 15:37',110,109.9,110.1,5),at('2026-10-23 15:37'))===null,'thin minute rejected');
$row=[];$base=['price'=>100];
ok(omEvaluate($row,'close',$base,at('2026-10-23 14:37'))===[],'baseline close');
$r=omEvaluate($row,'close',['price'=>103],at('2026-10-23 15:52'));
ok(count($r)===1 && $r[0]['phase']==='close','close setup');
$row['signals']['close']='2026-10-23';
ok(omEvaluate($row,'close',['price'=>104],at('2026-10-23 15:57'))===[],'close dedup');
$r=omEvaluate($row,'after',['price'=>107],at('2026-10-23 16:22'));
ok(count($r)===1 && $r[0]['phase']==='after','after acceleration');
$row['signals']['after']='2026-10-23';
ok(omEvaluate($row,'after',['price'=>108],at('2026-10-23 16:37'))===[],'after dedup');
ok(omEvaluate($row,'pre',['price'=>109],at('2026-10-26 07:07'))===[],'pre first sample');
$r=omEvaluate($row,'pre',['price'=>111],at('2026-10-26 07:22'));
ok(count($r)===1 && $r[0]['phase']==='pre','pre positive gap and acceleration');
$row['signals']['pre']='2026-10-26';
ok(omEvaluate($row,'open',['price'=>112],at('2026-10-26 09:37'))===[],'open first sample');
$r=omEvaluate($row,'open',['price'=>113],at('2026-10-26 09:52'));
ok(count($r)===1 && $r[0]['phase']==='open','opening confirmation');
$row['signals']['open']='2026-10-26';
ok(omEvaluate($row,'open',['price'=>115],at('2026-10-26 10:07'))===[],'open dedup');
$fresh=[];
ok(omEvaluate($fresh,'pre',['price'=>120],at('2026-10-26 07:07'))===[],'no close no pre');
ok(omCloseAgeDays('2026-10-23',at('2026-10-26 07:07'))===3,'Friday to Monday');
echo "OK : $pass tests overnight\n";
