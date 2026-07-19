<?php

namespace App\Form;

use App\Form\Model\ConfigurationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('timezone', ChoiceType::class, [
                'label' => 'manage.configuration.field.timezone.label',
                'choices' => self::timezoneChoices(),
                'help' => 'manage.configuration.field.timezone.help',
            ])
            ->add('dateTimeFormat', TextType::class, [
                'label' => 'manage.configuration.field.datetime_format.label',
                'help' => 'manage.configuration.field.datetime_format.help',
                'help_translation_parameters' => ['%example%' => date('D, d M Y H:i')],
            ])
            ->add('dateFormat', TextType::class, [
                'label' => 'manage.configuration.field.date_format.label',
                'help' => 'manage.configuration.field.date_format.help',
                'help_translation_parameters' => ['%example%' => date('D, d M Y')],
            ])
            ->add('timeFormat', TextType::class, [
                'label' => 'manage.configuration.field.time_format.label',
                'help' => 'manage.configuration.field.time_format.help',
                'help_translation_parameters' => ['%example%' => date('H:i')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConfigurationData::class,
        ]);
    }

    /**
     * All IANA timezone identifiers as label => value, with the common UTC entry
     * kept at the top for convenience.
     *
     * @return array<string, string>
     */
    private static function timezoneChoices(): array
    {
        $identifiers = \DateTimeZone::listIdentifiers();
        sort($identifiers);

        $choices = ['UTC' => 'UTC'];
        foreach ($identifiers as $id) {
            $choices[$id] = $id;
        }

        return $choices;
    }
}
