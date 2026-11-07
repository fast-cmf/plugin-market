<?php

namespace App\Console\Commands;

use App\Services\Market\License\LicenseManager;
use Illuminate\Console\Command;

/**
 * Daily silent heartbeat (design §4.5). Never blocks page requests and
 * never fails hard: connection problems are absorbed by the manager and
 * the license simply rides its grace period.
 */
class MarketHeartbeatCommand extends Command
{
    protected $signature = 'market:heartbeat';

    protected $description = '向市场平台发送 License 心跳（每日静默执行）';

    public function handle(LicenseManager $manager): int
    {
        $manager->heartbeat();

        if ($this->output->isVerbose()) {
            $this->info(sprintf('心跳完成，当前状态：%s', $manager->state()));
        }

        return self::SUCCESS;
    }
}
