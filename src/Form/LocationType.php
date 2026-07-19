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
                'label' => 'common.label.name',
            ])
            ->add('alias', TextType::class, [
                'label' => 'manage.location.field.alias.label',
                'help' => 'manage.location.field.alias.help',
            ])
            ->add('parent', EntityType::class, [
                'label' => 'manage.location.field.parent.label',
                'class' => Location::class,
                'required' => false,
                'placeholder' => 'manage.location.field.parent.placeholder',
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
                'label' => 'common.label.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('mapUrl', TextType::class, [
                'label' => 'manage.location.field.map_url.label',
                'required' => false,
                'help' => 'manage.location.field.map_url.help',
            ])
            ->add('embedHtml', TextareaType::class, [
                'label' => 'manage.location.field.embed_html.label',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'manage.location.field.embed_html.help',
            ])
            ->add('phone', TextType::class, [
                'label' => 'manage.label.phone',
                'required' => false,
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'common.state.staff_only',
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
