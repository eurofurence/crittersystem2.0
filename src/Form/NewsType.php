<?php

namespace App\Form;

use App\Entity\News;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NewsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('text', TextareaType::class, [
                'label' => 'Text',
                'attr' => ['rows' => 8],
                'help' => 'Use [more] to split a short preview from the full article.',
            ])
            ->add('isMeeting', CheckboxType::class, ['label' => 'Meeting announcement', 'required' => false])
            ->add('isPinned', CheckboxType::class, ['label' => 'Pin to top', 'required' => false])
            ->add('isHighlighted', CheckboxType::class, ['label' => 'Highlight', 'required' => false])
            ->add('staffOnly', CheckboxType::class, ['label' => 'Staff only', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => News::class]);
    }
}
