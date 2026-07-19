<?php

namespace App\Form;

use App\Entity\Faq;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FaqType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', TextType::class, ['label' => 'manage.faq.field.category.label'])
            ->add('question', TextareaType::class, ['label' => 'manage.faq.field.question.label', 'attr' => ['rows' => 2]])
            ->add('answer', TextareaType::class, ['label' => 'manage.faq.field.answer.label', 'attr' => ['rows' => 5]])
            ->add('displayOrder', IntegerType::class, ['label' => 'manage.faq.field.display_order.label']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Faq::class]);
    }
}
