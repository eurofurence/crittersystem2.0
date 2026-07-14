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
                'label' => 'SSO group id',
                'help' => 'The provider\'s structured group id, e.g. 0RV39Y2PLMX1J4N6.',
            ])
            ->add('name', TextType::class, [
                'label' => 'Name',
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'help' => 'The provider group slug/alias.',
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'Staff only',
                'required' => false,
                'help' => 'Members of this group are treated as staff.',
            ])
            ->add('department', EntityType::class, [
                'label' => 'Department',
                'class' => Department::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— None —',
            ])
            ->add('permissionGroups', EntityType::class, [
                'label' => 'Permission groups',
                'class' => Group::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
            ])
            ->add('volunteerTypes', EntityType::class, [
                'label' => 'Volunteer types',
                'class' => VolunteerType::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
            ])
            ->add('badges', EntityType::class, [
                'label' => 'Badges',
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
