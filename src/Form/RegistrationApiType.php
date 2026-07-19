<?php

namespace App\Form;

use App\Form\Model\RegistrationApiData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RegistrationApiType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('apiUrl', UrlType::class, [
            'label' => 'manage.registration_api.field.url.label',
            'required' => false,
            'help' => 'manage.registration_api.field.url.help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationApiData::class,
        ]);
    }
}
