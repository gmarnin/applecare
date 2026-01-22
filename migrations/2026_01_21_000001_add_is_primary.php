<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;

class AddIsPrimary extends Migration
{
    public function up()
    {
        $capsule = new Capsule();
        
        // Add is_primary and coverage_status columns
        $capsule::schema()->table('applecare', function (Blueprint $table) {
            $table->boolean('is_primary')->default(0)->after('isCanceled');
            $table->string('coverage_status', 20)->nullable()->after('is_primary');
            $table->index('is_primary');
            $table->index('coverage_status');
            $table->index(['serial_number', 'is_primary']);
        });
        
        // Mark "the one" for each device and set coverage_status
        $this->markPrimaryPlans();
    }

    public function down()
    {
        $capsule = new Capsule();
        $capsule::schema()->table('applecare', function (Blueprint $table) {
            // Drop indexes by column name (Laravel will generate the correct index name)
            $table->dropIndex(['is_primary']);
            $table->dropIndex(['coverage_status']);
            $table->dropIndex(['serial_number', 'is_primary']);
            $table->dropColumn(['is_primary', 'coverage_status']);
        });
    }
    
    /**
     * Mark the primary plan for each device and set coverage_status
     */
    private function markPrimaryPlans()
    {
        $now = date('Y-m-d');
        $thirtyDays = date('Y-m-d', strtotime('+30 days'));
        
        // Get all unique serial numbers
        $serials = Capsule::table('applecare')
            ->distinct()
            ->pluck('serial_number');
        
        foreach ($serials as $serial) {
            if (empty($serial)) {
                continue;
            }
            
            // Get all plans for this device
            $plans = Capsule::table('applecare')
                ->where('serial_number', $serial)
                ->get();
            
            if ($plans->isEmpty()) {
                continue;
            }
            
            // Find the primary plan and determine coverage status
            $result = $this->findPrimaryPlanAndStatus($plans, $now, $thirtyDays);
            
            if ($result['id']) {
                // Mark this plan as primary with coverage_status
                Capsule::table('applecare')
                    ->where('id', $result['id'])
                    ->update([
                        'is_primary' => 1,
                        'coverage_status' => $result['coverage_status']
                    ]);
            }
        }
    }
    
    /**
     * Find the primary plan for a device and determine its coverage status
     * 
     * Logic (same as tab's get_data):
     * - Pick the plan with the latest end date (treating null as very old date)
     * - This is the "most relevant" plan for display purposes
     * 
     * Coverage status is then determined based on the primary plan:
     * - "active": Plan is active (status=ACTIVE, not canceled, end date > 30 days from now)
     * - "expiring_soon": Plan is active but end date <= 30 days from now
     * - "inactive": Plan is not active (status != ACTIVE, or canceled, or end date in past)
     * 
     * @param \Illuminate\Support\Collection $plans
     * @param string $now
     * @param string $thirtyDays
     * @return array ['id' => string|null, 'coverage_status' => string]
     */
    private function findPrimaryPlanAndStatus($plans, $now, $thirtyDays)
    {
        $allPlans = [];
        
        foreach ($plans as $plan) {
            $allPlans[] = $plan;
        }
        
        // Pick the plan with the latest end date (same logic as tab's get_data)
        // Treat null end dates as very old (1970-01-01)
        usort($allPlans, function($a, $b) {
            $aEnd = $a->endDateTime ?? '1970-01-01';
            $bEnd = $b->endDateTime ?? '1970-01-01';
            return strcmp($bEnd, $aEnd); // Descending order
        });
        
        $primary = $allPlans[0] ?? null;
        
        if (!$primary) {
            return ['id' => null, 'coverage_status' => 'inactive'];
        }
        
        // Determine coverage status based on the primary plan
        $status = strtoupper($primary->status ?? '');
        $isCanceled = !empty($primary->isCanceled);
        $endDate = $primary->endDateTime;
        
        // Check if plan is active (status=ACTIVE, not canceled, end date in future)
        $isActive = $status === 'ACTIVE' 
            && !$isCanceled 
            && !empty($endDate) 
            && $endDate >= $now;
        
        if ($isActive) {
            // Active - check if expiring soon
            $coverageStatus = ($endDate <= $thirtyDays) ? 'expiring_soon' : 'active';
        } else {
            // Inactive (expired, canceled, or status != ACTIVE)
            $coverageStatus = 'inactive';
        }
        
        return ['id' => $primary->id, 'coverage_status' => $coverageStatus];
    }
}
