<?php

namespace App\Console\Commands;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Services\PolicyFlowService;
use Illuminate\Console\Command;

class ReconcilePendingPoliciesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'policyflow:reconcile {--limit=50 : Maximum number of policies to check}';

    /**
     * The console command description.
     */
    protected $description = 'Detects stale PENDING_CONFIRMATION or NEEDS_RECONCILIATION requests and reconciles with mock insurer.';

    /**
     * Execute the console command.
     */
    public function handle(PolicyFlowService $policyFlowService): int
    {
        $limit = (int) $this->option('limit');

        $pendingPolicies = PolicyRequest::whereIn('status', [
            PolicyStatus::PENDING_CONFIRMATION,
            PolicyStatus::NEEDS_RECONCILIATION,
        ])
            ->limit($limit)
            ->get();

        $this->info("Found {$pendingPolicies->count()} policies awaiting reconciliation.");

        $resolved = 0;
        foreach ($pendingPolicies as $policy) {
            $this->line("Reconciling PolicyRequest #{$policy->id} ({$policy->client_request_id})...");
            $success = $policyFlowService->reconcileWithProvider($policy);
            if ($success) {
                $this->info("✓ Successfully reconciled #{$policy->id} to ISSUED (Policy: {$policy->policy_number})");
                $resolved++;
            } else {
                $this->warn("- Status unchanged or record not yet confirmed at provider for #{$policy->id}");
            }
        }

        $this->info("Reconciliation run completed. Resolved: {$resolved}/{$pendingPolicies->count()}");
        return self::SUCCESS;
    }
}
