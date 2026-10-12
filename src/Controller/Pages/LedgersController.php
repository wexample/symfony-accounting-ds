<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerAccessService;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyAccountingDs\Service\LedgerOverviewService;
use Wexample\SymfonyAccountingDs\Service\FormProcessor\LedgerSettingsFormProcessor;
use Wexample\SymfonyForms\Attribute\EntityFormProcessor;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The ledgers the reader may open, one of them at a glance — what is owed to
 * it and by it, what waits to be done —, and its settings.
 */
#[Route(path: '/accounting/', name: 'accounting_')]
final class LedgersController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    final public const string ROUTE_SHOW = 'show';

    final public const string ROUTE_SETTINGS = 'settings';

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        LedgerAccessService $access,
        LedgerOverviewService $overview,
    ): Response {
        $ledgers = $access->getAccessible();

        // One ledger only: it is the one to open.
        if (1 === count($ledgers)) {
            return $this->redirectToRoute('accounting_show', ['id' => (string) $ledgers[0]->getId()]);
        }

        return $this->renderPage(self::ROUTE_INDEX, [
            'ledgers' => array_map(fn (Ledger $ledger) => $overview->summary($ledger), $ledgers),
        ]);
    }

    #[Route(path: '{id}', name: self::ROUTE_SHOW, requirements: ['id' => Requirement::UUID])]
    public function show(
        string $id,
        LedgerOverviewService $overview,
    ): Response {
        $ledger = $this->ledger($id);

        return $this->renderPage(self::ROUTE_SHOW, [
            'ledger' => $ledger,
            'overview' => $overview->overview($ledger),
        ]);
    }

    #[EntityFormProcessor(LedgerSettingsFormProcessor::class, Ledger::class)]
    #[Route(path: '{id}/settings', name: self::ROUTE_SETTINGS, requirements: ['id' => Requirement::UUID])]
    public function settings(FormInterface $ledgerSettingsForm): Response
    {
        $ledger = $this->reachable($ledgerSettingsForm->getData(), LedgerPermission::ADMINISTER);

        return $this->renderPage(self::ROUTE_SETTINGS, [
            'ledger' => $ledger,
            'form_settings' => $ledgerSettingsForm->createView(),
        ]);
    }
}
