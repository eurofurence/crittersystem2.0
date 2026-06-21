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
                'label' => 'Timezone',
                'choices' => self::timezoneChoices(),
                'help' => 'All dates and times are shown in this timezone for every user, '
                    . 'regardless of their browser or device settings.',
            ])
            ->add('dateTimeFormat', TextType::class, [
                'label' => 'Date & time format',
                'help' => 'PHP date() format, e.g. "D, d M Y H:i" → ' . date('D, d M Y H:i'),
            ])
            ->add('dateFormat', TextType::class, [
                'label' => 'Date format',
                'help' => 'PHP date() format, e.g. "D, d M Y" → ' . date('D, d M Y'),
            ])
            ->add('timeFormat', TextType::class, [
                'label' => 'Time format',
                'help' => 'PHP date() format, e.g. "H:i" → ' . date('H:i'),
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
