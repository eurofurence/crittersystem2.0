<?php

namespace App\Form;

use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftType;
use App\Service\DisplaySettings;
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
    public function __construct(private readonly DisplaySettings $display)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The admin enters wall-clock times in the configured event timezone;
        // they are stored in UTC (model_timezone) so every datetime in the
        // system is an absolute instant. See {@see DisplaySettings}.
        $tz = $this->display->timezone()->getName();

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
                'view_timezone' => $tz,
                'model_timezone' => 'UTC',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Ends at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'view_timezone' => $tz,
                'model_timezone' => 'UTC',
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
