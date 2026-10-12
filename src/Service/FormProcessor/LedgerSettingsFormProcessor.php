<?php

namespace Wexample\SymfonyAccountingDs\Service\FormProcessor;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\Attribute\Required;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyForms\Service\FormProcessor\AbstractEntityFormProcessor;

/**
 * The settings of a ledger, written by whoever may administer it.
 */
class LedgerSettingsFormProcessor extends AbstractEntityFormProcessor
{
    private AuthorizationCheckerInterface $authorizationChecker;

    #[Required]
    public function setAuthorizationChecker(AuthorizationCheckerInterface $authorizationChecker): void
    {
        $this->authorizationChecker = $authorizationChecker;
    }

    protected function beforeSave(
        object $entity,
        FormInterface $form
    ): void {
        if (! $this->authorizationChecker->isGranted(LedgerPermission::ADMINISTER->value, $entity)) {
            throw new AccessDeniedException();
        }
    }
}
