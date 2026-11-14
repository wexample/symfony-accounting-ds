<?php

namespace Wexample\SymfonyAccountingDs\Service\FormProcessor;

use Symfony\Component\Form\FormInterface;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccountingDs\Helper\TypedAmountHelper;

/**
 * The settings of a ledger, written by whoever may administer it: its
 * columns by the form, the list it keeps (`settings`) here — percentages
 * typed become basis points, an admission per line `prefix: percent`.
 */
class LedgerSettingsFormProcessor extends AbstractAccountingEntityFormProcessor
{
    protected function getEntityClass(): string
    {
        return Ledger::class;
    }

    protected function getPermission(): LedgerPermission
    {
        return LedgerPermission::ADMINISTER;
    }

    protected function beforeSave(
        object $entity,
        FormInterface $form
    ): void {
        /** @var Ledger $entity */
        $settings = $entity->getSettings();
        $settings['oss_registered'] = (bool) $form->get('ossRegistered')->getData();
        $settings['oss_electronic_services'] = (bool) $form->get('ossElectronicServices')->getData();

        $rate = TypedAmountHelper::basisPoints($form->get('vatDeductionRate')->getData());
        if (null === $rate) {
            unset($settings['vat_deduction_rate']);
        } else {
            $settings['vat_deduction_rate'] = $rate;
        }

        $admissions = [];
        foreach (preg_split('/\R/', (string) $form->get('vatAdmissionRates')->getData()) as $line) {
            if (preg_match('/^\s*(\d+)\s*[:=]\s*([\d.,]+)\s*%?\s*$/', $line, $match)) {
                $admissions[$match[1]] = TypedAmountHelper::basisPoints($match[2]);
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
