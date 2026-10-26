<?php

declare(strict_types=1);

namespace Dbots\Sumidero\Application;

use Closure;
use Dbots\Irc\Application\NetworkState;
use Dbots\OperServ\Application\Port\Out\EnforcementInterface;
use Dbots\Sumidero\Application\Port\Out\SumideroStateRepositoryInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function explode;
use function fnmatch;
use function implode;
use function is_string;
use function preg_match;
use function random_bytes;
use function sprintf;
use function str_contains;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

/**
 * Core behaviour of the Sumidero module: anti-clone gate, flood detection,
 * sink/release and staff commands.
 */
final class SumideroService
{
    /** @var array<string, list<array{time: int, text: string}>> */
    private array $floodWindow = [];

    private int $loadTime;

    public function __construct(
        private Config $config,
        private SumideroStateRepositoryInterface $repository,
        private EnforcementInterface $enforcement,
        private string $moduleUid,
        private Closure $reply,
        private Closure $line,
        private Closure $now,
    ) {
        $this->loadTime = ($this->now)();
    }

    public function joinChannels(): void
    {
        ($this->line)(sprintf(':%s JOIN %s', $this->moduleUid, $this->config->sink));

        foreach ($this->config->watched as $channel) {
            if (0 !== strcasecmp($channel, $this->config->sink)) {
                ($this->line)(sprintf(':%s JOIN %s', $this->moduleUid, $channel));
            }
        }
    }

    public function onUserIntroduced(string $uid, NetworkState $state): void
    {
        if (!$this->config->gateEnabled) {
            return;
        }

        if ($state->hasMode($uid, 'r')) {
            return;
        }

        if ($this->isProtected($uid, $state)) {
            return;
        }

        // The caller (the module) is expected to schedule this check.
    }

    public function checkGate(string $uid, NetworkState $state): void
    {
        if (null === $state->nickOf($uid)) {
            return;
        }

        if ($state->hasMode($uid, 'r')) {
            return;
        }

        if ($this->isProtected($uid, $state)) {
            return;
        }

        $this->sink($uid, 'sin identificar', $state);
    }

    public function onChannelMessage(string $channel, string $uid, string $text, NetworkState $state): void
    {
        if (!$this->isWatched($channel)) {
            return;
        }

        if ($this->isProtected($uid, $state)) {
            return;
        }

        if ($this->config->floodEnabled) {
            $reason = $this->detectFlood($channel, $uid, $text);

            if (null !== $reason) {
                $this->forgetFlood($uid);
                $this->sink($uid, sprintf('flood en %s (%s)', $channel, $reason), $state);

                return;
            }
        }

        if ($this->matchesSpamPattern($text)) {
            $this->forgetFlood($uid);
            $this->sink($uid, sprintf('spam en %s', $channel), $state);
        }
    }

    public function onPrivateMessage(string $sourceUid, string $text, NetworkState $state): void
    {
        $text = trim($text);

        if ('' === $text) {
            return;
        }

        $parts = array_values(array_filter(explode(' ', $text), static fn (string $token): bool => '' !== $token));

        if (strtoupper($parts[0] ?? '') === 'VERIFICAR') {
            $this->onVerify($sourceUid, $parts, $state);

            return;
        }

        if (!$this->isAdmin($state->nickOf($sourceUid) ?? '', $state->hostOf($sourceUid) ?? '')) {
            return;
        }

        $this->onStaffCommand($sourceUid, $text, $state);
    }

    /**
     * @param list<string> $parts
     */
    private function onVerify(string $sourceUid, array $parts, NetworkState $state): void
    {
        if ('irc' !== $this->config->verifyBridgeKind) {
            return;
        }

        $nick = $state->nickOf($sourceUid) ?? '';
        $host = $state->hostOf($sourceUid) ?? '';

        if ('' === $nick || '' === $host) {
            return;
        }

        $mask = sprintf('%s!*@%s', $nick, $host);

        if (!$this->matchesMask($mask, $this->config->bridgeMask)) {
            return;
        }

        $token = $parts[1] ?? '';
        $ip = $parts[2] ?? '';

        if ('' === $token) {
            return;
        }

        if ($this->verifyToken($token, $ip)) {
            $this->reply($sourceUid, 'VERIFICAR OK: verificado');

            return;
        }

        $this->reply($sourceUid, 'VERIFICAR ERROR: token desconocido, caducado o IP distinta');
    }

