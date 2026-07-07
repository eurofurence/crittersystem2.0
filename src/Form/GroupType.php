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
                'help' => 'Display name of the group.',
            ])
            ->add('role', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'No elevated role (regular users)',
                'help' => 'Coarse role this group grants for firewall access.',
                'choices' => [
                    'Staff' => 'ROLE_STAFF',
                    'Sub admin' => 'ROLE_SUBADMIN',
                    'Global admin' => 'ROLE_ADMIN',
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
