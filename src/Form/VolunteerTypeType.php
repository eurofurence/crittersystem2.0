<?php

namespace App\Form;

use App\Entity\VolunteerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VolunteerTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('contactName', TextType::class, ['label' => 'Contact name', 'required' => false])
            ->add('contactDect', TextType::class, ['label' => 'Contact DECT', 'required' => false])
            ->add('contactEmail', EmailType::class, ['label' => 'Contact email', 'required' => false])
            ->add('restricted', CheckboxType::class, [
                'label' => 'Restricted (requires supporter confirmation to join)',
                'required' => false,
            ])
            ->add('shiftSelfSignup', CheckboxType::class, [
                'label' => 'Self sign-up for shifts (no approval needed)',
                'required' => false,
            ])
            ->add('showOnDashboard', CheckboxType::class, [
                'label' => 'Show on public dashboard',
                'required' => false,
            ])
            ->add('hideRegister', CheckboxType::class, [
                'label' => 'Hide from registration page',
                'required' => false,
            ])
            ->add('hideOnShiftView', CheckboxType::class, [
                'label' => 'Hide in shift details',
                'required' => false,
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'Staff only',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VolunteerType::class,
        ]);
    }
}
