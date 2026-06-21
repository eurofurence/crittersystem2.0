<?php

namespace App\Form;

use App\Form\Model\EventConfigData;
use App\Service\DisplaySettings;
use App\Theme\ThemeCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class EventConfigType extends AbstractType
{
    public function __construct(
        private readonly ThemeCatalog $themes,
        private readonly DisplaySettings $display,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $dateOptions = [
            'widget' => 'single_text',
            'required' => false,
            'input' => 'datetime_immutable',
            'view_timezone' => $this->display->timezone()->getName(),
            'model_timezone' => 'UTC',
        ];

        $builder
            ->add('name', TextType::class, ['label' => 'Event name', 'required' => false])
            ->add('welcomeMessage', TextareaType::class, [
                'label' => 'Welcome message',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('accessMode', ChoiceType::class, [
                'label' => 'Access mode',
                'choices' => [
                    'Public' => 'public',
                    'Staff only' => 'staff',
                    'Admin only' => 'admin',
                ],
            ])
            ->add('buildupStart', DateTimeType::class, ['label' => 'Buildup start'] + $dateOptions)
            ->add('eventStart', DateTimeType::class, ['label' => 'Event start'] + $dateOptions)
            ->add('eventEnd', DateTimeType::class, ['label' => 'Event end'] + $dateOptions)
            ->add('teardownEnd', DateTimeType::class, ['label' => 'Teardown end'] + $dateOptions)
            ->add('defaultTheme', ChoiceType::class, [
                'label' => 'Default theme',
                'required' => false,
                'placeholder' => '— first available —',
                'choices' => $this->themes->choices(),
                'help' => 'Applied to everyone who hasn\'t picked their own theme.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventConfigData::class,
        ]);
    }
}
