<?php

declare(strict_types=1);

namespace Dbots\ClonesServ\Adapter\Out\Config;

use Dbots\ClonesServ\Application\Port\Out\ClonesWriterInterface;
use RuntimeException;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function posix_kill;
use function rename;
use function sprintf;
use function tempnam;
use function unlink;

final readonly class UnrealircdClonesWriter implements ClonesWriterInterface
{
    public function __construct(
        private string $configPath,
        private string $pidFilePath,
    ) {
    }

    public function write(array $exceptions): bool
    {
        $lines = [];

        foreach ($exceptions as $exception) {
            $lines[] = sprintf(
                "allow {\n    mask *@%s;\n    class clients;\n    maxperip %d;\n}",
                $exception->host,
                $exception->clones,
            );
        }

        $content = [] === $lines ? '' : implode("\n", $lines) . "\n";
        $this->writeAtomically($content);

        return $this->rehash();
    }

    private function writeAtomically(string $content): void
    {
        $dir = dirname($this->configPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        $temp = tempnam($dir, 'clones_conf_tmp_');

        if (false === $temp) {
            throw new RuntimeException(sprintf('No se pudo crear fichero temporal en %s.', $dir));
        }

        try {
            file_put_contents($temp, $content);
            rename($temp, $this->configPath);
        } catch (\Throwable $error) {
            unlink($temp);
            throw new RuntimeException(sprintf('No se pudo escribir %s: %s', $this->configPath, $error->getMessage()));
        }
    }

    private function rehash(): bool
    {
        if (!is_file($this->pidFilePath)) {
            return false;
        }

        $pid = (int) file_get_contents($this->pidFilePath);

        if ($pid <= 0) {
            return false;
        }

        if (!function_exists('posix_kill')) {
            return false;
        }

        return posix_kill($pid, 1);
    }
}
