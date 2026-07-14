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
                'label' => 'No-show ban threshold (shifts)',
                'help' => 'Account is locked after this many no-show shifts.',
            ])
            ->add('banScreenMessage', TextareaType::class, [
                'label' => 'Ban screen message',
                'required' => true,
                'attr' => ['rows' => 2],
                'help' => 'Optional extra message shown on the ban screen.',
            ])
            ->add('messagesEnabled', CheckboxType::class, [
                'label' => 'Enable the messaging feature',
                'required' => false,
            ])
            ->add('infoDeskWelcome', TextareaType::class, [
                'label' => 'Info Desk welcome message',
                'required' => true,
                'attr' => ['rows' => 2],
            ])
            ->add('infoDeskFinalization', TextareaType::class, [
                'label' => 'Info Desk finalization message',
                'required' => true,
                'attr' => ['rows' => 2],
            ])
            ->add('infoDeskClaimTimeout', IntegerType::class, ['label' => 'Info Desk claim idle timeout (seconds)'] + $seconds)
            ->add('messageEditWindow', IntegerType::class, ['label' => 'Message edit window (seconds)'] + $seconds)
            ->add('callResponseTimeout', IntegerType::class, ['label' => 'Global call response timeout (seconds)'] + $seconds)
            ->add('callManagerLead', IntegerType::class, ['label' => 'Manager call start window before shift (seconds)'] + $seconds)
            ->add('shiftReminderLead', IntegerType::class, ['label' => 'Default shift reminder lead time (seconds)'] + $seconds)
            ->add('recommendedMaxHours', IntegerType::class, [
                'label' => 'Recommended maximum planned event hours',
                'help' => 'Warning threshold only, not a hard limit.',
                'attr' => ['min' => 1],
            ])
            ->add('autoMembershipFromLinks', CheckboxType::class, [
                'label' => 'Allow automatic department membership from non-SSO request links',
                'required' => false,
            ])
            ->add('sessionIdleMinutes', IntegerType::class, [
                'label' => 'Sign-out after inactivity (minutes)',
                'help' => 'How long a session survives with no request. An open, visible page keeps itself '
                    . 'signed in by polling, so this governs tabs that are hidden or closed.',
                'attr' => ['min' => 1],
            ])
            ->add('nightStartHour', IntegerType::class, ['label' => 'Night bonus start hour (0-23)', 'attr' => ['min' => 0, 'max' => 23]])
            ->add('nightEndHour', IntegerType::class, ['label' => 'Night bonus end hour (0-24)', 'attr' => ['min' => 0, 'max' => 24]])
            ->add('nightMultiplier', NumberType::class, ['label' => 'Night hours multiplier', 'scale' => 2])
            ->add('noShowMultiplier', NumberType::class, ['label' => 'No-show penalty multiplier', 'scale' => 2]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OperationsConfigData::class]);
    }
}
