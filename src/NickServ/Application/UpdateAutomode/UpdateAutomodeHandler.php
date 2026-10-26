<?php

declare(strict_types=1);

namespace Dbots\NickServ\Application\UpdateAutomode;

use Dbots\Domain\Account\Account;
use Dbots\Domain\Account\Nick;
use Dbots\Irc\Application\NetworkState;
use Dbots\NickServ\Application\Port\Out\AccountRepositoryInterface;
use InvalidArgumentException;

use function in_array;
use function preg_match;
use function str_replace;
use function str_split;
use function strtoupper;

final readonly class UpdateAutomodeHandler
{
    /** @var list<string> */
    private array $blockedModes;

    /**
     * @param list<string> $blockedModes
     */
    public function __construct(
        private AccountRepositoryInterface $accounts,
        array $blockedModes = ['B', 'A', 'o', 'k', 'q', 'z'],
    ) {
        $this->blockedModes = array_map(strtoupper(...), $blockedModes);
    }

    public function __invoke(string $uid, string $targetNick, ?string $modes, NetworkState $state): UpdateAutomodeResult
    {
        if (!$state->hasMode($uid, 'r')) {
            return UpdateAutomodeResult::notIdentified();
        }

        $requesterNick = $state->nickOf($uid);

        if (null === $requesterNick) {
            return UpdateAutomodeResult::notIdentified();
        }

        $isOper = $state->hasMode($uid, 'o');

        if (!$isOper && strtoupper($requesterNick) !== strtoupper($targetNick)) {
            return UpdateAutomodeResult::notAuthorized();
        }

        try {
            $account = $this->accounts->findByNick(new Nick($targetNick));
        } catch (InvalidArgumentException) {
            return UpdateAutomodeResult::unknownAccount();
        }

        if (null === $account) {
            return UpdateAutomodeResult::unknownAccount();
        }

        if (null === $modes || '' === $modes) {
            $this->accounts->update($this->withoutAutomode($account));

            return UpdateAutomodeResult::cleared();
        }

        $clean = str_replace(['+', '-'], '', $modes);

        if (!preg_match('/^[a-zA-Z]+$/', $clean)) {
            return UpdateAutomodeResult::invalidValue('Syntax: AUTOMODE <nick> [modes]');
        }

        $invalid = [];

        foreach (str_split($clean) as $mode) {
            if (in_array(strtoupper($mode), $this->blockedModes, true)) {
                $invalid[] = $mode;
            }
        }

        if ([] !== $invalid) {
            return UpdateAutomodeResult::invalidValue(sprintf(
                'Mode%s %s cannot be set via AUTOMODE.',
                1 === count($invalid) ? '' : 's',
                implode(', ', $invalid),
            ));
        }

        $this->accounts->update($this->withAutomode($account, $clean));

        return UpdateAutomodeResult::changed();
    }

    private function withAutomode(Account $account, string $modes): Account
    {
        return new Account(
            $account->nick,
            $account->passwordHash,
            $account->email,
            $account->createdAt,
            $account->forbidReason,
            $account->suspendReason,
            $account->swhois,
            $account->vhost,
            $account->noexpire,
            $account->url,
            $account->quitVisible,
            $account->private,
            $account->kill,
            $modes,
        );
    }

    private function withoutAutomode(Account $account): Account
    {
        return new Account(
            $account->nick,
            $account->passwordHash,
            $account->email,
            $account->createdAt,
            $account->forbidReason,
            $account->suspendReason,
            $account->swhois,
            $account->vhost,
            $account->noexpire,
            $account->url,
            $account->quitVisible,
            $account->private,
            $account->kill,
            null,
        );
    }
}
