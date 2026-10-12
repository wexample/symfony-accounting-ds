<?php

namespace Wexample\SymfonyAccountingDs\Service\FormProcessor;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\Attribute\Required;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyForms\Service\FormProcessor\AbstractEntityFormProcessor;

/**
 * The settings of a ledger, written by whoever may administer it: its
 * columns by the form, the list it keeps (`settings`) here — percentages
 * typed become basis points, an admission per line `prefix: percent`.
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

        /** @var Ledger $entity */
        $settings = $entity->getSettings();
        $settings['oss_registered'] = (bool) $form->get('ossRegistered')->getData();
        $settings['oss_electronic_services'] = (bool) $form->get('ossElectronicServices')->getData();

        $rate = $form->get('vatDeductionRate')->getData();
        if (null === $rate || '' === $rate) {
            unset($settings['vat_deduction_rate']);
        } else {
            $settings['vat_deduction_rate'] = (int) round((float) $rate * 100);
        }

        $admissions = [];
        foreach (preg_split('/\R/', (string) $form->get('vatAdmissionRates')->getData()) as $line) {
            if (preg_match('/^\s*(\d+)\s*[:=]\s*([\d.,]+)\s*%?\s*$/', $line, $match)) {
                $admissions[$match[1]] = (int) round((float) str_replace(',', '.', $match[2]) * 100);
            }
        }
        $settings['vat_admission_rates'] = $admissions;

        if ($form->has('nbbCourt')) {
            $settings['be_nbb'] = array_filter([
                'court' => $form->get('nbbCourt')->getData() ?: null,
                'last_deed_date' => $form->get('nbbLastDeedDate')->getData() ?: null,
                'legal_form' => $form->get('nbbLegalForm')->getData() ?: null,
            ]);
        }

        $entity->setSettings($settings);
    }
}
