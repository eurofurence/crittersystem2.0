<?php

namespace App\Form;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Repository\PrivilegeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class GroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'common.label.name',
                'help' => 'manage.group.field.name.help',
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'common.label.role',
                'required' => false,
                'placeholder' => 'manage.group.field.role.placeholder',
                'help' => 'manage.group.field.role.help',
                'choices' => [
                    'manage.group.role.staff' => 'ROLE_STAFF',
                    'manage.group.role.sub_admin' => 'ROLE_SUBADMIN',
                    'manage.group.role.global_admin' => 'ROLE_ADMIN',
                ],
            ])
            ->add('privileges', EntityType::class, [
                'class' => Privilege::class,
                'multiple' => true,
                'expanded' => true,
                'by_reference' => false,
                'choice_label' => static fn (Privilege $p): string => $p->getName(),
                'choice_value' => static fn (?Privilege $p): ?string => $p?->getName(),
                'query_builder' => static fn (PrivilegeRepository $r) => $r->createQueryBuilder('p')->orderBy('p.name', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Group::class]);
    }
}
