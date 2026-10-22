<?php

namespace Wexample\SymfonyAccountingDs\Service;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyOrganization\Entity\Organization;
use Wexample\SymfonyOrganizationDs\Interface\OrganizationSectionInterface;

/**
 * The books of an organization in the navigation of its pages: its ledger,
 * every view of it folded under — the bank among them —, for a reader who may
 * open the ledger. The pages of the ledger show the organization's navigation
 * (layouts/ledger), so the books are always read inside their organization.
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
        $id = (string) $ledger->getId();
        $ledgerParams = ['ledger' => $id];

        // A view per family of pages: lit on every page of the family.
        $view = static fn (string $name, string $icon, string $family): array => [
            'label' => $t.$name,
            'icon' => $icon,
            'route' => 'accounting_'.$family.'_index',
            'params' => $ledgerParams,
            'match' => 'accounting_'.$family.'_',
        ];

        return [
            [
                'label' => $t.'accounting',
                'icon' => 'ph:bold/calculator',
                'route' => 'accounting_show',
                'params' => ['id' => $id],
                'children' => [
                    ['label' => $t.'overview', 'icon' => 'ph:bold/gauge', 'route' => 'accounting_show', 'params' => ['id' => $id]],
                    $view('documents', 'ph:bold/receipt', 'documents'),
                    $view('parties', 'ph:bold/address-book', 'parties'),
                    $view('bank', 'ph:bold/bank', 'bank'),
                    $view('entries', 'ph:bold/notebook', 'entries'),
                    $view('fiscal_years', 'ph:bold/calendar-check', 'fiscal_years'),
                    $view('vat', 'ph:bold/percent', 'vat'),
                    $view('reports', 'ph:bold/chart-bar', 'reports'),
                    $view('chart', 'ph:bold/list-numbers', 'chart'),
                    ['label' => $t.'settings', 'icon' => 'ph:bold/gear', 'route' => 'accounting_settings', 'params' => ['id' => $id]],
                ],
            ],
        ];
    }
}
