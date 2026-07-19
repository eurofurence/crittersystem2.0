<?php

namespace App\Form;

use App\Entity\Certification;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CertificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'common.label.title'])
            ->add('description', TextareaType::class, [
                'label' => 'common.label.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('contactPerson', TextType::class, ['label' => 'manage.certification.field.contact_person.label', 'required' => false])
            ->add('contactEmail', EmailType::class, ['label' => 'manage.certification.field.contact_email.label', 'required' => false])
            ->add('location', TextType::class, ['label' => 'common.label.location', 'required' => false])
            ->add('validityPeriodDays', IntegerType::class, [
                'label' => 'manage.certification.field.validity_period_days.label',
                'required' => false,
            ])
            ->add('isPerpetual', CheckboxType::class, [
                'label' => 'manage.certification.field.is_perpetual.label',
                'required' => false,
            ])
            ->add('allowSelfConfirmation', CheckboxType::class, [
                'label' => 'manage.certification.field.allow_self_confirmation.label',
                'required' => false,
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'common.state.staff_only',
                'required' => false,
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'manage.certification.field.is_active.label',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Certification::class,
        ]);
    }
}
