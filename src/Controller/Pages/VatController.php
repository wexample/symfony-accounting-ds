<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Vat\OssService;
use Wexample\SymfonyAccounting\Service\Vat\VatDeductionRegularizationService;
use Wexample\SymfonyAccounting\Service\Vat\VatPositionService;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The VAT of a ledger: where the year stands, period by period — collected,
 * deductible, the deposits paid and expected —; one period's return with its
 * lines and the boxes of the country's forms, and its settlement booked. The
 * one-stop shop's return apart, by country and rate; and the share of VAT
 * deducted set right at the year's end when the ledger deducts only part of it.
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
     * The one-stop shop's return of a quarter: distance sales by country and
     * rate, the year's total against the threshold, the CSV of the portal.
     */
    #[Route(path: 'oss', name: 'oss', methods: [Request::METHOD_GET])]
    public function oss(
        string $ledger,
        Request $request,
        OssService $oss,
    ): Response {
        $ledger = $this->ledger($ledger);
        [$from, $to] = $this->quarter($request->query->getString('from'));
        $lines = $oss->computeReturn($ledger, $from, $to);

        return $this->renderPage('oss', [
            'ledger' => $ledger,
            'from' => $from,
            'to' => $to,
            'lines' => $lines,
            'distance_sales' => $oss->getDistanceSales($ledger, (int) $from->format('Y')),
            'threshold' => OssService::THRESHOLD,
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    #[Route(path: 'oss/csv', name: 'oss_csv', methods: [Request::METHOD_GET])]
    public function ossCsv(
        string $ledger,
        Request $request,
        OssService $oss,
    ): Response {
        $ledger = $this->ledger($ledger);
        [$from, $to] = $this->quarter($request->query->getString('from'));
        $response = new Response($oss->exportCsv($ledger, $oss->computeReturn($ledger, $from, $to)), Response::HTTP_OK, ['Content-Type' => 'text/csv']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('oss-%s.csv', $from->format('Y-m'))));

        return $response;
    }

    #[Route(path: 'oss/settle', name: 'oss_settle', methods: [Request::METHOD_POST])]
    public function ossSettle(
        string $ledger,
        Request $request,
        OssService $oss,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);
        [$from, $to] = $this->quarter($request->request->getString('from'));

        return $this->act($request, function () use ($ledger, $from, $to, $oss): string {
            $entry = $oss->settle($ledger, $from, $to);

            return $this->generateUrl('accounting_entries_show', ['ledger' => (string) $ledger->getId(), 'id' => (string) $entry->getId()]);
        }, 'settled');
    }

    /**
     * Partial deduction for a year: what was eligible, what the provisional
     * coefficient deducted, what the final one would have — and the entry
     * setting it right.
     */
    #[Route(path: 'deduction', name: 'deduction', methods: [Request::METHOD_GET])]
    public function deduction(
        string $ledger,
        Request $request,
        VatDeductionRegularizationService $regularization,
    ): Response {
        $ledger = $this->ledger($ledger);
        $year = $request->query->getInt('year', (int) date('Y'));
        $final = $request->query->getString('rate');
        // The final coefficient as typed, in percent; else the one set for the year.
        $rate = '' === $final
            ? (int) ($ledger->getSetting('vat_deduction_rates', [])[$year] ?? $ledger->getSetting('vat_deduction_rate', 10000))
            : (int) round((float) str_replace(',', '.', $final) * 100);

        return $this->renderPage('deduction', [
            'ledger' => $ledger,
            'year' => $year,
            'years' => range((int) date('Y'), (int) date('Y') - 4),
            'rate' => $rate,
            'computed' => $regularization->compute($ledger, $year, $rate),
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    #[Route(path: 'deduction/regularize', name: 'regularize', methods: [Request::METHOD_POST])]
    public function regularize(
        string $ledger,
        Request $request,
        VatDeductionRegularizationService $regularization,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $request, $regularization): ?string {
            $entry = $regularization->regularize($ledger, $request->request->getInt('year'), $request->request->getInt('rate'));

            return $entry ? $this->generateUrl('accounting_entries_show', ['ledger' => (string) $ledger->getId(), 'id' => (string) $entry->getId()]) : null;
        }, 'regularized');
    }

    /**
     * The quarter a date falls in, today's when none is given.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function quarter(string $date): array
    {
        try {
            $day = new DateTimeImmutable($date ?: 'today');
        } catch (\Exception) {
            throw $this->createNotFoundException();
        }

        $month = intdiv((int) $day->format('n') - 1, 3) * 3 + 1;
        $from = $day->setDate((int) $day->format('Y'), $month, 1)->setTime(0, 0);

        return [$from, $from->modify('+3 months -1 day')];
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
