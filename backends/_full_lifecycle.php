<?php
require __DIR__.'/vendor/autoload.php'; $a=require __DIR__.'/bootstrap/app.php'; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Workspace; use App\Models\QuickTrade; use App\Domain\Trading\RobotSessionService; use App\Domain\Trading\CandleDirectionBotService; use App\Models\RobotSession;
function line($s){ echo $s."\n"; }
$ws=Workspace::first(); $svc=app(RobotSessionService::class); $bot=app(CandleDirectionBotService::class);

line("===== A) Threshold 65 (high) — yean trade aagala =====");
$candles=app(\App\Domain\Trading\TradingMarketService::class)->candles('BTCUSDT','5m',100,['workspace_id'=>$ws->id,'mode'=>'paper']);
$sig=$bot->computeSignal($candles,'5m',['RSI','MACD']);
line("signal: {$sig['side']} ".round($sig['confidence']*100)."% → threshold 65 → ".(($sig['confidence']*100)>=65?'TRADE':'NO TRADE (below 65)'));

line("");
line("===== B) Threshold 50 (correct for candle) — FULL lifecycle =====");
$s=$svc->create($ws->id,['name'=>'Lifecycle Test','symbol'=>'BTCUSDT','timeframe'=>'5m','expiry_seconds'=>300,'base_stake'=>10,'bot_mode'=>'candle_direction','stop_loss_pct'=>20,'confidence_threshold'=>50]);
$s=$svc->start($s);
line("created+started: status={$s->status} threshold={$s->confidence_threshold}");
line("BEFORE: open_trades=".QuickTrade::where('robot_session_id',$s->id)->where('status','open')->count()." active_side=".var_export($s->active_side,true)." pnl={$s->total_pnl} wins={$s->win_count} losses={$s->loss_count}");

// drive a few eval cycles
for($i=1;$i<=3;$i++){
  $r=$svc->evaluateCandleDirectionSession($s->fresh());
  $s=$s->fresh();
  $open=QuickTrade::where('robot_session_id',$s->id)->where('status','open')->count();
  line("eval#$i → status={$r['status']} action={$r['action']} | active_side=".var_export($s->active_side,true)." open_trades=$open peak_profit={$s->peak_profit}");
}

$st=$svc->liveStats($s->fresh()); $p=$st['position']??null;
line("liveStats: price={$st['current_price']} position=".($p?"YES side={$p['side']} entry={$p['entry_price']} pnl={$p['unrealized_pnl']} stop={$p['stop_price']}":'none')." | session PnL={$st['performance']['total_pnl']} W/L={$st['performance']['win_count']}/{$st['performance']['loss_count']}");

// force close to show win/loss + pnl change
if($s->active_side){
  $fc=$svc->forceClose($s->fresh()); $s=$s->fresh();
  line("forceClose → ".$fc['action']);
  $svc->refreshPerformance($s); $s=$s->fresh();
  line("AFTER CLOSE: open_trades=".QuickTrade::where('robot_session_id',$s->id)->where('status','open')->count()." pnl={$s->total_pnl} wins={$s->win_count} losses={$s->loss_count}");
}

QuickTrade::where('robot_session_id',$s->id)->delete(); $s->delete(); line("cleanup done.");
unlink(__FILE__);
