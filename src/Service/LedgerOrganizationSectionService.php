<?php

namespace Wexample\SymfonyAccountingDs\Service;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyOrganization\Entity\Organization;
use Wexample\SymfonyOrganizationDs\Interface\OrganizationSectionInterface;

/**
 * The books of an organization in the menu of its pages: its ledger, and its
 * bank — for a reader who may open the ledger.
 */
class LedgerOrganizationSectionService implements OrganizationSectionInterface
{
    public function __construct(
        private readonly LedgerRepository $ledgerRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function getSections(
        Organization $organization,
        ?string $currentRoute
    ): array {
        $ledger = $this->ledgerRepository->findOneBy(['organization' => $organization]);

        if (! $ledger instanceof Ledger || ! $this->authorizationChecker->isGranted(LedgerPermission::VIEW->value, $ledger)) {
            return [];
        }

        $t = 'WexampleSymfonyAccountingDsBundle.common.accounting::tab.';

        return [
            ['label' => $t.'accounting', 'icon' => 'ph:bold/calculator', 'route' => 'accounting_show', 'params' => ['id' => (string) $ledger->getId()]],
            ['label' => $t.'bank', 'icon' => 'ph:bold/bank', 'route' => 'accounting_bank_index', 'params' => ['ledger' => (string) $ledger->getId()], 'active' => str_starts_with((string) $currentRoute, 'accounting_bank_')],
        ];
    }
}
