<?php

namespace App\Form;

use App\Entity\ConsentText;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ConsentTextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('locale', TextType::class, ['help' => 'e.g. en_US, de_DE'])
            ->add('headerTitle', TextType::class)
            ->add('headerBody', TextareaType::class, ['attr' => ['rows' => 3]])
            ->add('checkboxLabel', TextareaType::class, [
                'attr' => ['rows' => 2],
                'help' => 'Shown next to the required consent checkbox. Supports %variables.',
            ])
            ->add('footer', TextareaType::class, ['attr' => ['rows' => 3], 'help' => 'Supports %variables.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ConsentText::class]);
    }
}
