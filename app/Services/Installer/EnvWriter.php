<?php

namespace App\Services\Installer;

use RuntimeException;

/**
 * Minimal .env writer: replaces existing keys in place, appends missing ones.
 */
class EnvWriter
{
    /**
     * Set one or more keys in the given env file.
     *
     * @param  array<string, string|null>  $values
     */
    public function set(string $path, array $values): void
    {
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            $contents = $this->replace($contents, $key, $value);
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('无法写入配置文件：%s', $path));
        }
    }

    private function replace(string $contents, string $key, ?string $value): string
    {
        $line = sprintf('%s=%s', $key, $this->format($value));

        $pattern = sprintf('/^%s\s*=.*$/m', preg_quote($key, '/'));

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, $line, $contents);
        }

        $contents = rtrim($contents, "\r\n")."\n";

        return $contents.$line."\n";
    }

    private function format(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (preg_match('/[\s"\'#]/', $value) === 1) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }
}
