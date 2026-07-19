<?php

namespace App\Form;

use App\Entity\Badge;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BadgeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'common.label.name'])
            ->add('type', ChoiceType::class, [
                'label' => 'common.label.type',
                'choices' => ['manage.badge.type.position' => Badge::TYPE_POSITION, 'manage.badge.type.standard' => Badge::TYPE_STANDARD],
                'help' => 'manage.badge.field.type.help',
            ])
            ->add('priority', IntegerType::class, [
                'label' => 'manage.label.priority',
                'required' => false,
                'help' => 'manage.badge.field.priority.help',
            ])
            ->add('color', TextType::class, ['label' => 'manage.badge.field.color.label', 'help' => 'manage.badge.field.color.help']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Badge::class]);
    }
}
