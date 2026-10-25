<?php

declare(strict_types=1);

namespace Dbots\Module;

/** The `module.json` a module directory must carry. */
final readonly class ModuleManifest
{
    public function __construct(
        public string $name,
        public string $class,
        public string $nick,
        public string $description,
        public bool $enabledByDefault,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromJson(string $name, array $data): ?self
    {
        $class = $data['class'] ?? null;
        $nick = $data['nick'] ?? null;

        if (!is_string($class) || !is_string($nick)) {
            return null;
        }

        return new self(
            $name,
            $class,
            $nick,
            is_string($data['description'] ?? null) ? $data['description'] : '',
            true === ($data['enabled_by_default'] ?? false),
        );
    }
}
