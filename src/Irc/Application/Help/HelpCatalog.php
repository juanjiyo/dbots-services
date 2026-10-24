<?php

declare(strict_types=1);

namespace Dbots\Irc\Application\Help;

use function array_filter;
use function array_values;
use function count;
use function sprintf;
use function strtoupper;

/**
 * Help pages for one service.
 *
 * Mirrors the original dBots help: an index of commands and one page per
 * command with its syntax, a short explanation and an example.
 */
final readonly class HelpCatalog
{
    /** @param list<HelpEntry> $entries */
    public function __construct(
        private string $service,
        private array $entries,
    ) {
    }

    /** @return list<string> */
    public function render(string $query, bool $isOper): array
    {
        $query = trim($query);

        if ('' === $query) {
            return $this->index($isOper);
        }

        $entry = $this->find($query);

        if (null === $entry) {
            return [sprintf('No hay ayuda para %s. Usa HELP para ver los comandos disponibles.', $query)];
        }

        if ($entry->operOnly && !$isOper) {
            return [sprintf('%s es un comando para operadores.', $entry->command)];
        }

        return $this->page($entry);
    }

    public function find(string $word): ?HelpEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->matches($word)) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<HelpEntry> */
    public function commands(bool $isOper): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (HelpEntry $entry): bool => $isOper || !$entry->operOnly,
        ));
    }

    /** @return list<string> */
    private function index(bool $isOper): array
    {
        $lines = [sprintf('Comandos de %s:', $this->service)];

        foreach ($this->commands($isOper) as $entry) {
            $lines[] = sprintf('%s — %s', strtoupper($entry->command), $entry->summary);
        }

        $lines[] = sprintf('Usa HELP %s <comando> para los detalles.', $this->service);

        return $lines;
    }

    /** @return list<string> */
    private function page(HelpEntry $entry): array
    {
        $lines = [
            sprintf('%s — %s', strtoupper($entry->command), $entry->summary),
            sprintf('Sintaxis: %s', $entry->syntax),
        ];

        if ([] !== $entry->aliases) {
            $lines[] = sprintf('También: %s', implode(', ', $entry->aliases));
        }

        if ('' !== $entry->example) {
            $lines[] = sprintf('Ejemplo: %s', $entry->example);
        }

        if (count($lines) === 2) {
            $lines[] = 'Sin más opciones.';
        }

        return $lines;
    }
}
