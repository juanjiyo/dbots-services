<?php

declare(strict_types=1);

namespace Dbots\Bootstrap\Command;

use Dbots\Bootstrap\DaemonFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function max;
use function min;
use function sleep;
use function sprintf;
use function time;

#[AsCommand(name: 'dbots:run', description: 'Link to the IRCd and serve IRC services.')]
final class RunCommand extends Command
{
    public function __construct(
        private readonly DaemonFactory $factory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('seconds', InputArgument::OPTIONAL, 'Stop after this many seconds (default: run until stopped).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runtime = max(0, (int) $input->getArgument('seconds'));
        $deadline = $runtime > 0 ? time() + $runtime : null;
        $delay = (int) ($_ENV['DBOTS_RECONNECT_DELAY'] ?? 5);

        $io->title('dbots');

        do {
            $budget = null === $deadline ? null : max(0, $deadline - time());

            if (null !== $budget && $budget <= 0) {
                break;
            }

            $daemon = $this->factory->create();

            try {
                $daemon->run($budget);
            } catch (Throwable $exception) {
                $io->warning(sprintf('link failed: %s', $exception->getMessage()));
            } finally {
                $daemon->stop();
            }

            if (null !== $deadline && time() >= $deadline) {
                break;
            }

            if (null === $deadline) {
                sleep(max(1, $delay));
            }
        } while (true);

        $io->success('stopped');

        return Command::SUCCESS;
    }
}
