<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\ClonesServ;

use DateTimeImmutable;
use Dbots\ClonesServ\Adapter\Out\Config\InspircdClonesWriter;
use Dbots\ClonesServ\Domain\ClonesException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class InspircdClonesWriterTest extends TestCase
{
    private string $configPath;
    private string $pidPath;
    private InspircdClonesWriter $writer;

    protected function setUp(): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'clones_conf_');
        $this->pidPath = tempnam(sys_get_temp_dir(), 'clones_pid_');
        unlink($this->pidPath);

        $this->writer = new InspircdClonesWriter($this->configPath, $this->pidPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @unlink($this->pidPath);
    }

    #[Test]
    public function itWritesOneLinePerException(): void
    {
        $exceptions = [
            new ClonesException(null, '217.160.136.43', 100, 'perm', 'admin', new DateTimeImmutable(), null),
            new ClonesException(null, '101-209-39-46.usuarios.innovasur.com', 100, 'perm', 'admin', new DateTimeImmutable(), null),
        ];

        $rehashed = $this->writer->write($exceptions);

        self::assertFalse($rehashed);

        $content = file_get_contents($this->configPath);
        self::assertStringContainsString('<connect name="clones_217_160_136_43" allow="217.160.136.43" parent="main" localmax="100" globalmax="100">', $content);
        self::assertStringContainsString('<connect name="clones_101_209_39_46_usuarios_innovasur_com" allow="101-209-39-46.usuarios.innovasur.com" parent="main" localmax="100" globalmax="100">', $content);
    }

    #[Test]
    public function anEmptyListLeavesTheFileEmpty(): void
    {
        $rehashed = $this->writer->write([]);

        self::assertFalse($rehashed);
        self::assertSame('', file_get_contents($this->configPath));
    }
}
