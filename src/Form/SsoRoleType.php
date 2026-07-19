<?php

namespace App\Form;

use App\Form\Model\SsoRoleData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SsoRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('departmentManagerRole', TextType::class, [
                'label' => 'admin.sso.roles.field.department_manager.label',
                'required' => false,
                'help' => 'admin.sso.roles.field.department_manager.help',
            ])
            ->add('shiftManagerRole', TextType::class, [
                'label' => 'admin.sso.roles.field.shift_manager.label',
                'required' => false,
                'help' => 'admin.sso.roles.field.shift_manager.help',
            ])
            ->add('globalAdminRole', TextType::class, [
                'label' => 'admin.sso.roles.field.global_admin.label',
                'required' => false,
                'help' => 'admin.sso.roles.field.global_admin.help',
            ])
            ->add('subAdminRole', TextType::class, [
                'label' => 'admin.sso.roles.field.sub_admin.label',
                'required' => false,
                'help' => 'admin.sso.roles.field.sub_admin.help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SsoRoleData::class,
        ]);
    }
}
