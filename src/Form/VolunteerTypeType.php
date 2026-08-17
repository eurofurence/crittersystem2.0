<?php

namespace App\Form;

use App\Entity\Certification;
use App\Entity\VolunteerType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The `role` field is what makes the base types recoverable.
 *
 * Onboarding finds the type to hand out by its role, never by its name, so an event may rename them
 * freely. A system where no type holds "volunteer" gives every new non-staff user nothing and leaves
 * them unable to take a shift, and before this field existed the only way back was editing the
 * database by hand.
 */
final class VolunteerTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'common.label.name'])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'manage.volunteer_type.field.sort_order.label',
                'help' => 'manage.volunteer_type.field.sort_order.help',
                'attr' => ['min' => 0, 'max' => 9999],
                'required' => false,
                'empty_data' => (string) VolunteerType::SORT_ORDER_DEFAULT,
            ])
            ->add('description', RichTextType::class, [
                'label' => 'common.label.description',
                'required' => false,
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'manage.volunteer_type.field.role.label',
                'help' => 'manage.volunteer_type.field.role.help',
                'required' => false,
                'placeholder' => 'manage.volunteer_type.field.role.none',
                'choices' => [
                    'manage.volunteer_type.field.role.volunteer' => VolunteerType::ROLE_VOLUNTEER,
                    'manage.volunteer_type.field.role.staff' => VolunteerType::ROLE_STAFF,
                ],
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
            ->add('global', CheckboxType::class, [
                'label' => 'manage.volunteer_type.field.global.label',
                'help' => 'manage.volunteer_type.field.global.help',
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
