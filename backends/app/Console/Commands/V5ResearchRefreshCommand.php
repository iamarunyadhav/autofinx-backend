<?php
namespace App\Console\Commands;
use App\Domain\MarketIntelligence\V5\Application\ResearchScanner;
use App\Domain\MarketOpportunityBrain\Fixtures\DeterministicMarketFixtureProvider;
use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicUniverseProvider;
use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicSnapshotProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
final class V5ResearchRefreshCommand extends Command
{
 protected $signature='v5:research-refresh {--fixture} {--live} {--as-of=} {--limit=3}';
 protected $description='Manual, disabled-by-default V5 shadow refresh; never schedules or trades.';
 public function handle():int { $lock=Cache::lock('v5-research-refresh',120); if(!$lock->get()){ $this->warn('Scanner already running.'); return self::FAILURE; } try { $live=(bool)$this->option('live'); if($live && $this->option('as-of')) { $this->error('--live cannot be combined with a historical --as-of'); return self::FAILURE; } $at=$this->option('as-of')?new \DateTimeImmutable($this->option('as-of')):null; $provider=$live?new BinancePublicUniverseProvider():new DeterministicMarketFixtureProvider(); $snapshot=$live?new BinancePublicSnapshotProvider():$provider; $s=new ResearchScanner($provider,$snapshot); $result=$s->scan($at,(int)$this->option('limit')); $run=$s->persist($result,$live?'binance_public':'fixture',(int)$this->option('limit')); $this->line(json_encode(['run_id'=>$run->id,'execution_authorized'=>false,'candidate_count'=>count($result['candidates']),'source_mode'=>$live?'binance_public':'fixture'])); return self::SUCCESS; } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; } finally { $lock->release(); } }
}
