<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Wexample\SymfonyAccounting\Entity\Account;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The chart of accounts of a ledger, by number: the one its country loaded,
 * and the accounts created on first use. Each leads to its general ledger.
 */
#[Route(path: '/accounting/{ledger}/chart/', name: 'accounting_chart_', requirements: ['ledger' => Requirement::UUID])]
#[IsGranted('IS_AUTHENTICATED')]
final class ChartController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        string $ledger,
        Request $request,
        AccountRepository $accounts,
    ): Response {
        $ledger = $this->ledger($ledger);
        $search = mb_strtolower(trim($request->query->getString('search')));
        $class = $request->query->getString('class');

        $list = array_values(array_filter(
            $accounts->findByLedger($ledger),
            static fn (Account $account) => ('' === $class || (string) $account->getClass() === $class)
                && ('' === $search || str_starts_with($account->getNumber(), $search) || str_contains(mb_strtolower($account->getLabel()), $search))
        ));

        return $this->renderPage(self::ROUTE_INDEX, [
            'ledger' => $ledger,
            'search' => $search,
            'accounts' => $list,
        ]);
    }
}
