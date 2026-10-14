<?php

namespace Wexample\SymfonyAccountingDs\Form;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\VatPeriodicity;
use Wexample\SymfonyForms\Form\AbstractForm;
use Wexample\SymfonyForms\Form\Type\DateInputType;
use Wexample\SymfonyForms\Form\Type\NumberInputType;
use Wexample\SymfonyForms\Form\Type\SelectInputType;
use Wexample\SymfonyForms\Form\Type\SwitchInputType;
use Wexample\SymfonyForms\Form\Type\TextareaInputType;
use Wexample\SymfonyForms\Form\Type\TextInputType;
use Wexample\SymfonyOrganizationDs\Form\Type\LegalIdentityType;

/**
 * Who the ledger is — the name, the identifiers and the address printed on
 * its documents — and how it keeps its books: its VAT, its documents' prefix
 * and payment term. Its country is not here: it chose the chart and the
 * journals when the ledger was created. Below the columns of the ledger, the
 * settings it keeps as a list (`settings`): the one-stop shop, the share of
 * VAT it may deduct, and for a Belgian ledger what its annual accounts filed
 * with the National Bank say.
 */
class LedgerSettingsForm extends AbstractForm
{
    public static bool $ajax = true;

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => Ledger::class,
        ]);
    }

    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $optional = [self::FIELD_OPTION_NAME_LABEL => true, self::FIELD_OPTION_NAME_REQUIRED => false];

        $builder
            // The country is the jurisdiction of the books: not changed here.
            ->add('organization', LegalIdentityType::class, ['with_legal_form' => true, 'with_website' => true, 'with_country' => false])
            ->add('vatSubject', SwitchInputType::class, $optional)
            ->add('vatPeriodicity', SelectInputType::class, [
                self::FIELD_OPTION_NAME_LABEL => true,
                'choices' => VatPeriodicity::cases(),
                'choice_value' => static fn (?VatPeriodicity $periodicity) => $periodicity?->value,
                'choice_label' => static fn (VatPeriodicity $periodicity) => $periodicity->value,
            ])
            ->add('vatOnPayments', SwitchInputType::class, $optional)
            ->add('invoicePrefix', TextInputType::class, $optional)
            ->add('paymentTermDays', NumberInputType::class, [
                self::FIELD_OPTION_NAME_LABEL => true,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 1],
            ])
            ->add('legalMentions', TextareaInputType::class, $optional);

        $setting = $optional + ['mapped' => false];
        $builder
            ->add('ossRegistered', SwitchInputType::class, $setting)
            ->add('ossElectronicServices', SwitchInputType::class, $setting)
            ->add('vatDeductionRate', NumberInputType::class, $setting + ['html5' => true, 'attr' => ['min' => 0, 'max' => 100, 'step' => 'any']])
            ->add('vatAdmissionRates', TextareaInputType::class, $setting);

        // What the settings hold, shown in their fields; the National Bank's
        // only on a Belgian ledger.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($setting): void {
            /** @var Ledger|null $ledger */
            $ledger = $event->getData();
            $form = $event->getForm();

            if (! $ledger) {
                return;
            }

            if ('BE' === $ledger->getCountry()?->getIsoAlpha2Code()) {
                $nbb = $ledger->getSetting('be_nbb', []);
                $form
                    ->add('nbbCourt', TextInputType::class, $setting + ['data' => $nbb['court'] ?? null])
                    ->add('nbbLastDeedDate', DateInputType::class, $setting + ['widget' => 'single_text', 'input' => 'string', 'data' => $nbb['last_deed_date'] ?? null])
                    ->add('nbbLegalForm', TextInputType::class, $setting + ['data' => $nbb['legal_form'] ?? null]);
            }

            $admissions = $ledger->getSetting('vat_admission_rates', []);
            $form->get('ossRegistered')->setData((bool) $ledger->getSetting('oss_registered', false));
            $form->get('ossElectronicServices')->setData((bool) $ledger->getSetting('oss_electronic_services', false));
            $form->get('vatDeductionRate')->setData(null === ($rate = $ledger->getSetting('vat_deduction_rate')) ? null : $rate / 100);
            $form->get('vatAdmissionRates')->setData(implode("\n", array_map(
                static fn ($prefix, $admission) => $prefix.': '.($admission / 100),
                array_keys($admissions),
                $admissions
            )));
        });

        $this->builderAddSubmit($builder);
    }
}
