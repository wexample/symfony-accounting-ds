<?php

namespace Wexample\SymfonyAccountingDs\Service\FormProcessor;

use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;

/**
 * A party saved by whoever may write in its ledger; a role it takes on gets
 * its auxiliary code.
 */
class PartyFormProcessor extends AbstractAccountingEntityFormProcessor
{
    private PartyService $partyService;

    #[Required]
    public function setPartyService(PartyService $partyService): void
    {
        $this->partyService = $partyService;
    }

    protected function getEntityClass(): string
    {
        return Party::class;
    }

    protected function getPermission(): LedgerPermission
    {
        return LedgerPermission::EDIT;
    }

    protected function beforeSave(
        object $entity,
        FormInterface $form
    ): void {
        /** @var Party $entity */
        $this->partyService->assignCodes($entity);
    }
}
