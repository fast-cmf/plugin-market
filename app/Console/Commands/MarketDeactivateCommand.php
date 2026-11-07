<?php

namespace App\Console\Commands;

use App\Services\Market\License\LicenseManager;
use Illuminate\Console\Command;

class MarketDeactivateCommand extends Command
{
    protected $signature = 'market:deactivate {--force : Skip confirmation}';

    protected $description = '停用并清除本站点的 License 档案（释放激活名额）';

    public function handle(LicenseManager $manager): int
    {
        if ($manager->document() === null) {
            $this->info('当前站点没有已激活的 License。');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('确定要停用当前站点的 License 吗？')) {
            $this->line('已取消。');

            return self::SUCCESS;
        }

        $manager->deactivate();

        $this->info('License 档案已清除。站点名额将在下次激活时释放。');

        return self::SUCCESS;
    }
}
