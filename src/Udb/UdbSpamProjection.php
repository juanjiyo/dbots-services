<?php

declare(strict_types=1);

namespace Dbots\Udb;

use Dbots\OperServ\Application\Port\Out\SpamRuleRepositoryInterface;

use function base64_encode;
use function sprintf;

final readonly class UdbSpamProjection
{
    public function __construct(
        private SpamRuleRepositoryInterface $rules,
        private UdbAuthority $authority,
    ) {
    }

    public function publish(string $pattern): void
    {
        $rule = $this->rules->find($pattern);

        if (null === $rule) {
            return;
        }

        $prefix = sprintf('F::%s::', $rule->encodedPattern());

        foreach ($rule->properties() as [$name, $value]) {
            $this->authority->insert(
                'K',
                $prefix . $name,
                'expires' === $name || 'ban-time' === $name ? (int) $value : $value,
            );
        }
    }

    public function remove(string $pattern): void
    {
        $prefix = sprintf('F::%s::', 'b64:' . base64_encode($pattern));

        foreach (['match-type', 'targets', 'action', 'ban-time', 'reason', 'expires'] as $name) {
            $this->authority->delete('K', $prefix . $name);
        }
    }
}
