<?php

namespace App\Form;

use App\Entity\Certification;
use App\Entity\VolunteerType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VolunteerTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('description', RichTextType::class, [
                'label' => 'Description',
                'required' => false,
            ])
            ->add('contacts', CollectionType::class, [
                'label' => 'Contacts',
                'entry_type' => VolunteerTypeContactType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])
            ->add('certifications', EntityType::class, [
                'label' => 'Certifications / requirements',
                'class' => Certification::class,
                'choice_label' => 'title',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('restricted', CheckboxType::class, [
                'label' => 'Requires Introduction (cannot apply to shifts until confirmed)',
                'required' => false,
            ])
            ->add('departmentOnly', CheckboxType::class, [
                'label' => 'Department only (requires Staff only)',
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