    public function verifyToken(string $token, string $ip): bool
    {
        $data = $this->repository->findToken($token);

        if (null === $data) {
            return false;
        }

        $since = (int) ($data['since'] ?? 0);

        if ($this->config->tokenLife > 0 && ($this->now)() - $since > $this->config->tokenLife) {
            $this->repository->removeToken($token);

            return false;
        }

        $tokenIp = $data['ip'] ?? null;

        if (null !== $tokenIp && '' !== $tokenIp && '' !== $ip && $tokenIp !== $ip) {
            return false;
        }

        $nick = is_string($data['nick'] ?? null) ? (string) $data['nick'] : '';

        if ('' === $nick) {
            return false;
        }

        $this->releaseByNick($nick);

        return true;
    }

    public function sink(string $uid, string $reason, NetworkState $state): bool
    {
        $nick = $state->nickOf($uid);

        if (null === $nick) {
            return false;
        }

        if (null !== $this->repository->findShun($nick)) {
            return false;
        }

        if ($this->isProtected($uid, $state)) {
            return false;
        }

        $host = $state->hostOf($uid);

        if (null === $host || '' === $host) {
            return false;
        }

        $mask = sprintf('*@%s', $host);
        $offenses = $this->repository->bumpOffense($host);
        $seconds = min($this->config->shunTime * $offenses, $this->config->shunMax);

        $token = bin2hex(random_bytes(16));
        $ip = $state->ipOf($uid);

        $this->repository->addToken($token, [
            'nick' => $nick,
            'host' => $host,
            'ip' => $ip,
            'since' => ($this->now)(),
            'reason' => $reason,
        ]);

        $this->enforcement->svsJoin($uid, $this->config->sink);
        $this->enforcement->shun($mask, $seconds, sprintf('Sumidero: %s', $reason));

        $this->repository->addShun($nick, $mask, $host, $ip, ($this->now)() + $seconds, $reason);

        $notice = sprintf(
            'Accedes a %s por %s. Lee el topic del canal para salir',
            $this->config->sink,
            $reason,
        );

        if ('' !== $this->config->verifyUrl) {
            $notice .= sprintf(' (%s?t=%s)', $this->config->verifyUrl, $token);
        }

        $notice .= ' o habla con un operador.';
        $this->notice($uid, $notice);

        return true;
    }

    public function release(string $uidOrMask, NetworkState $state): bool
    {
        if (str_contains($uidOrMask, '@')) {
            $this->enforcement->unshun($uidOrMask);
            $this->repository->removeShunByMask($uidOrMask);

            return true;
        }

        $uid = $state->uidOf($uidOrMask) ?? $uidOrMask;

        return $this->releaseByUid($uid, $state);
    }

    public function releaseByNick(string $nick): void
    {
        $shun = $this->repository->findShun($nick);

        if (null === $shun) {
            return;
        }

        $mask = is_string($shun['mask'] ?? null) ? (string) $shun['mask'] : '';

        if ('' !== $mask) {
            $this->enforcement->unshun($mask);
        }

        $this->repository->removeShun($nick);
        $this->repository->removeTokensForHost(is_string($shun['host'] ?? null) ? (string) $shun['host'] : '');
    }

    private function releaseByUid(string $uid, NetworkState $state): bool
    {
        $nick = $state->nickOf($uid);

        if (null === $nick) {
            return false;
        }

        $this->releaseByNick($nick);
        $this->notice($uid, sprintf('Ya puedes hablar y salir de %s.', $this->config->sink));

        return true;
    }

    private function onStaffCommand(string $sourceUid, string $text, NetworkState $state): void
    {
        $parts = array_values(array_filter(explode(' ', trim($text)), static fn (string $token): bool => '' !== $token));

        if ([] === $parts) {
            return;
        }

        $command = strtolower($parts[0]);
        $args = array_slice($parts, 1);

        match ($command) {
            'help' => $this->cmdHelp($sourceUid),
            'status' => $this->cmdStatus($sourceUid),
            'release' => $this->cmdRelease($sourceUid, $args, $state),
            'sink' => $this->cmdSink($sourceUid, $args, $state),
            'ignore' => $this->cmdIgnore($sourceUid, $args),
            'unignore' => $this->cmdUnignore($sourceUid, $args),
            'spam' => $this->cmdSpam($sourceUid, $args),
            default => $this->reply($sourceUid, sprintf('Comando desconocido %s. Usa HELP.', $parts[0])),
        };
    }

