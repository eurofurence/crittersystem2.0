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
            ->add('title', TextType::class, ['label' => 'common.label.title'])
            ->add('text', TextareaType::class, [
                'label' => 'manage.news.field.text.label',
                'attr' => ['rows' => 8],
                'help' => 'manage.news.field.text.help',
            ])
            ->add('isMeeting', CheckboxType::class, ['label' => 'manage.news.field.is_meeting.label', 'required' => false])
            ->add('isPinned', CheckboxType::class, ['label' => 'manage.news.field.is_pinned.label', 'required' => false])
            ->add('isHighlighted', CheckboxType::class, ['label' => 'manage.news.field.is_highlighted.label', 'required' => false])
            ->add('staffOnly', CheckboxType::class, ['label' => 'common.state.staff_only', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => News::class]);
    }
}
