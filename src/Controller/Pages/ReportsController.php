<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Service\Exchange\LedgerExportService;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Report\FinancialStatementService;
use Wexample\SymfonyAccounting\Service\Report\GeneralLedgerService;
use Wexample\SymfonyAccounting\Service\Report\TrialBalanceService;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * What the books say for a fiscal year: the trial balance, the statements of
 * the ledger's country — balance sheet, income statement, the forms that
 * follow them —, the general ledger of an account, and the exports. A Belgian
 * ledger also gets its annual accounts as the National Bank's XBRL instance,
 * when symfony-accounting-be is installed.
 */
#[Route(path: '/accounting/{ledger}/reports/', name: 'accounting_reports_', requirements: ['ledger' => Requirement::UUID])]
final class ReportsController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    /**
     * symfony-accounting-be's writer of the NBB's XBRL, named rather than
     * imported: this package does not require the Belgian one. Missing, the
     * argument asking for it is given null.
     */
    private const string NBB_WRITER = 'Wexample\\SymfonyAccountingBe\\Service\\Nbb\\NbbXbrlWriter';

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        string $ledger,
        Request $request,
        FiscalYearRepository $fiscalYears,
        TrialBalanceService $trialBalances,
        JurisdictionRegistry $jurisdictions,
        LedgerExportService $exports,
        #[Autowire(service: self::NBB_WRITER)]
        ?object $nbbWriter = null,
    ): Response {
        $ledger = $this->ledger($ledger);
        [$fiscalYear, $years] = $this->fiscalYear($ledger, $request, $fiscalYears);

        return $this->renderPage(self::ROUTE_INDEX, [
            'nbb' => $nbbWriter && 'BE' === $ledger->getCountry()?->getIsoAlpha2Code(),
            'ledger' => $ledger,
            'fiscal_year' => $fiscalYear,
            'fiscal_years' => $years,
            'trial_balance' => $fiscalYear ? $trialBalances->build($ledger, $fiscalYear) : null,
            'statements' => array_map(static fn ($definition) => ['key' => $definition->key, 'label' => $definition->label], $jurisdictions->forLedger($ledger)->getReportDefinitions()),
            'exports' => $fiscalYear ? $exports->getChoices($fiscalYear) : [],
        ]);
    }

    #[Route(path: 'statement/{key}', name: 'statement', requirements: ['key' => '[a-z0-9_]+'])]
    public function statement(
        string $ledger,
        string $key,
        Request $request,
        FiscalYearRepository $fiscalYears,
        FinancialStatementService $statements,
    ): Response {
        $ledger = $this->ledger($ledger);
        [$fiscalYear, $years] = $this->fiscalYear($ledger, $request, $fiscalYears);

        if (! $fiscalYear) {
            throw $this->createNotFoundException();
        }

        try {
            $result = $statements->build($fiscalYear, $key);
        } catch (AccountingException|\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        return $this->renderPage('statement', [
            'ledger' => $ledger,
            'fiscal_year' => $fiscalYear,
            'fiscal_years' => $years,
            'result' => $result,
        ]);
    }

    #[Route(path: 'general-ledger', name: 'general_ledger')]
    public function generalLedger(
        string $ledger,
        Request $request,
        FiscalYearRepository $fiscalYears,
        GeneralLedgerService $generalLedger,
    ): Response {
        $ledger = $this->ledger($ledger);
        [$fiscalYear, $years] = $this->fiscalYear($ledger, $request, $fiscalYears);
        $account = trim($request->query->getString('account'));

        return $this->renderPage('general_ledger', [
            'ledger' => $ledger,
            'fiscal_year' => $fiscalYear,
            'fiscal_years' => $years,
            'account' => $account,
            'accounts' => $fiscalYear && '' !== $account ? $generalLedger->build($ledger, $fiscalYear, accountPrefix: $account) : [],
        ]);
    }

    #[Route(path: 'export/{key}', name: 'export', requirements: ['key' => '[a-z0-9_\-]+'])]
    public function export(
        string $ledger,
        string $key,
        Request $request,
        FiscalYearRepository $fiscalYears,
        LedgerExportService $exports,
    ): Response {
        $ledger = $this->ledger($ledger);
        [$fiscalYear] = $this->fiscalYear($ledger, $request, $fiscalYears);

        if (! $fiscalYear || ! array_key_exists($key, $exports->getChoices($fiscalYear))) {
            throw $this->createNotFoundException();
        }

        $file = $exports->export($fiscalYear, $key);
        $response = new Response($file->content, Response::HTTP_OK, ['Content-Type' => $file->mimeType]);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->filename));

        return $response;
    }

    /**
     * The annual accounts of a Belgian ledger's fiscal year as the XBRL
     * instance the National Bank takes in: the micro or abridged model, the
     * date the general meeting approved them.
     */
    #[Route(path: 'nbb', name: 'nbb', methods: [Request::METHOD_POST])]
    public function nbb(
        string $ledger,
        Request $request,
        FiscalYearRepository $fiscalYears,
        #[Autowire(service: self::NBB_WRITER)]
        ?object $nbbWriter = null,
    ): Response {
        $ledger = $this->ledger($ledger);
        $fiscalYear = $fiscalYears->find($request->request->getString('fiscalYear'));

        if (! $nbbWriter || ! $fiscalYear || $fiscalYear->getLedger() !== $ledger) {
            throw $this->createNotFoundException();
        }

        try {
            $xbrl = $nbbWriter->write(
                $fiscalYear,
                new DateTimeImmutable($request->request->getString('approval') ?: 'today'),
                'abridged' === $request->request->getString('model') ? 'abridged' : 'micro',
            );
        } catch (AccountingException $exception) {
            $this->notify('error', $exception->getMessage());

            return $this->redirectToRoute('accounting_reports_index', ['ledger' => (string) $ledger->getId(), 'fiscalYear' => (string) $fiscalYear->getId()]);
        }

        $response = new Response($xbrl, Response::HTTP_OK, ['Content-Type' => 'application/xml']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('annual-accounts-%s.xbrl', $fiscalYear->getLabel())));

        return $response;
    }

    /**
     * The fiscal year asked (`?fiscalYear=`), else the one of today, else the
     * last; and all of them, the latest first.
     *
     * @return array{?FiscalYear, list<FiscalYear>}
     */
    private function fiscalYear(
        Ledger $ledger,
        Request $request,
        FiscalYearRepository $fiscalYears
    ): array {
        $years = array_reverse($fiscalYears->findByLedger($ledger));
        $asked = $request->query->getString('fiscalYear');

        foreach ($years as $year) {
            if ((string) $year->getId() === $asked) {
                return [$year, $years];
            }
        }

        return [$fiscalYears->findForDate($ledger, new \DateTimeImmutable()) ?? ($years[0] ?? null), $years];
    }
}