    private function cmdHelp(string $sourceUid): void
    {
        foreach ([
            'Comandos: status · release <nick|máscara> · sink <nick> [motivo]',
            'ignore <nick|máscara> · unignore <nick|máscara>',
            'spam list · spam add <patrón> · spam del <n> · spam reload',
        ] as $line) {
            $this->reply($sourceUid, $line);
        }
    }

    private function cmdStatus(string $sourceUid): void
    {
        $uptime = ($this->now)() - $this->loadTime;
        $hours = (int) ($uptime / 3600);
        $minutes = (int) (($uptime % 3600) / 60);

        $this->reply($sourceUid, sprintf(
            'activo %dh %dm · sumidero %s · shuneados %d · ignorados %d · flood %s',
            $hours,
            $minutes,
            $this->config->sink,
            count($this->repository->allShuns()),
            count($this->repository->allIgnored()),
            $this->config->floodEnabled ? 'sí' : 'no',
        ));
    }

    /**
     * @param list<string> $args
     */
    private function cmdRelease(string $sourceUid, array $args, NetworkState $state): void
    {
        if ([] === $args) {
            $this->reply($sourceUid, 'Uso: release <nick|máscara>');

            return;
        }

        $target = $args[0];
        $ok = $this->release($target, $state);

        $this->reply($sourceUid, sprintf('%s: %s', $ok ? 'Liberado' : 'No pude liberar', $target));
    }

    /**
     * @param list<string> $args
     */
    private function cmdSink(string $sourceUid, array $args, NetworkState $state): void
    {
        if ([] === $args) {
            $this->reply($sourceUid, 'Uso: sink <nick> [motivo]');

            return;
        }

        $nick = $args[0];
        $reason = implode(' ', array_slice($args, 1));

        if ('' === $reason) {
            $reason = sprintf('orden de %s', $state->nickOf($sourceUid) ?? 'staff');
        }

        $uid = $state->uidOf($nick);

        if (null === $uid) {
            $this->reply($sourceUid, sprintf('No encuentro a %s.', $nick));

            return;
        }

        $ok = $this->sink($uid, $reason, $state);

        $this->reply($sourceUid, sprintf('%s: %s', $ok ? 'Enviado al sumidero' : 'No pude enviar', $nick));
    }

    /**
     * @param list<string> $args
     */
    private function cmdIgnore(string $sourceUid, array $args): void
    {
        if ([] === $args) {
            $this->reply($sourceUid, 'Uso: ignore <nick|máscara>');

            return;
        }

        $this->repository->addIgnored($args[0]);
        $this->reply($sourceUid, sprintf('Ignorado: %s', strtolower($args[0])));
    }

    /**
     * @param list<string> $args
     */
    private function cmdUnignore(string $sourceUid, array $args): void
    {
        if ([] === $args) {
            $this->reply($sourceUid, 'Uso: unignore <nick|máscara>');

            return;
        }

        $this->repository->removeIgnored($args[0]);
        $this->reply($sourceUid, sprintf('Ya no se ignora: %s', strtolower($args[0])));
    }

    /**
     * @param list<string> $args
     */
    private function cmdSpam(string $sourceUid, array $args): void
    {
        $action = strtolower($args[0] ?? '');

        match ($action) {
            'list', 'lista' => $this->cmdSpamList($sourceUid),
            'add' => $this->cmdSpamAdd($sourceUid, array_slice($args, 1)),
            'del', 'delete', 'remove' => $this->cmdSpamDel($sourceUid, array_slice($args, 1)),
            'reload' => $this->cmdSpamReload($sourceUid),
            default => $this->reply($sourceUid, 'Uso: spam list|add <patrón>|del <n>|reload'),
        };
    }

    private function cmdSpamList(string $sourceUid): void
    {
        $patterns = $this->repository->allSpamPatterns();

        if ([] === $patterns) {
            $this->reply($sourceUid, 'No hay patrones cargados.');

            return;
        }

        foreach ($patterns as $pattern) {
            $this->reply($sourceUid, sprintf('%d: %s', $pattern['id'], $pattern['pattern']));
        }
    }

    /**
     * @param list<string> $args
     */
    private function cmdSpamAdd(string $sourceUid, array $args): void
    {
        $pattern = implode(' ', $args);

        if ('' === $pattern) {
            $this->reply($sourceUid, 'Uso: spam add <patrón>');

            return;
        }

        $id = $this->repository->addSpamPattern($pattern);
        $this->reply($sourceUid, sprintf('Añadido [%d]: %s', $id, $pattern));
    }

