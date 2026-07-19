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
            ->add('name', TextType::class, ['label' => 'common.label.name'])
            ->add('description', RichTextType::class, [
                'label' => 'common.label.description',
                'required' => false,
            ])
            ->add('contacts', CollectionType::class, [
                'label' => 'manage.volunteer_type.card.contacts',
                'entry_type' => VolunteerTypeContactType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])
            ->add('certifications', EntityType::class, [
                'label' => 'manage.volunteer_type.card.certifications',
                'class' => Certification::class,
                'choice_label' => 'title',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('restricted', CheckboxType::class, [
                'label' => 'manage.volunteer_type.field.restricted.label',
                'required' => false,
            ])
            ->add('departmentOnly', CheckboxType::class, [
                'label' => 'manage.volunteer_type.field.department_only.label',
                'required' => false,
            ])
            ->add('shiftSelfSignup', CheckboxType::class, [
                'label' => 'manage.volunteer_type.field.shift_self_signup.label',
                'required' => false,
            ])
            ->add('showOnDashboard', CheckboxType::class, [
                'label' => 'manage.volunteer_type.field.show_on_dashboard.label',
                'required' => false,
            ])
            ->add('hideRegister', CheckboxType::class, [
                'label' => 'manage.volunteer_type.field.hide_register.label',
                'required' => false,
            ])
            ->add('hideOnShiftView', CheckboxType::class, [
                'label' => 'manage.volunteer_type.field.hide_on_shift_view.label',
                'required' => false,
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'common.state.staff_only',
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
