<?php

namespace App\Form;

use App\Entity\Worklog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Self-service worklog entry. Bound to the Worklog entity; the
 * controller sets the subject and creator.
 */
final class WorklogSelfType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('hours', NumberType::class, ['scale' => 2, 'label' => 'Hours', 'attr' => ['min' => 0, 'step' => 0.25]])
            ->add('workedAt', DateTimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'Worked at',
            ])
            ->add('comment', TextareaType::class, ['required' => false, 'label' => 'Comment', 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Worklog::class]);
    }
}
