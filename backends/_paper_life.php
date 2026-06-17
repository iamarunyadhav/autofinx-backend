<?php
require __DIR__.'/vendor/autoload.php'; $a=require __DIR__.'/bootstrap/app.php'; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Workspace; use App\Models\QuickTrade; use App\Domain\Trading\RobotSessionService; use App\Models\RobotSession;
function line($s){ echo $s."\n"; }
$ws=Workspace::first(); $svc=app(RobotSessionService::class);

$s=$svc->create($ws->id,['name'=>'Paper Lifecycle','symbol'=>'BTCUSDT','timeframe'=>'5m','expiry_seconds'=>300,'base_stake'=>10,'bot_mode'=>'candle_direction','stop_loss_pct'=>20,'confidence_threshold'=>50]);
$s->mode='paper'; $s->status='RUNNING'; $s->save();  // force PAPER (user's actual mode)
line("session RUNNING, mode=paper, threshold=50");
line("BEFORE: open=".QuickTrade::where('robot_session_id',$s->id)->where('status','open')->count()." active=".var_export($s->active_side,true)." pnl={$s->total_pnl} W/L={$s->win_count}/{$s->loss_count}");

for($i=1;$i<=4;$i++){
  $r=$svc->evaluateCandleDirectionSession($s->fresh()); $s=$s->fresh();
  $open=QuickTrade::where('robot_session_id',$s->id)->where('status','open')->count();
  line("eval#$i → action={$r['action']} status={$r['status']} | active=".var_export($s->active_side,true)." open=$open peak={$s->peak_profit}");
}
$st=$svc->liveStats($s->fresh()); $p=$st['position']??null;
line("liveStats: pos=".($p?"YES {$p['side']} entry={$p['entry_price']} pnl={$p['unrealized_pnl']} pct={$p['unrealized_pct']}% stop={$p['stop_price']}":'none'));
if($s->fresh()->active_side){ $svc->forceClose($s->fresh()); $s=$s->fresh(); line("forced close."); }
line("AFTER: open=".QuickTrade::where('robot_session_id',$s->id)->where('status','open')->count()." pnl={$s->total_pnl} W/L={$s->win_count}/{$s->loss_count}");
$hist=QuickTrade::where('robot_session_id',$s->id)->where('status','!=','open')->get();
line("settled trades: ".$hist->count()." | ".$hist->map(fn($t)=>$t->side.' '.$t->status.' pnl='.$t->profit_loss)->implode(' , '));
QuickTrade::where('robot_session_id',$s->id)->delete(); $s->delete(); line("cleanup done.");
unlink(__FILE__);
