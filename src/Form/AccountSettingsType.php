<?php

namespace App\Form;

use App\Form\Model\AccountSettingsData;
use App\Theme\ThemeCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class AccountSettingsType extends AbstractType
{
    public function __construct(private readonly ThemeCatalog $themes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $nameEditable = (bool) $options['name_editable'];
        $passwordChangeable = (bool) $options['password_changeable'];

        $date = ['widget' => 'single_text', 'required' => false, 'input' => 'datetime_immutable', 'html5' => true];

        $builder
            ->add('pronoun', TextType::class, ['required' => false, 'label' => 'settings.field.pronoun.label'])
            ->add('firstName', TextType::class, ['required' => false, 'disabled' => !$nameEditable, 'label' => 'settings.field.first_name.label'])
            ->add('lastName', TextType::class, ['required' => false, 'disabled' => !$nameEditable, 'label' => 'settings.field.last_name.label'])
            ->add('mobile', TextType::class, ['required' => false, 'label' => 'settings.field.mobile.label'])
            ->add('plannedArrivalDate', DateType::class, ['label' => 'settings.field.planned_arrival.label'] + $date)
            ->add('plannedDepartureDate', DateType::class, ['label' => 'settings.field.planned_departure.label'] + $date)
            ->add('language', ChoiceType::class, [
                'choices' => [
                    'settings.language.option.en' => 'en_US',
                    'settings.language.option.de' => 'de_DE',
                    'settings.language.option.tlh' => 'tlh',
                ],
                'label' => 'settings.field.language.label',
            ])
            ->add('theme', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'settings.theme.system_default',
                'choices' => $this->themes->choices(),
                'label' => 'settings.theme.label',
            ])
            ->add('avatar', FileType::class, [
                'required' => false,
                'mapped' => false,
                'label' => 'settings.field.avatar.label',
                'constraints' => [
                    new Assert\Image(maxSize: '2M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']),
                ],
            ]);

        if ($passwordChangeable) {
            $builder
                ->add('currentPassword', PasswordType::class, ['required' => false, 'mapped' => false, 'label' => 'settings.field.current_password.label'])
                ->add('newPassword', PasswordType::class, [
                    'required' => false,
                    'mapped' => false,
                    'label' => 'settings.field.new_password.label',
                    'constraints' => [new Assert\Length(min: 8, max: 4096)],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountSettingsData::class,
            'name_editable' => true,
            'password_changeable' => true,
        ]);
        $resolver->setAllowedTypes('name_editable', 'bool');
        $resolver->setAllowedTypes('password_changeable', 'bool');
    }
}
