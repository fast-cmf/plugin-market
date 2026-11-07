<?php

namespace App\Console\Commands;

use App\Services\Market\License\Fingerprint;
use App\Services\Market\License\LicenseException;
use App\Services\Market\License\LicenseManager;
use Illuminate\Console\Command;

class MarketActivateCommand extends Command
{
    protected $signature = 'market:activate
                            {key : Marketplace license key (MKT-XXXX-XXXX-XXXX-XXXX)}
                            {--host= : Site host used for the fingerprint (defaults to app.url)}';

    protected $description = '激活市场 License 并绑定当前站点';

    public function handle(LicenseManager $manager): int
    {
        $host = is_string($this->option('host')) && $this->option('host') !== ''
            ? (string) $this->option('host')
            : (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        try {
            $document = $manager->activate((string) $this->argument('key'), Fingerprint::forHost($host));
        } catch (LicenseException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'License %s 已激活（产品：%s，到期：%s，站点指纹：%s…）。',
            $document->key(),
            $document->product(),
            date('Y-m-d', $document->expiresAt()),
            substr(Fingerprint::forHost($host), 0, 12),
        ));

        return self::SUCCESS;
    }
}
