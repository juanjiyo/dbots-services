<?php

declare(strict_types=1);

namespace Dbots\Domain\Channel;

use function sprintf;

/**
 * The on/off switches of `chanserv.set`. Their meaning is enforced by the
 * reactor (T17); here they are stored and reported.
 */
enum ChannelOption: string
{
    case KeepTopic = 'KEEPTOPIC';
    case TopicLock = 'TOPICLOCK';
    case DefaultTopic = 'DEFTOPIC';
    case Private = 'PRIVATE';
    case Restricted = 'RESTRICTED';
    case Secure = 'SECURE';
    case SecureOps = 'SECUREOPS';
    case SecureHalfOps = 'SECUREHALFOPS';
    case Debug = 'DEBUG';
    case NoExpire = 'NOEXPIRE';
    case AutoLimit = 'AUTOLIMIT';
    case ProtectedBans = 'PBANS';
    case ProtectedModes = 'PMODE';
    case Mlock = 'MLOCK';

    /** `ch.mrc` answers with a fixed sentence per option, not a generic one. */
    public function message(string $channel, bool $enabled): string
    {
        $state = $enabled ? 'activada' : 'desactivada';

        return match ($this) {
            self::KeepTopic => sprintf('Opción de retención de tópico %s.', $state),
            self::TopicLock => sprintf('Opción de candado de tópico %s.', $state),
            self::DefaultTopic => sprintf('Opción de tópico por defecto %s.', $state),
            self::Private => sprintf('Opción de privacidad %s.', $state),
            self::Restricted => sprintf('Opción de acceso restringido %s.', $state),
            self::Secure => sprintf('Opción Secure %s.', $state),
            self::SecureOps => sprintf('Opción de Secure Ops %s.', $state),
            self::SecureHalfOps => sprintf('Opción de Secure HalfOps %s.', $state),
            self::Debug => sprintf('Opción de Debug %s.', $state),
            self::NoExpire => $enabled
                ? sprintf('El canal %s no expirará.', $channel)
                : sprintf('El canal %s expirará si no es usado.', $channel),
            self::AutoLimit => $enabled
                ? sprintf('El canal %s será auto-limitado.', $channel)
                : sprintf('El canal %s no será auto-limitado.', $channel),
            self::ProtectedBans => sprintf(
                'Protección de bans %s para el canal %s',
                $enabled ? 'activada' : 'desactivada',
                $channel,
            ),
            self::Mlock => '',
            self::ProtectedModes => sprintf(
                'Protección de modos %s para el canal %s',
                $enabled ? 'activada' : 'desactivada',
                $channel,
            ),
        };
    }

    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtoupper($name));
    }
}
