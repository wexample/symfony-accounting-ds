<?php

namespace Wexample\SymfonyAccountingDs\Service\FormProcessor;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\Attribute\Required;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyForms\Service\FormProcessor\AbstractEntityFormProcessor;

/**
 * A party saved by whoever may write in its ledger; a role it takes on gets
 * its auxiliary code.
 */
class PartyFormProcessor extends AbstractEntityFormProcessor
{
    private AuthorizationCheckerInterface $authorizationChecker;

    private PartyService $partyService;

    #[Required]
    public function setServices(
        AuthorizationCheckerInterface $authorizationChecker,
        PartyService $partyService,
    ): void {
        $this->authorizationChecker = $authorizationChecker;
        $this->partyService = $partyService;
    }

    protected function beforeSave(
        object $entity,
        FormInterface $form
    ): void {
        /** @var Party $entity */
        if (! $this->authorizationChecker->isGranted(LedgerPermission::EDIT->value, $entity)) {
            throw new AccessDeniedException();
        }

        $this->partyService->assignCodes($entity);
    }
}
