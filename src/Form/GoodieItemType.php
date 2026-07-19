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
                'label' => 'backstage.goodie.label.category',
                'class' => GoodieCategory::class,
                'choice_label' => 'name',
            ])
            ->add('name', TextType::class, ['label' => 'common.label.name'])
            ->add('description', TextareaType::class, [
                'label' => 'common.label.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('requiredHours', NumberType::class, [
                'label' => 'backstage.goodie.label.required_hours',
                'scale' => 2,
            ])
            ->add('maxPerPerson', IntegerType::class, [
                'label' => 'backstage.goodie.label.max_per_person',
                'required' => false,
                'help' => 'backstage.goodie.item.field.max_per_person.help',
            ])
            ->add('displayOrder', IntegerType::class, ['label' => 'backstage.goodie.label.display_order'])
            ->add('isActive', CheckboxType::class, ['label' => 'common.state.active', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GoodieItem::class]);
    }
}
