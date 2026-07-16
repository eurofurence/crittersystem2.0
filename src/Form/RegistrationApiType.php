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
            'label' => 'Registration API endpoint',
            'required' => false,
            'help' => 'Queried with the user\'s own access token right after SSO login to read their '
                . 'registration number (expects a JSON body like {"ids": [12345]}). Leave empty to disable.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationApiData::class,
        ]);
    }
}
