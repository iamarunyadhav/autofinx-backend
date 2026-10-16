<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Domain\MarketIntelligence\V5\Application\ResearchScanner;
use App\Domain\MarketOpportunityBrain\Fixtures\DeterministicMarketFixtureProvider;
use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicUniverseProvider;
use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicSnapshotProvider;
final class V5ResearchScanCommand extends Command { protected $signature='v5:research-scan {--limit=3} {--fixture} {--live} {--as-of=} {--persist : Persist only when schema is installed}'; protected $description='Run isolated V5 research scanner'; public function handle():int { $asOf=$this->option('as-of')?new \DateTimeImmutable($this->option('as-of')):new \DateTimeImmutable('now',new \DateTimeZone('UTC')); $live=(bool)$this->option('live'); if($live && $this->option('as-of')) { $this->error('--live requires a near-real-time --as-of or no --as-of'); return self::FAILURE; } $provider=$live?new BinancePublicUniverseProvider():new DeterministicMarketFixtureProvider(); $snapshot=$live?new BinancePublicSnapshotProvider():$provider; try { $scanner=new ResearchScanner($provider,$snapshot); $r=$scanner->scan($asOf,(int)$this->option('limit')); if($this->option('persist')) $r['run_id']=$scanner->persist($r,$live?'binance_public':'fixture',(int)$this->option('limit'))->id; $this->line(json_encode($r,JSON_PRETTY_PRINT)); return self::SUCCESS; } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; } } }
