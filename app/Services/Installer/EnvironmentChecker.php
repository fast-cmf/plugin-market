<?php

namespace App\Services\Installer;

/**
 * Environment requirements checklist for step 1 of the wizard.
 */
class EnvironmentChecker
{
    /**
     * @return list<array{label: string, required: bool, passed: bool, detail: string}>
     */
    public function check(): array
    {
        $items = [];

        $version = PHP_VERSION;

        $items[] = [
            'label' => 'PHP 版本',
            'required' => true,
            'passed' => version_compare($version, '8.4.0', '>='),
            'detail' => sprintf('当前 %s，需要 >= 8.4', $version),
        ];

        foreach (['pdo_sqlite', 'pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl', 'sodium'] as $extension) {
            $items[] = [
                'label' => sprintf('扩展 %s', $extension),
                'required' => in_array($extension, ['pdo_sqlite', 'mbstring', 'openssl'], true),
                'passed' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? '已加载' : '未安装',
            ];
        }

        foreach ([
            'storage 目录' => storage_path(),
            'bootstrap/cache 目录' => base_path('bootstrap/cache'),
            '项目根目录' => base_path(),
        ] as $label => $path) {
            $items[] = [
                'label' => $label,
                'required' => true,
                'passed' => is_writable($path),
                'detail' => $path,
            ];
        }

        return $items;
    }

    /**
     * True when every required item passed.
     *
     * @param  list<array{label: string, required: bool, passed: bool, detail: string}>  $checklist
     */
    public function allPassed(array $checklist): bool
    {
        foreach ($checklist as $item) {
            if (($item['required'] ?? false) && ! ($item['passed'] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
