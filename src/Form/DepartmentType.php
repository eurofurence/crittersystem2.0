<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\VolunteerType;
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
    public function __construct(private readonly SluggerInterface $slugger)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'required' => false,
                'help' => 'Leave blank to generate from the name.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'Staff only',
                'required' => false,
            ])
            ->add('organizational', CheckboxType::class, [
                'label' => 'Organizational (cannot own shifts)',
                'required' => false,
                'disabled' => (bool) $options['lock_organizational'],
                'help' => 'Can only be changed while the department has no shifts.',
            ])
            ->add('locations', EntityType::class, [
                'label' => 'Locations',
                'class' => Location::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'by_reference' => false,
                'attr' => ['size' => 6],
            ])
            ->add('volunteerTypes', EntityType::class, [
                'label' => 'Volunteer types',
                'class' => VolunteerType::class,
                'choice_label' => 'name',
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
