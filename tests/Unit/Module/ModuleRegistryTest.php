<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Module;

use Dbots\Module\ModuleRegistry;
use Dbots\Tests\Support\InMemoryModuleStateRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    private InMemoryModuleStateRepository $state;
    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        $this->state = new InMemoryModuleStateRepository();
        $this->registry = new ModuleRegistry($this->state, __DIR__.'/../../../modules');
    }

    #[Test]
    public function itFindsTheModulesOnDisk(): void
    {
        $manifests = $this->registry->manifests();

        self::assertArrayHasKey('Ping', $manifests);
        self::assertSame('PiNG', $manifests['Ping']->nick);
        self::assertSame('Modules\\Ping\\PingModule', $manifests['Ping']->class);
    }

    #[Test]
    public function anEnabledModuleIsInstantiated(): void
    {
        $modules = $this->registry->enabled();

        self::assertCount(1, $modules);
        self::assertSame('PiNG', $modules[0]->nick());
    }

    #[Test]
    public function aDisabledModuleStaysOut(): void
    {
        $this->state->setEnabled('Ping', false);

        self::assertSame([], $this->registry->enabled());
    }
}
