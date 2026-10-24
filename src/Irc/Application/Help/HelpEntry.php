<?php

declare(strict_types=1);

namespace Dbots\Irc\Application\Help;

final readonly class HelpEntry
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(
        public string $command,
        public string $syntax,
        public string $summary,
        public string $example = '',
        public array $aliases = [],
        public bool $operOnly = false,
    ) {
    }

    public function matches(string $word): bool
    {
        $word = strtoupper($word);

        if ($word === strtoupper($this->command)) {
            return true;
        }

        foreach ($this->aliases as $alias) {
            if ($word === strtoupper($alias)) {
                return true;
            }
        }

        return false;
    }
}
