<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\ShiftType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ShiftTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('department', EntityType::class, [
                'label' => 'Department',
                'class' => Department::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— None —',
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'Staff only',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ShiftType::class,
        ]);
    }
}
