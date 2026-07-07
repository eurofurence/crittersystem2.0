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
            ->add('name', TextType::class)
            ->add('type', ChoiceType::class, [
                'choices' => ['Position (ranked)' => Badge::TYPE_POSITION, 'Standard (tag)' => Badge::TYPE_STANDARD],
                'help' => 'Position badges are mutually ranked; the highest-priority one is shown.',
            ])
            ->add('priority', IntegerType::class, [
                'required' => false,
                'help' => 'Only used for position badges. Higher wins (BoD 40 > Director 30 > Staff 20 > Volunteer 10).',
            ])
            ->add('color', TextType::class, ['help' => 'Tabler colour token, e.g. red, azure, green, purple.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Badge::class]);
    }
}
