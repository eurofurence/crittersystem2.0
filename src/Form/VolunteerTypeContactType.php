<?php

namespace App\Form;

use App\Entity\VolunteerTypeContact;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VolunteerTypeContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'required' => false])
            ->add('phone', TextType::class, ['label' => 'Phone', 'required' => false])
            ->add('telegram', TextType::class, ['label' => 'Telegram (@handle)', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'Email', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => VolunteerTypeContact::class]);
    }
}
