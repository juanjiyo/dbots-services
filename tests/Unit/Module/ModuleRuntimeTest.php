<?php

declare(strict_types=1);

namespace Dbots\Tests\Unit\Module;

use Dbots\Irc\Adapter\In\IrcMessage;
use Dbots\Irc\Adapter\Out\Irc\ServiceUidFormatter;
use Dbots\Irc\Application\NetworkState;
use Dbots\Module\ModuleContext;
use Dbots\Module\ModuleInterface;
use Dbots\Module\ModuleLifecycleInterface;
use Dbots\Module\ModuleRegistry;
use Dbots\Module\ModuleRuntime;
use Dbots\Tests\Support\InMemoryModuleStateRepository;
use Dbots\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function glob;
use function implode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class FakeEnabledModule implements ModuleInterface
{
    public function nick(): string
    {
        return 'Fake';
    }

    public function description(): string
    {
        return 'A fake module for testing.';
    }

    public function handle(IrcMessage $message, NetworkState $state, ModuleContext $context): void
    {
    }
}

final class FakeLifecycleModule implements ModuleInterface, ModuleLifecycleInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function nick(): string
    {
        return 'Life';
    }

    public function description(): string
    {
        return 'A module with lifecycle hooks.';
    }

    public function handle(IrcMessage $message, NetworkState $state, ModuleContext $context): void
    {
    }

    public function onLoad(ModuleContext $context): void
    {
        $this->calls[] = 'load';
    }

    public function onUnload(ModuleContext $context): void
    {
        $this->calls[] = 'unload';
    }
}

final class ModuleRuntimeTest extends TestCase
{
    private string $directory;
    private InMemoryModuleStateRepository $state;
    private RecordingTransport $transport;
    private ModuleRuntime $runtime;

    protected function setUp(): void
    {
        $this->directory = tempnam(sys_get_temp_dir(), 'modules');
        unlink($this->directory);
        mkdir($this->directory);

        mkdir($this->directory.'/Enabled');
        file_put_contents(
            $this->directory.'/Enabled/module.json',
            '{"class":"Dbots\\\\Tests\\\\Unit\\\\Module\\\\FakeEnabledModule","nick":"Fake","description":"A fake module.","enabled_by_default":true}'
        );

        mkdir($this->directory.'/Lifecycle');
        file_put_contents(
            $this->directory.'/Lifecycle/module.json',
            '{"class":"Dbots\\\\Tests\\\\Unit\\\\Module\\\\FakeLifecycleModule","nick":"Life","description":"A lifecycle module.","enabled_by_default":true}'
        );

        $this->state = new InMemoryModuleStateRepository();
        $this->transport = new RecordingTransport();

        $registry = new ModuleRegistry($this->state, $this->directory);
        $this->runtime = new ModuleRuntime(
            $registry,
            $this->transport,
            new ServiceUidFormatter('003'),
            '003',
            'bots.sitiochat.es',
            '#debug',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path.'/module.json');
            rmdir($path);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function itIntroducesEnabledModulesAtStartup(): void
    {
        $this->runtime->loadEnabled();

        self::assertCount(2, $this->runtime->loaded());
        self::assertStringContainsString('UID Fake 0 ', implode("\n", $this->transport->written));
        self::assertStringContainsString('UID Life 0 ', implode("\n", $this->transport->written));
        self::assertStringContainsString('JOIN #debug', implode("\n", $this->transport->written));
    }

    #[Test]
    public function itUnloadsDisabledModulesAndQuitsThem(): void
    {
        $this->runtime->loadEnabled();
        $this->state->setEnabled('Enabled', false);

        $this->runtime->refresh();

        self::assertCount(1, $this->runtime->loaded());
        self::assertStringContainsString('QUIT :Módulo descargado', implode("\n", $this->transport->written));
    }

    #[Test]
    public function itReloadsAModuleWhenEnabledAgain(): void
    {
        $this->runtime->loadEnabled();
        $this->state->setEnabled('Enabled', false);
        $this->runtime->refresh();

        $before = $this->transport->written;
        $this->state->setEnabled('Enabled', true);
        $this->runtime->refresh();

        self::assertCount(2, $this->runtime->loaded());
        self::assertStringContainsString('UID Fake 0 ', implode("\n", $this->transport->written));
        self::assertNotSame($before, $this->transport->written);
    }

    #[Test]
    public function itCallsLifecycleHooks(): void
    {
        $this->runtime->loadEnabled();

        $lifecycle = null;
        foreach ($this->runtime->loaded() as $module) {
            if ($module instanceof FakeLifecycleModule) {
                $lifecycle = $module;
                break;
            }
        }

        self::assertInstanceOf(FakeLifecycleModule::class, $lifecycle);
        self::assertSame(['load'], $lifecycle->calls);

        $this->state->setEnabled('Lifecycle', false);
        $this->runtime->refresh();

        self::assertSame(['load', 'unload'], $lifecycle->calls);
    }

    #[Test]
    public function itProvidesAContextForEachLoadedModule(): void
    {
        $this->runtime->loadEnabled();

        foreach ($this->runtime->loaded() as $module) {
            $context = $this->runtime->contextFor($module);

            self::assertSame('003', $context->sid);
        }
    }
}