    /**
     * @param list<string> $args
     */
    private function cmdSpamDel(string $sourceUid, array $args): void
    {
        $position = (int) ($args[0] ?? '0');

        if ($position < 1) {
            $this->reply($sourceUid, 'Uso: spam del <n>');

            return;
        }

        $ok = $this->repository->removeSpamPattern($position);
        $this->reply($sourceUid, $ok ? 'Patrón eliminado.' : 'No existe ese número.');
    }

    private function cmdSpamReload(string $sourceUid): void
    {
        $this->reply($sourceUid, sprintf('Recargados %d patrones.', count($this->repository->allSpamPatterns())));
    }

    private function detectFlood(string $channel, string $uid, string $text): ?string
    {
        if (0 === $this->config->floodWindow || 0 === $this->config->floodMessages) {
            return null;
        }

        $key = sprintf('%s|%s', strtolower($channel), $uid);
        $now = ($this->now)();
        $history = [];

        foreach ($this->floodWindow[$key] ?? [] as $entry) {
            if ($now - $entry['time'] <= $this->config->floodWindow) {
                $history[] = $entry;
            }
        }

        $history[] = ['time' => $now, 'text' => $text];
        $this->floodWindow[$key] = $history;

        if ($this->config->floodLength > 0 && strlen($text) >= $this->config->floodLength) {
            return sprintf('mensaje de %d caracteres', strlen($text));
        }

        if (count($history) > $this->config->floodMessages) {
            return sprintf('%d mensajes en %ds', count($history), $this->config->floodWindow);
        }

        if ($this->config->floodRepeat > 0) {
            $repeated = 0;

            foreach ($history as $entry) {
                if (strtolower($entry['text']) === strtolower($text)) {
                    ++$repeated;
                }
            }

            if ($repeated >= $this->config->floodRepeat) {
                return sprintf('mensaje repetido %d veces', $repeated);
            }
        }

        return null;
    }

    public function forgetFlood(string $uid): void
    {
        foreach (array_keys($this->floodWindow) as $key) {
            if (str_contains($key, '|'.$uid)) {
                unset($this->floodWindow[$key]);
            }
        }
    }

    private function matchesSpamPattern(string $text): bool
    {
        foreach ($this->repository->allSpamPatterns() as $pattern) {
            $raw = $pattern['pattern'];

            if (strtolower(substr($raw, 0, 3)) === 're:') {
                $regex = substr($raw, 3);

                if ('' !== $regex && 1 === preg_match('/'.$regex.'/i', $text)) {
                    return true;
                }

                continue;
            }

            if (str_contains(strtolower($text), strtolower($raw))) {
                return true;
            }
        }

        return false;
    }

    private function isWatched(string $channel): bool
    {
        foreach ($this->config->watched as $watched) {
            if (0 === strcasecmp($watched, $channel)) {
                return true;
            }
        }

        return 0 === strcasecmp($channel, $this->config->sink);
    }

    private function isProtected(string $uid, NetworkState $state): bool
    {
        $nick = $state->nickOf($uid) ?? '';
        $host = $state->hostOf($uid) ?? '';

        if ($uid === $this->moduleUid) {
            return true;
        }

        if ($this->isAdmin($nick, $host)) {
            return true;
        }

        if ($this->isIgnored($nick, $host)) {
            return true;
        }

        return false;
    }

    private function isAdmin(string $nick, string $host): bool
    {
        foreach ($this->config->admins as $entry) {
            if ($this->matchesMask($nick, $entry) || $this->matchesMask($host, $entry)) {
                return true;
            }
        }

        foreach ($this->repository->allOperators() as $entry) {
            if ($this->matchesMask($nick, $entry) || $this->matchesMask($host, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function isIgnored(string $nick, string $host): bool
    {
        foreach ($this->repository->allIgnored() as $entry) {
            if ($this->matchesMask($nick, $entry) || $this->matchesMask($host, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function matchesMask(string $value, string $pattern): bool
    {
        if ('' === $pattern) {
            return false;
        }

        if (!str_contains($pattern, '*') && !str_contains($pattern, '?')) {
            return 0 === strcasecmp($value, $pattern);
        }

        return fnmatch(strtolower($pattern), strtolower($value));
    }

    private function reply(string $targetUid, string $text): void
    {
        ($this->reply)($targetUid, $text);
    }

    private function notice(string $targetUid, string $text): void
    {
        ($this->reply)($targetUid, $text);
    }
}
