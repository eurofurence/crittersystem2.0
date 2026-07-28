<?php

namespace App\Form;

use App\Form\Model\LocalLoginData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LocalLoginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('passwordLoginEnabled', CheckboxType::class, [
            'label' => 'admin.sso.local_login.field.enabled.label',
            'required' => false,
            'help' => 'admin.sso.local_login.field.enabled.help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LocalLoginData::class,
        ]);
    }
}
