<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Enum\ShiftAudience;
use App\Repository\DepartmentRepository;
use App\Service\DisplaySettings;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ShiftFormType extends AbstractType
{
    public function __construct(private readonly DisplaySettings $display)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The admin enters wall-clock times in the configured event timezone;
        // they are stored in UTC (model_timezone) so every datetime in the
        // system is an absolute instant. See {@see DisplaySettings}.
        $tz = $this->display->timezone()->getName();

        $builder
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('url', UrlType::class, [
                'label' => 'URL',
                'required' => false,
                'default_protocol' => null,
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Starts at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'view_timezone' => $tz,
                'model_timezone' => 'UTC',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Ends at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'view_timezone' => $tz,
                'model_timezone' => 'UTC',
            ])
            ->add('department', EntityType::class, [
                'label' => 'Department',
                'class' => Department::class,
                'choice_label' => 'name',
                // Organizational departments cannot own shifts.
                'query_builder' => fn (DepartmentRepository $repo) => $repo->createQueryBuilder('d')
                    ->andWhere('d.organizational = false')
                    ->orderBy('d.name', 'ASC'),
            ])
            ->add('shiftTask', EntityType::class, [
                'label' => 'Shift Task',
                'class' => ShiftTask::class,
                'choice_label' => 'displayName',
                'required' => false,
                'placeholder' => '— None —',
            ])
            ->add('location', EntityType::class, [
                'label' => 'Location',
                'class' => Location::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— None —',
            ])
            ->add('audience', EnumType::class, [
                'label' => 'Audience',
                'class' => ShiftAudience::class,
                'choice_label' => fn (ShiftAudience $a) => $a->label(),
                'help' => 'Staff-only shifts are never shown to volunteers.',
            ])
            ->add('requireCheckin', CheckboxType::class, [
                'label' => 'Require check-in',
                'required' => false,
                'help' => 'Volunteers must be checked in before applying — even during setup and teardown.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Shift::class,
        ]);
    }
}
