<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\ShiftGroup;
use App\Repository\DepartmentRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shift group's own fields. Members are added and removed on the edit screen rather than here,
 * because adding one has consequences the manager has to be told about first.
 *
 * Not bound to the entity: a new group needs its department before it can be constructed at all, and
 * the controller refuses to move a group that already holds members (its members would land in
 * another department's permission scope).
 */
final class ShiftGroupType extends AbstractType
{
    public function __construct(
        private readonly Security $security,
        private readonly DepartmentRepository $departments,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'common.label.name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 128)],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'common.label.description',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => ShiftGroup::DESCRIPTION_MAX_LENGTH],
                'help' => 'manage.shift_group.field.description.help',
                'constraints' => [new Assert\Length(max: ShiftGroup::DESCRIPTION_MAX_LENGTH)],
            ])
            ->add('department', EntityType::class, [
                'label' => 'common.label.department',
                'class' => Department::class,
                'choice_label' => 'name',
                'help' => 'manage.shift_group.field.department.help',
                'constraints' => [new Assert\NotNull()],
                // Only departments this manager holds shift:manage on, and never an organizational
                // one: those cannot own shifts, so they cannot own a group of them.
                'choices' => $this->manageableDepartments($options['group_department']),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'group_department' => null,
        ]);
        $resolver->setAllowedTypes('group_department', [Department::class, 'null']);
    }

    /**
     * The group's current department stays in the list even if the manager could not otherwise pick
     * it, so opening the form never silently rewrites the group on save.
     *
     * @return list<Department>
     */
    private function manageableDepartments(?Department $current): array
    {
        $choices = array_values(array_filter(
            $this->departments->findAllOrdered(),
            fn (Department $department): bool => !$department->isOrganizational()
                && $this->security->isGranted('shift:manage', $department),
        ));

        if ($current !== null && !\in_array($current, $choices, true)) {
            array_unshift($choices, $current);
        }

        return $choices;
    }
}
