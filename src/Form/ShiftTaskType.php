<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\ShiftTask;
use App\Service\Shift\ShiftTaskAccess;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

final class ShiftTaskType extends AbstractType
{
    public function __construct(private readonly ShiftTaskAccess $access)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isAdmin = $this->access->isAdmin();
        $manageable = $this->access->manageableDepartments();

        $builder
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('department', EntityType::class, [
                'label' => 'Department',
                'class' => Department::class,
                'choice_label' => 'name',
                // Only what the user may own. A global task (no department) is shared by every
                // department, so it stays an admin's to make.
                'choices' => $isAdmin ? null : $manageable,
                'required' => !$isAdmin,
                'placeholder' => $isAdmin ? '— None (available to every department) —' : '— Choose a department —',
                'constraints' => $isAdmin ? [] : [new NotNull(message: 'Choose the department this task belongs to.')],
                'help' => $isAdmin
                    ? 'Leave empty to make the task available to every department.'
                    : 'The task is available when planning shifts for this department.',
            ])
            ->add('staffOnly', CheckboxType::class, [
                'label' => 'Staff only',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ShiftTask::class,
        ]);
    }
}
