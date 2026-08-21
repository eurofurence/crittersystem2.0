<?php

namespace App\Form;

use App\Form\Model\CustomTallyData;
use App\Form\Model\StatisticsTalliesData;
use App\Service\Statistics\TallyCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The catalog figures are rendered as a compound child whose own data is a plain array, so each
 * catalog slug maps to an array key and adding a slug to the catalog needs no change here.
 */
final class StatisticsTalliesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $known = $builder->create('known', FormType::class, [
            'label' => false,
            'required' => false,
        ]);

        foreach (TallyCatalog::slugs() as $slug) {
            $known->add($slug, NumberType::class, [
                'label' => 'manage.statistics.tally.'.$slug.'.label',
                'required' => false,
                'html5' => true,
                'scale' => 0,
                'attr' => ['min' => 0, 'step' => 1],
            ]);
        }

        $builder
            ->add($known)
            ->add('custom', CollectionType::class, [
                'label' => 'manage.statistics.tallies.custom.title',
                'entry_type' => CustomTallyType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_data' => new CustomTallyData(),
            ])
            ->add('hourlyRate', NumberType::class, [
                'label' => 'manage.statistics.tallies.hourly_rate.label',
                'help' => 'manage.statistics.tallies.hourly_rate.help',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['min' => 0, 'step' => 'any'],
            ])
            ->add('currency', TextType::class, [
                'label' => 'manage.statistics.tallies.currency.label',
                'required' => false,
                'attr' => ['maxlength' => 3, 'placeholder' => 'EUR'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StatisticsTalliesData::class]);
    }
}
