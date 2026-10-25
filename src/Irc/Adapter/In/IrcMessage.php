<?php

declare(strict_types=1);

namespace Dbots\Irc\Adapter\In;

final readonly class IrcMessage
{
    /**
     * @param list<string> $params
     */
    public function __construct(
        public ?string $prefix,
        public string $command,
        public array $params,
        public ?string $trailing,
    ) {
    }

    /** The source UID for a user or server, without the leading colon. */
    public function source(): ?string
    {
        return $this->prefix;
    }

    public function isFromUser(): bool
    {
        return null !== $this->prefix && !str_contains($this->prefix, '.');
    }

    public function target(): ?string
    {
        return $this->params[0] ?? null;
    }

    /**
     * The first argument, whether the server sent it as a parameter or as the
     * trailing part. A join arrives as `JOIN :#channel`, so params stays empty.
     */
    public function firstParameter(): ?string
    {
        return $this->params[0] ?? $this->trailing;
    }

    public function text(): string
    {
        return $this->trailing ?? '';
    }
}
