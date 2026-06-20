<?php

namespace App\Form;

use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ShiftFormType extends AbstractType
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
            ->add('url', UrlType::class, [
                'label' => 'URL',
                'required' => false,
                'default_protocol' => null,
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Starts at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Ends at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('shiftType', EntityType::class, [
                'label' => 'Shift type',
                'class' => ShiftType::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— None —',
            ])
            ->add('location', EntityType::class, [
                'label' => 'Location',
                'class' => Location::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— None —',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Shift::class,
        ]);
    }
}
