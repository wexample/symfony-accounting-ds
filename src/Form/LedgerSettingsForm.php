<?php

namespace Wexample\SymfonyAccountingDs\Form;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\VatPeriodicity;
use Wexample\SymfonyForms\Form\AbstractForm;
use Wexample\SymfonyForms\Form\Type\EmailInputType;
use Wexample\SymfonyForms\Form\Type\NumberInputType;
use Wexample\SymfonyForms\Form\Type\SelectInputType;
use Wexample\SymfonyForms\Form\Type\SwitchInputType;
use Wexample\SymfonyForms\Form\Type\TextareaInputType;
use Wexample\SymfonyForms\Form\Type\TextInputType;
use Wexample\SymfonyForms\Form\Type\UrlInputType;

/**
 * Who the ledger is — the name, the identifiers and the address printed on
 * its documents — and how it keeps its books: its VAT, its documents' prefix
 * and payment term. Its country is not here: it chose the chart and the
 * journals when the ledger was created.
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
            ->add('name', TextInputType::class, [self::FIELD_OPTION_NAME_LABEL => true])
            ->add('legalForm', TextInputType::class, $optional)
            ->add('legalIdentifier', TextInputType::class, $optional)
            ->add('vatNumber', TextInputType::class, $optional)
            ->add('email', EmailInputType::class, $optional)
            ->add('phone', TextInputType::class, $optional)
            ->add('website', UrlInputType::class, $optional)
            ->add('postalAddress', TextareaInputType::class, $optional)
            ->add('postCode', TextInputType::class, $optional)
            ->add('city', TextInputType::class, $optional)
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

        $this->builderAddSubmit($builder);
    }
}
