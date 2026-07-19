<?php

namespace App\Form;

use App\Form\Model\OperationsConfigData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OperationsConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $seconds = ['attr' => ['min' => 1]];

        $builder
            ->add('noShowThreshold', IntegerType::class, [
                'label' => 'manage.operations_config.field.no_show_threshold.label',
                'help' => 'manage.operations_config.field.no_show_threshold.help',
            ])
            ->add('banScreenMessage', TextareaType::class, [
                'label' => 'manage.operations_config.field.ban_screen_message.label',
                'required' => true,
                'attr' => ['rows' => 2],
                'help' => 'manage.operations_config.field.ban_screen_message.help',
            ])
            ->add('messagesEnabled', CheckboxType::class, [
                'label' => 'manage.operations_config.field.messages_enabled.label',
                'required' => false,
            ])
            ->add('infoDeskWelcome', TextareaType::class, [
                'label' => 'manage.operations_config.field.infodesk_welcome.label',
                'required' => true,
                'attr' => ['rows' => 2],
            ])
            ->add('infoDeskFinalization', TextareaType::class, [
                'label' => 'manage.operations_config.field.infodesk_finalization.label',
                'required' => true,
                'attr' => ['rows' => 2],
            ])
            ->add('infoDeskClaimTimeout', IntegerType::class, ['label' => 'manage.operations_config.field.infodesk_claim_timeout.label'] + $seconds)
            ->add('messageEditWindow', IntegerType::class, ['label' => 'manage.operations_config.field.message_edit_window.label'] + $seconds)
            ->add('callResponseTimeout', IntegerType::class, ['label' => 'manage.operations_config.field.call_response_timeout.label'] + $seconds)
            ->add('callManagerLead', IntegerType::class, ['label' => 'manage.operations_config.field.call_manager_lead.label'] + $seconds)
            ->add('shiftReminderLead', IntegerType::class, ['label' => 'manage.operations_config.field.shift_reminder_lead.label'] + $seconds)
            ->add('recommendedMaxHours', IntegerType::class, [
                'label' => 'manage.operations_config.field.recommended_max_hours.label',
                'help' => 'manage.operations_config.field.recommended_max_hours.help',
                'attr' => ['min' => 1],
            ])
            ->add('autoMembershipFromLinks', CheckboxType::class, [
                'label' => 'manage.operations_config.field.auto_membership.label',
                'required' => false,
            ])
            ->add('sessionIdleMinutes', IntegerType::class, [
                'label' => 'manage.operations_config.field.session_idle.label',
                'help' => 'manage.operations_config.field.session_idle.help',
                'attr' => ['min' => 1],
            ])
            ->add('nightStartHour', IntegerType::class, ['label' => 'manage.operations_config.field.night_start.label', 'attr' => ['min' => 0, 'max' => 23]])
            ->add('nightEndHour', IntegerType::class, ['label' => 'manage.operations_config.field.night_end.label', 'attr' => ['min' => 0, 'max' => 24]])
            ->add('nightMultiplier', NumberType::class, ['label' => 'manage.operations_config.field.night_multiplier.label', 'scale' => 2])
            ->add('noShowMultiplier', NumberType::class, ['label' => 'manage.operations_config.field.noshow_multiplier.label', 'scale' => 2]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OperationsConfigData::class]);
    }
}
