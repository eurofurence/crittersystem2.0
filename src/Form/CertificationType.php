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
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('contactPerson', TextType::class, ['label' => 'Contact person', 'required' => false])
            ->add('contactEmail', EmailType::class, ['label' => 'Contact email', 'required' => false])
            ->add('location', TextType::class, ['label' => 'Location', 'required' => false])
            ->add('validityPeriodDays', IntegerType::class, [
                'label' => 'Validity period (days)',
                'required' => false,
            ])
            ->add('isPerpetual', CheckboxType::class, [
                'label' => 'Perpetual (never expires)',
                'required' => false,
            ])
            ->add('allowSelfConfirmation', CheckboxType::class, [
                'label' => 'Allow self-confirmation',
                'required' => false,
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'Staff only',
                'required' => false,
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Active (available for application)',
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
