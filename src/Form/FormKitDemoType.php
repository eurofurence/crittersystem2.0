<?php

namespace App\Form;

use App\Form\Model\FormKitDemoData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FormKitDemoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'required' => true,
            ])
            ->add('startsAt', TextType::class, [
                'label' => 'Starts at',
                'required' => false,
                // we’ll render as datetime-local via the macro
            ])
            ->add('priority', IntegerType::class, [
                'label' => 'Priority',
                'required' => true,
            ])
            ->add('departments', ChoiceType::class, [
                'label' => 'Departments',
                'choices' => [
                    'Ops' => 'ops',
                    'Registration' => 'reg',
                    'Logistics' => 'log',
                    'Security' => 'sec',
                ],
                'multiple' => true,
                'expanded' => false,
                'required' => false,
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Submit',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FormKitDemoData::class,
        ]);
    }
}
