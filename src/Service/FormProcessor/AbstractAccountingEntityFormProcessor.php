<?php

namespace Wexample\SymfonyAccountingDs\Service\FormProcessor;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Interface\BelongsToLedgerInterface;
use Wexample\SymfonyForms\Controller\FormController;
use Wexample\SymfonyForms\Service\FormProcessor\AbstractEntityFormProcessor;
use Wexample\SymfonyHelpers\Helper\RoleHelper;

/**
 * A form editing something of the books — a ledger, a party —: posted to the
 * address naming it, found again there, and saved only by whoever holds the
 * permission over it; anyone else is told it does not exist, as the pages
 * tell them.
 */
abstract class AbstractAccountingEntityFormProcessor extends AbstractEntityFormProcessor
{
    protected AuthorizationCheckerInterface $authorizationChecker;

    #[Required]
    public function setAuthorizationChecker(AuthorizationCheckerInterface $authorizationChecker): void
    {
        $this->authorizationChecker = $authorizationChecker;
    }

    /**
     * @return class-string<Ledger|BelongsToLedgerInterface>
     */
    abstract protected function getEntityClass(): string;

    abstract protected function getPermission(): LedgerPermission;

    /**
     * No role of its own: the ledger's voter decides, once the entity is
     * found (handleSubmittedRequest()).
     */
    public function getRequiredRoles(): array
    {
        return [RoleHelper::PUBLIC_ACCESS];
    }

    public function getFormActionRoute(): string
    {
        return 'form_'.FormController::ROUTE_ENTITY_FORM_PROCESSOR_SUBMIT;
    }

    public function getFormActionArgs($data): array
    {
        return parent::getFormActionArgs($data) + ['id' => (string) $data?->getId()];
    }

    public function handleSubmittedRequest(Request $request): object
    {
        try {
            $entity = $this->entityManager->find($this->getEntityClass(), (string) $request->attributes->get('id'));
        } catch (Throwable) {
            $entity = null;
        }

        if (! $entity || ! $this->authorizationChecker->isGranted($this->getPermission()->value, $entity)) {
            throw new NotFoundHttpException();
        }

        return $entity;
    }
}
