<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftGroup;
use App\Entity\ShiftTask;
use App\Enum\ShiftAudience;
use App\Repository\DepartmentRepository;
use App\Repository\ShiftGroupRepository;
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
            ->add('title', TextType::class, ['label' => 'common.label.title'])
            ->add('description', TextareaType::class, [
                'label' => 'common.label.description',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => Shift::DESCRIPTION_MAX_LENGTH],
            ])
            ->add('url', UrlType::class, [
                'label' => 'manage.shift.field.url.label',
                'required' => false,
                'default_protocol' => null,
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'manage.shift.field.starts_at.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'view_timezone' => $tz,
                'model_timezone' => 'UTC',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'manage.shift.field.ends_at.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'view_timezone' => $tz,
                'model_timezone' => 'UTC',
            ])
            ->add('department', EntityType::class, [
                'label' => 'common.label.department',
                'class' => Department::class,
                'choice_label' => 'name',
                // Organizational departments cannot own shifts.
                'query_builder' => fn (DepartmentRepository $repo) => $repo->createQueryBuilder('d')
                    ->andWhere('d.organizational = false')
                    ->orderBy('d.name', 'ASC'),
            ])
            ->add('shiftTask', EntityType::class, [
                'label' => 'manage.shift.field.shift_task.label',
                'class' => ShiftTask::class,
                'choice_label' => 'displayName',
                'required' => false,
                'placeholder' => 'manage.shift.placeholder.none',
            ])
            ->add('location', EntityType::class, [
                'label' => 'common.label.location',
                'class' => Location::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'manage.shift.placeholder.none',
            ])
            ->add('shiftGroup', EntityType::class, [
                'label' => 'manage.shift.field.shift_group.label',
                'class' => ShiftGroup::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'manage.shift.placeholder.none',
                'help' => 'manage.shift.field.shift_group.help',
                // A group and its members share one department, because shift:manage is scoped by
                // department and a group spanning two would have none to check against. Membership is
                // otherwise managed on the group's own screen, where the manager is warned about the
                // volunteers a change leaves on a partial commitment.
                'query_builder' => fn (ShiftGroupRepository $repo) => $repo->createQueryBuilder('g')
                    ->orderBy('g.name', 'ASC'),
            ])
            ->add('audience', EnumType::class, [
                'label' => 'manage.shift.field.audience.label',
                'class' => ShiftAudience::class,
                'choice_label' => fn (ShiftAudience $a) => $a->label(),
                'help' => 'manage.shift.field.audience.help',
            ])
            ->add('requireCheckin', CheckboxType::class, [
                'label' => 'manage.shift.field.require_checkin.label',
                'required' => false,
                'help' => 'manage.shift.field.require_checkin.help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Shift::class,
        ]);
    }
}
