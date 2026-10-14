<?php

namespace Wexample\SymfonyAccountingDs\Form;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyForms\Form\AbstractForm;
use Wexample\SymfonyForms\Form\Type\NumberInputType;
use Wexample\SymfonyForms\Form\Type\SwitchInputType;
use Wexample\SymfonyForms\Form\Type\TextareaInputType;
use Wexample\SymfonyForms\Form\Type\TextInputType;
use Wexample\SymfonyOrganizationDs\Form\Type\LegalIdentityType;

/**
 * A customer or a supplier: who it is — its organization's identity, the same
 * in every ledger it appears in —, and how it pays and is paid here. Its
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
            ->add('organization', LegalIdentityType::class, ['with_individual' => true])
            ->add('customer', SwitchInputType::class, $optional)
            ->add('supplier', SwitchInputType::class, $optional)
            ->add('iban', TextInputType::class, $optional)
            ->add('paymentTermDays', NumberInputType::class, $optional + ['html5' => true, 'attr' => ['min' => 0, 'step' => 1]])
            ->add('notes', TextareaInputType::class, $optional);

        $this->builderAddSubmit($builder);
    }
}
