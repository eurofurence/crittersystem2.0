<?php

namespace App\Form;

use App\Entity\Location;
use App\Repository\LocationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LocationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $current = $builder->getData();
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
            ])
            ->add('alias', TextType::class, [
                'label' => 'Alias',
                'help' => 'Stable key used to match this location on JSON import. Must be unique.',
            ])
            ->add('parent', EntityType::class, [
                'label' => 'Parent location',
                'class' => Location::class,
                'required' => false,
                'placeholder' => '— None (root) —',
                'choice_label' => fn (Location $l) => $l->fullName(),
                // Only roots and first-level children can be parents (max depth 2),
                // and a location cannot be its own parent.
                'query_builder' => function (LocationRepository $repo) use ($current) {
                    $qb = $repo->createQueryBuilder('l')->orderBy('l.name', 'ASC');
                    if ($current instanceof Location && $current->getId() !== null) {
                        $qb->andWhere('l.id != :self')->setParameter('self', $current->getId());
                    }

                    return $qb;
                },
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('mapUrl', TextType::class, [
                'label' => 'Map URL',
                'required' => false,
                'help' => 'https URL from an allowed domain, embedded in an app-controlled iframe.',
            ])
            ->add('embedHtml', TextareaType::class, [
                'label' => 'Map embed (iframe)',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'Optional <iframe> snippet; only allowed-domain https sources are rendered.',
            ])
            ->add('phone', TextType::class, [
                'label' => 'Phone',
                'required' => false,
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'Staff only',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Location::class,
        ]);
    }
}
