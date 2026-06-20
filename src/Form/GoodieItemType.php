<?php

namespace App\Form;

use App\Entity\GoodieCategory;
use App\Entity\GoodieItem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class GoodieItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EntityType::class, [
                'label' => 'Category',
                'class' => GoodieCategory::class,
                'choice_label' => 'name',
            ])
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('requiredHours', NumberType::class, [
                'label' => 'Required hours',
                'scale' => 2,
            ])
            ->add('maxPerPerson', IntegerType::class, [
                'label' => 'Max per person',
                'required' => false,
                'help' => 'Leave blank for unlimited.',
            ])
            ->add('displayOrder', IntegerType::class, ['label' => 'Display order'])
            ->add('isActive', CheckboxType::class, ['label' => 'Active', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GoodieItem::class]);
    }
}
