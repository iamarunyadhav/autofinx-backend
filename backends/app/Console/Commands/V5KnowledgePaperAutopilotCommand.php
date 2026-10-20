<?php
namespace App\Console\Commands;
use App\Domain\Ml\Shadow\V5KnowledgePaperRunner;
use Illuminate\Console\Command;
final class V5KnowledgePaperAutopilotCommand extends Command
{
    protected $signature = 'v5:knowledge-paper:run {workspace?}';
    protected $description = 'Run approved Nexus knowledge in an isolated internal paper ledger; never places exchange orders.';
    public function handle(V5KnowledgePaperRunner $runner): int
    {
        $count = $runner->runApproved($this->argument('workspace'));
        $this->info("Knowledge paper autopilot processed {$count} approved rule(s); exchange execution remains disabled.");
        return self::SUCCESS;
    }
}
