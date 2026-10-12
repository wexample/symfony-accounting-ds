<?php

namespace Wexample\SymfonyAccountingDs\Form;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyForms\Form\AbstractForm;
use Wexample\SymfonyForms\Form\Type\EmailInputType;
use Wexample\SymfonyForms\Form\Type\NumberInputType;
use Wexample\SymfonyForms\Form\Type\SwitchInputType;
use Wexample\SymfonyForms\Form\Type\TextareaInputType;
use Wexample\SymfonyForms\Form\Type\TextInputType;

/**
 * A customer or a supplier: who it is, where, how it pays and is paid. Its
 * auxiliary codes are the books' own, given when the party was created.
 */
class PartyForm extends AbstractForm
{
    public static bool $ajax = true;

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => Party::class,
        ]);
    }

    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $optional = [self::FIELD_OPTION_NAME_LABEL => true, self::FIELD_OPTION_NAME_REQUIRED => false];

        $builder
            ->add('name', TextInputType::class, [self::FIELD_OPTION_NAME_LABEL => true])
            ->add('customer', SwitchInputType::class, $optional)
            ->add('supplier', SwitchInputType::class, $optional)
            ->add('individual', SwitchInputType::class, $optional)
            ->add('legalIdentifier', TextInputType::class, $optional)
            ->add('vatNumber', TextInputType::class, $optional)
            ->add('email', EmailInputType::class, $optional)
            ->add('phone', TextInputType::class, $optional)
            ->add('postalAddress', TextareaInputType::class, $optional)
            ->add('postCode', TextInputType::class, $optional)
            ->add('city', TextInputType::class, $optional)
            ->add('iban', TextInputType::class, $optional)
            ->add('paymentTermDays', NumberInputType::class, $optional + ['html5' => true, 'attr' => ['min' => 0, 'step' => 1]])
            ->add('notes', TextareaInputType::class, $optional);

        $this->builderAddSubmit($builder);
    }
}
