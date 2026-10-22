<?php

namespace Wexample\SymfonyAccountingDs\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\Attribute\Required;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\BelongsToLedgerInterface;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccountingDs\Traits\SymfonyAccountingDsBundleClassTrait;
use Wexample\SymfonyLoader\Controller\AbstractPagesController;

/**
 * What the pages of the books share. A ledger the reader may not reach, or
 * anything in it, does not exist for them: 404, as the API answers (LedgerVoter
 * decides). An action is a form posted to its page — its token, a question
 * asked first when it cannot be undone —: what the books refuse is said in a
 * toast, and the reader is sent back where they were.
 */
abstract class AbstractAccountingController extends AbstractPagesController
{
    use SymfonyAccountingDsBundleClassTrait;

    /**
     * The id of the token every action of the books posts.
     */
    final public const string CSRF_TOKEN_ID = 'accounting';

    /**
     * Where the sentences said after an action are kept.
     */
    final public const string MESSAGES = 'WexampleSymfonyAccountingDsBundle.common.accounting::';

    protected readonly EntityManagerInterface $entityManager;

    protected readonly LedgerRepository $ledgerRepository;

    #[Required]
    public function setAccountingServices(
        EntityManagerInterface $entityManager,
        LedgerRepository $ledgerRepository,
    ): void {
        $this->entityManager = $entityManager;
        $this->ledgerRepository = $ledgerRepository;
    }

    protected function ledger(
        string $id,
        LedgerPermission $permission = LedgerPermission::VIEW
    ): Ledger {
        return $this->reachable($this->ledgerRepository->find($id), $permission);
    }

    /**
     * @template T of Ledger|BelongsToLedgerInterface
     * @param T|null $subject
     * @return T
     */
    protected function reachable(
        Ledger|BelongsToLedgerInterface|null $subject,
        LedgerPermission $permission = LedgerPermission::VIEW
    ): Ledger|BelongsToLedgerInterface {
        if (! $subject || ! $this->isGranted($permission->value, $subject)) {
            throw new NotFoundHttpException();
        }

        return $subject;
    }

    /**
     * Runs what the books may refuse, then sends the reader on: to what the
     * action answers when it names a page, else back where they came from.
     *
     * @param callable(): (string|null) $action the address to go to, or null to go back
     */
    protected function act(
        Request $request,
        callable $action,
        string $done
    ): RedirectResponse {
        $back = $request->headers->get('referer') ?: $this->generateUrl('accounting_index');

        if (! $this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            $this->notify('error', self::MESSAGES.'refused.token');

            return $this->redirect($back);
        }

        try {
            $next = $action();
            $this->entityManager->flush();
        } catch (AccountingException $exception) {
            $this->notify('error', $exception->getMessage());

            return $this->redirect($back);
        }

        $this->notify('success', self::MESSAGES.'done.'.$done);

        return $this->redirect($next ?? $back);
    }

    /**
     * Said in a toast on the next page, as a form's notification is.
     */
    protected function notify(
        string $type,
        string $message
    ): void {
        $this->addFlash($type, ['type' => $type, 'message' => $message]);
    }
}
