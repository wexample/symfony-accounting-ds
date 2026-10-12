<?php

namespace Wexample\SymfonyAccountingDs\Controller\Pages;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Document\ElectronicInvoiceReceiver;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccountingDs\Controller\AbstractAccountingController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The documents of a ledger — invoices, credit notes, quotations, sales and
 * purchases —: listed and filtered, one of them read, a draft written in its
 * editor, and what can be done to it: issue it, bill a quotation, copy it,
 * cancel it by a credit note. A structured invoice received is dropped here
 * and becomes a purchase draft.
 */
#[Route(path: '/accounting/{ledger}/documents/', name: 'accounting_documents_', requirements: ['ledger' => Requirement::UUID])]
final class DocumentsController extends AbstractAccountingController
{
    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    final public const string ROUTE_NEW = 'new';

    final public const string ROUTE_CREATE = 'create';

    final public const string ROUTE_RECEIVE = 'receive';

    final public const string ROUTE_SHOW = 'show';

    final public const string ROUTE_EMIT = 'emit';

    final public const string ROUTE_BILL = 'bill';

    final public const string ROUTE_DUPLICATE = 'duplicate';

    final public const string ROUTE_CREDIT = 'credit';

    final public const string ROUTE_DELETE = 'delete';

    /**
     * What a reader can start, as type and direction.
     */
    final public const array STARTS = [
        ['bill', 'sale'],
        ['quotation', 'sale'],
        ['bill', 'purchase'],
    ];

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(
        string $ledger,
        Request $request,
        InvoiceRepository $invoices,
    ): Response {
        $ledger = $this->ledger($ledger);
        $filters = [
            'type' => $request->query->getString('type'),
            'direction' => $request->query->getString('direction'),
            'status' => $request->query->getString('status'),
            'year' => $request->query->getString('year'),
            'search' => trim($request->query->getString('search')),
        ];

        $query = $invoices->createQueryBuilder('invoice')
            ->leftJoin('invoice.party', 'party')
            ->andWhere('invoice.ledger = :ledger')
            ->setParameter('ledger', $ledger->getId(), 'uuid')
            ->orderBy('invoice.dateInvoice', 'DESC')
            ->addOrderBy('invoice.number', 'DESC')
            ->setMaxResults(200);

        foreach (['type', 'direction', 'status'] as $field) {
            if ($values = array_values(array_filter(explode(',', $filters[$field])))) {
                $query->andWhere("invoice.$field IN (:$field)")->setParameter($field, $values);
            }
        }

        if (ctype_digit($filters['year'])) {
            $query->andWhere('invoice.dateInvoice >= :from AND invoice.dateInvoice < :to')
                ->setParameter('from', new DateTimeImmutable($filters['year'].'-01-01'), 'date_immutable')
                ->setParameter('to', new DateTimeImmutable(((int) $filters['year'] + 1).'-01-01'), 'date_immutable');
        }

        if ('' !== $filters['search']) {
            $query->andWhere('LOWER(invoice.number) LIKE :search OR LOWER(invoice.title) LIKE :search OR LOWER(party.name) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($filters['search']).'%');
        }

        $today = new DateTimeImmutable('today');

        return $this->renderPage(self::ROUTE_INDEX, [
            'ledger' => $ledger,
            'filters' => $filters,
            'years' => $this->years($ledger, $invoices),
            'documents' => array_map(fn (Invoice $invoice) => [
                'invoice' => $invoice,
                'href' => $this->generateUrl('accounting_documents_show', ['ledger' => (string) $ledger->getId(), 'id' => (string) $invoice->getId()]),
                'late' => $invoice->getType()->isPayable() && ! $invoice->isEditable() && $invoice->calcDateDue() < $today,
                'total' => $invoice->calcPriceBreakdown()->getFinal(),
                'due' => $invoice->getType()->isPayable() && ! $invoice->isEditable() ? $invoice->calcRemainingAmount() : null,
            ], $query->getQuery()->getResult()),
        ]);
    }

    #[Route(path: 'new', name: self::ROUTE_NEW, methods: [Request::METHOD_GET])]
    public function new(string $ledger): Response
    {
        return $this->renderPage(self::ROUTE_NEW, [
            'ledger' => $this->ledger($ledger, LedgerPermission::EDIT),
            'starts' => self::STARTS,
        ]);
    }

    /**
     * A draft of the kind asked, opened in its editor.
     */
    #[Route(path: 'create', name: self::ROUTE_CREATE, methods: [Request::METHOD_POST])]
    public function create(
        string $ledger,
        Request $request,
        InvoiceFactory $factory,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $request, $factory): string {
            $invoice = $factory->create(
                $ledger,
                InvoiceType::tryFrom($request->request->getString('type')) ?? InvoiceType::Bill,
                InvoiceDirection::tryFrom($request->request->getString('direction')) ?? InvoiceDirection::Sale,
            );
            $this->entityManager->flush();

            return $this->showUrl($invoice);
        }, 'created');
    }

    /**
     * A structured invoice received — Factur-X, CII, UBL —, made a purchase
     * draft: what the reading noticed is said, the draft opened.
     */
    #[Route(path: 'receive', name: self::ROUTE_RECEIVE, methods: [Request::METHOD_POST])]
    public function receive(
        string $ledger,
        Request $request,
        ElectronicInvoiceReceiver $receiver,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);

        return $this->act($request, function () use ($ledger, $request, $receiver): string {
            $file = $request->files->get('file');

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw new AccountingException('Choose the invoice file to receive.');
            }

            $result = $receiver->receive($ledger, (string) file_get_contents($file->getPathname()));
            $this->entityManager->flush();

            foreach ($result->warnings as $warning) {
                $this->notify('warning', $warning);
            }

            return $this->showUrl($result->invoice);
        }, 'received');
    }

    #[Route(path: '{id}', name: self::ROUTE_SHOW, requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_GET])]
    public function show(
        string $ledger,
        string $id,
        InvoiceRepository $invoices,
        PartyRepository $parties,
        JurisdictionRegistry $jurisdictions,
    ): Response {
        $ledger = $this->ledger($ledger);
        $invoice = $this->document($ledger, $id, $invoices);

        return $this->renderPage(self::ROUTE_SHOW, [
            'ledger' => $ledger,
            'invoice' => $invoice,
            'editable' => $invoice->isEditable() && $this->isGranted(LedgerPermission::EDIT->value, $ledger),
            'can_edit' => $this->isGranted(LedgerPermission::EDIT->value, $ledger),
            'breakdown' => $invoice->calcPriceBreakdown(),
            'parties' => array_map(static fn ($party) => ['id' => (string) $party->getId(), 'name' => $party->getName()], $parties->findBy(['ledger' => $ledger], ['name' => 'ASC'])),
            'vat_rates' => $jurisdictions->forLedger($ledger)->getVatRates(),
            'relations' => $invoice->getOutgoingRelations(),
        ]);
    }

    #[Route(path: '{id}/emit', name: self::ROUTE_EMIT, requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function emit(
        string $ledger,
        string $id,
        Request $request,
        InvoiceRepository $invoices,
        InvoiceEmissionService $emission,
    ): Response {
        $invoice = $this->document($this->ledger($ledger, LedgerPermission::EDIT), $id, $invoices);

        return $this->act($request, function () use ($invoice, $emission) {
            $emission->emit($invoice);

            return null;
        }, 'emitted');
    }

    #[Route(path: '{id}/bill', name: self::ROUTE_BILL, requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function bill(
        string $ledger,
        string $id,
        Request $request,
        InvoiceRepository $invoices,
        InvoiceFactory $factory,
    ): Response {
        $quotation = $this->document($this->ledger($ledger, LedgerPermission::EDIT), $id, $invoices);

        return $this->act($request, function () use ($quotation, $factory): string {
            $bill = $factory->createBillFromQuotation($quotation);
            $this->entityManager->flush();

            return $this->showUrl($bill);
        }, 'billed');
    }

    #[Route(path: '{id}/duplicate', name: self::ROUTE_DUPLICATE, requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function duplicate(
        string $ledger,
        string $id,
        Request $request,
        InvoiceRepository $invoices,
        InvoiceFactory $factory,
    ): Response {
        $source = $this->document($this->ledger($ledger, LedgerPermission::EDIT), $id, $invoices);

        return $this->act($request, function () use ($source, $factory): string {
            $copy = $factory->duplicate($source);
            $this->entityManager->flush();

            return $this->showUrl($copy);
        }, 'duplicated');
    }

    /**
     * An issued invoice cancelled, in whole or in part: the credit note is
     * issued at once, and the invoice expects that much less.
     */
    #[Route(path: '{id}/credit', name: self::ROUTE_CREDIT, requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function credit(
        string $ledger,
        string $id,
        Request $request,
        InvoiceRepository $invoices,
        InvoiceFactory $factory,
        InvoiceEmissionService $emission,
    ): Response {
        $bill = $this->document($this->ledger($ledger, LedgerPermission::EDIT), $id, $invoices);

        return $this->act($request, function () use ($bill, $request, $factory, $emission): string {
            $amount = trim($request->request->getString('amount'));
            $credit = $factory->createCreditNote(
                $bill,
                '' === $amount ? null : (int) round((float) str_replace([' ', ','], ['', '.'], $amount) * 100),
                $request->request->getString('reason') ?: null,
            );
            $this->entityManager->flush();
            $emission->emit($credit);

            return $this->showUrl($credit);
        }, 'credited');
    }

    #[Route(path: '{id}/delete', name: self::ROUTE_DELETE, requirements: ['id' => Requirement::UUID], methods: [Request::METHOD_POST])]
    public function delete(
        string $ledger,
        string $id,
        Request $request,
        InvoiceRepository $invoices,
    ): Response {
        $ledger = $this->ledger($ledger, LedgerPermission::EDIT);
        $invoice = $this->document($ledger, $id, $invoices);

        return $this->act($request, function () use ($ledger, $invoice): string {
            if (! $invoice->isEditable()) {
                throw new AccountingException('An issued document is cancelled by a credit note, not deleted.');
            }

            $this->entityManager->remove($invoice);

            return $this->generateUrl('accounting_documents_index', ['ledger' => (string) $ledger->getId()]);
        }, 'deleted');
    }

    private function document(
        Ledger $ledger,
        string $id,
        InvoiceRepository $invoices
    ): Invoice {
        $invoice = $invoices->find($id);

        if (! $invoice || $invoice->getLedger() !== $ledger) {
            throw $this->createNotFoundException();
        }

        return $invoice;
    }

    private function showUrl(Invoice $invoice): string
    {
        return $this->generateUrl('accounting_documents_show', [
            'ledger' => (string) $invoice->getLedger()->getId(),
            'id' => (string) $invoice->getId(),
        ]);
    }

    /**
     * The years the ledger has documents in, the latest first.
     *
     * @return list<string>
     */
    private function years(
        Ledger $ledger,
        InvoiceRepository $invoices
    ): array {
        $range = $invoices->createQueryBuilder('invoice')
            ->select('MIN(invoice.dateInvoice) AS first, MAX(invoice.dateInvoice) AS last')
            ->andWhere('invoice.ledger = :ledger')
            ->setParameter('ledger', $ledger->getId(), 'uuid')
            ->getQuery()
            ->getSingleResult();

        if (! $range['first']) {
            return [];
        }

        return array_map('strval', range((int) substr((string) $range['last'], 0, 4), (int) substr((string) $range['first'], 0, 4)));
    }
}
