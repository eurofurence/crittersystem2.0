<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\VolunteerType;
use App\Repository\LocationRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\SluggerInterface;

final class DepartmentType extends AbstractType
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly LocationRepository $locations,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'common.label.name'])
            ->add('slug', TextType::class, [
                'label' => 'manage.label.slug',
                'required' => false,
                'help' => 'manage.department.field.slug.help',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'common.label.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'common.state.staff_only',
                'required' => false,
            ])
            ->add('organizational', CheckboxType::class, [
                'label' => 'manage.department.field.organizational.label',
                'required' => false,
                'disabled' => (bool) $options['lock_organizational'],
                'help' => 'manage.department.field.organizational.help',
            ])
            ->add('locations', EntityType::class, [
                'label' => 'manage.label.locations',
                'class' => Location::class,
                'choice_label' => fn (Location $l) => $l->fullName(),
                'choices' => $this->locations->findAllOrderedByPath(),
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'by_reference' => false,
                'attr' => ['size' => 6],
            ])
            ->add('volunteerTypes', EntityType::class, [
                'label' => 'manage.label.volunteer_types',
                'class' => VolunteerType::class,
                'choice_label' => 'name',
                // Global types belong to every department and are not offered here: claiming one
                // would let a single department's edit restrict a type the whole event relies on.
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('t')
                    ->andWhere('t.global = false')
                    ->orderBy('t.sortOrder', 'ASC')
                    ->addOrderBy('t.name', 'ASC'),
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'by_reference' => false,
                'attr' => ['size' => 6],
            ]);

        // Derive the slug from the name when the admin leaves it blank, before validation.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (\is_array($data) && empty($data['slug']) && !empty($data['name'])) {
                $data['slug'] = strtolower((string) $this->slugger->slug((string) $data['name']));
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Department::class,
            'lock_organizational' => false,
        ]);
        $resolver->setAllowedTypes('lock_organizational', 'bool');
    }
}
