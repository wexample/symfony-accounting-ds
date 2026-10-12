<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Vat\VatPositionService;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The VAT of a ledger: where the year stands, period by period — collected,
 * deductible, the deposits paid and expected —; one period's return with its
 * lines and the boxes of the country's forms, and its settlement booked.
 */
#[Route(path: '/accounting/{ledger}/vat/', name: 'accounting_vat_', requirements: ['ledger' => Requirement::UUID])]
final class VatController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        string $ledger,
        Request $request,
        VatPositionService $positions,
    ): Response {
        $ledger = $this->ledger($ledger);
        $year = $request->query->getInt('year', (int) date('Y'));

        return $this->renderPage(self::ROUTE_INDEX, [
            'ledger' => $ledger,
            'year' => $year,
            'years' => range((int) date('Y'), (int) date('Y') - 4),
            'position' => $positions->build($ledger, $year),
        ]);
    }

    #[Route(path: 'return', name: 'return', methods: [Request::METHOD_GET])]
    public function return(
        string $ledger,
        Request $request,
        VatReturnService $returns,
    ): Response {
        $ledger = $this->ledger($ledger);
        [$from, $to] = $this->period($request->query->getString('from'), $request->query->getString('to'));
        $vatReturn = $returns->compute($ledger, $from, $to);

        return $this->renderPage('return', [
            'ledger' => $ledger,
            'vat_return' => $vatReturn,
            'forms' => $returns->fillForms($vatReturn),
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    /**
     * The return of the period booked: what is due moved to the account
     * paid to the State, or the credit carried forward.
     */
    #[Route(path: 'settle', name: 'settle', methods: [Request::METHOD_POST])]
    public function settle(
        string $ledger,
        Request $request,
        VatReturnService $returns,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);
        [$from, $to] = $this->period($request->request->getString('from'), $request->request->getString('to'));

        return $this->act($request, function () use ($ledger, $from, $to, $returns): string {
            $entry = $returns->settle($returns->compute($ledger, $from, $to));

            return $this->generateUrl('accounting_entries_show', ['ledger' => (string) $ledger->getId(), 'id' => (string) $entry->getId()]);
        }, 'settled');
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function period(
        string $from,
        string $to
    ): array {
        try {
            return [new DateTimeImmutable($from ?: 'first day of this month'), new DateTimeImmutable($to ?: 'last day of this month')];
        } catch (\Exception) {
            throw $this->createNotFoundException();
        }
    }
}
