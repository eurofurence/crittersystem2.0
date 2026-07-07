<?php

namespace App\Form;

use App\Entity\Group;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Data for inviting a new user. Backed by a plain DTO ({@see \App\Form\Model\UserInviteData}).
 */
final class UserInviteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, ['constraints' => [new NotBlank()]])
            ->add('email', EmailType::class, ['constraints' => [new NotBlank()]])
            ->add('firstName', TextType::class, ['required' => false, 'label' => 'First name (optional)'])
            ->add('lastName', TextType::class, ['required' => false, 'label' => 'Last name (optional)'])
            ->add('groups', EntityType::class, [
                'class' => Group::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choice_label' => 'name',
                'choices' => $options['available_groups'],
                'label' => 'Groups',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => \App\Form\Model\UserInviteData::class,
            'available_groups' => [],
        ]);
    }
}
