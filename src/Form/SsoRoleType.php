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
                'label' => 'Department manager role ID',
                'required' => false,
                'help' => 'A user holding this role is a department manager of every department their '
                    . 'group mappings place them in. Leave empty to disable.',
            ])
            ->add('shiftManagerRole', TextType::class, [
                'label' => 'Shift manager role ID',
                'required' => false,
                'help' => 'As above, for shift managers. A user holding both roles becomes a '
                    . 'department manager.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SsoRoleData::class,
        ]);
    }
}
