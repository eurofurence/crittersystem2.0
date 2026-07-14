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
            ->add('pronoun', TextType::class, ['required' => false, 'label' => 'Pronouns'])
            ->add('firstName', TextType::class, ['required' => false, 'disabled' => !$nameEditable])
            ->add('lastName', TextType::class, ['required' => false, 'disabled' => !$nameEditable])
            ->add('mobile', TextType::class, ['required' => false, 'label' => 'Mobile'])
            ->add('plannedArrivalDate', DateType::class, ['label' => 'Planned arrival'] + $date)
            ->add('plannedDepartureDate', DateType::class, ['label' => 'Planned departure'] + $date)
            ->add('language', ChoiceType::class, [
                'choices' => ['English' => 'en_US', 'Deutsch' => 'de_DE'],
                'label' => 'Language',
            ])
            ->add('theme', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'System default',
                'choices' => $this->themes->choices(),
                'label' => 'Theme',
            ])
            ->add('avatar', FileType::class, [
                'required' => false,
                'mapped' => false,
                'label' => 'Profile picture',
                'constraints' => [
                    new Assert\Image(maxSize: '2M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']),
                ],
            ]);

        if ($passwordChangeable) {
            $builder
                ->add('currentPassword', PasswordType::class, ['required' => false, 'mapped' => false, 'label' => 'Current password'])
                ->add('newPassword', PasswordType::class, [
                    'required' => false,
                    'mapped' => false,
                    'label' => 'New password',
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
