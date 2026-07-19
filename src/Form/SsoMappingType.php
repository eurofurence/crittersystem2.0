<?php

namespace App\Form;

use App\Entity\Badge;
use App\Entity\Department;
use App\Entity\Group;
use App\Entity\SsoGroupMapping;
use App\Entity\VolunteerType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Manual create/edit form for a single SSO group mapping. Mirrors the fields
 * accepted by the JSON bulk import (see App\Sso\SsoMappingImporter).
 */
final class SsoMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ssoGroupId', TextType::class, [
                'label' => 'manage.sso_mapping.field.sso_group_id.label',
                'help' => 'manage.sso_mapping.field.sso_group_id.help',
            ])
            ->add('name', TextType::class, [
                'label' => 'common.label.name',
            ])
            ->add('slug', TextType::class, [
                'label' => 'manage.label.slug',
                'help' => 'manage.sso_mapping.field.slug.help',
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'common.state.staff_only',
                'required' => false,
                'help' => 'manage.sso_mapping.field.staff_only.help',
            ])
            ->add('department', EntityType::class, [
                'label' => 'common.label.department',
                'class' => Department::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'manage.sso_mapping.field.department.placeholder',
            ])
            ->add('permissionGroups', EntityType::class, [
                'label' => 'manage.sso_mapping.field.permission_groups.label',
                'class' => Group::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
            ])
            ->add('volunteerTypes', EntityType::class, [
                'label' => 'manage.label.volunteer_types',
                'class' => VolunteerType::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
            ])
            ->add('badges', EntityType::class, [
                'label' => 'manage.label.badges',
                'class' => Badge::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SsoGroupMapping::class]);
    }
}
