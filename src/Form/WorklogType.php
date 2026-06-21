<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\Worklog;
use App\Service\DisplaySettings;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class WorklogType extends AbstractType
{
    public function __construct(private readonly DisplaySettings $display)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tz = $this->display->timezone()->getName();

        $builder
            ->add('user', EntityType::class, [
                'label' => 'Volunteer',
                'class' => User::class,
                'choice_label' => 'name',
            ])
            ->add('hours', NumberType::class, [
                'label' => 'Hours',
                'scale' => 2,
            ])
            ->add('workedAt', DateTimeType::class, [
                'label' => 'Worked at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'view_timezone' => $tz,
                'model_timezone' => 'UTC',
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Comment',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Worklog::class,
        ]);
    }
}
