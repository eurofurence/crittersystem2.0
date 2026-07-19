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
            ->add('name', TextType::class, ['label' => 'manage.event_config.field.name.label', 'required' => false])
            ->add('welcomeMessage', TextareaType::class, [
                'label' => 'manage.event_config.field.welcome_message.label',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('accessMode', ChoiceType::class, [
                'label' => 'manage.event_config.field.access_mode.label',
                'choices' => [
                    'common.state.public' => 'public',
                    'common.state.staff_only' => 'staff',
                    'manage.event_config.access.admin_only' => 'admin',
                ],
            ])
            ->add('buildupStart', DateTimeType::class, ['label' => 'manage.event_config.field.buildup_start.label'] + $dateOptions)
            ->add('eventStart', DateTimeType::class, ['label' => 'manage.event_config.field.event_start.label'] + $dateOptions)
            ->add('eventEnd', DateTimeType::class, ['label' => 'manage.event_config.field.event_end.label'] + $dateOptions)
            ->add('teardownEnd', DateTimeType::class, ['label' => 'manage.event_config.field.teardown_end.label'] + $dateOptions)
            ->add('defaultTheme', ChoiceType::class, [
                'label' => 'manage.event_config.field.default_theme.label',
                'required' => false,
                'placeholder' => 'manage.event_config.field.default_theme.placeholder',
                'choices' => $this->themes->choices(),
                'help' => 'manage.event_config.field.default_theme.help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventConfigData::class,
        ]);
    }
}
