<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyAccountingDs\Service\FormProcessor\PartyFormProcessor;
use Wexample\SymfonyForms\Attribute\EntityFormProcessor;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The customers and suppliers of a ledger: listed by role, one started by its
 * name alone — its auxiliary code given at once —, then filled in on its page,
 * where its documents are listed too.
 */
#[Route(path: '/accounting/{ledger}/parties/', name: 'accounting_parties_', requirements: ['ledger' => Requirement::UUID])]
final class PartiesController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    final public const string ROUTE_CREATE = 'create';

    final public const string ROUTE_EDIT = 'edit';

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        string $ledger,
        Request $request,
        PartyRepository $parties,
    ): Response {
        $ledger = $this->ledger($ledger);
        $role = $request->query->getString('role');
        $search = trim($request->query->getString('search'));

        $query = $parties->createQueryBuilder('party')
            ->join('party.organization', 'organization')
            ->addSelect('organization')
            ->andWhere('party.ledger = :ledger')
            ->setParameter('ledger', $ledger->getId(), 'uuid')
            ->orderBy('organization.name', 'ASC');

        if (in_array($role, ['customer', 'supplier'], true)) {
            $query->andWhere("party.$role = true");
        }

        if ('' !== $search) {
            $query->andWhere('LOWER(organization.name) LIKE :search OR LOWER(party.customerCode) LIKE :search OR LOWER(party.supplierCode) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $this->renderPage(self::ROUTE_INDEX, [
            'ledger' => $ledger,
            'search' => $search,
            'parties' => $query->getQuery()->getResult(),
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
        ]);
    }

    #[Route(path: 'create', name: self::ROUTE_CREATE, methods: [Request::METHOD_POST])]
    public function create(
        string $ledger,
        Request $request,
        PartyService $partyService,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $request, $partyService): string {
            $name = trim($request->request->getString('name'));

            if ('' === $name) {
                throw new AccountingException('A party needs a name.');
            }

            $role = $request->request->getString('role');
            $party = $partyService->create($ledger, $name, 'supplier' !== $role, 'customer' !== $role);
            $this->entityManager->flush();

            return $this->generateUrl('accounting_parties_edit', ['ledger' => (string) $ledger->getId(), 'id' => (string) $party->getId()]);
        }, 'created');
    }

    #[EntityFormProcessor(PartyFormProcessor::class, Party::class)]
    #[Route(path: '{id}', name: self::ROUTE_EDIT, requirements: ['id' => Requirement::UUID])]
    public function edit(
        string $ledger,
        FormInterface $partyForm,
        InvoiceRepository $invoices,
    ): Response {
        $ledger = $this->ledger($ledger);
        /** @var Party $party */
        $party = $partyForm->getData();

        if ($party->getLedger() !== $ledger) {
            throw $this->createNotFoundException();
        }

        return $this->renderPage(self::ROUTE_EDIT, [
            'ledger' => $ledger,
            'party' => $party,
            'form_party' => $partyForm->createView(),
            'documents' => $invoices->findBy(['party' => $party], ['dateInvoice' => 'DESC'], 50),
        ]);
    }
}
