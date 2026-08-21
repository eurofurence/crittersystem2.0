<?php

namespace App\Form;

use App\Form\Model\CustomTallyData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CustomTallyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'manage.statistics.tallies.custom.label',
                'required' => false,
                'attr' => ['placeholder' => 'manage.statistics.tallies.custom.placeholder'],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'manage.statistics.tallies.custom.amount',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['min' => 0, 'step' => 'any'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CustomTallyData::class]);
    }
}
