<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * One-shot backup-bucket configuration for a connectivity test. Not mapped to any
 * entity and never persisted: the app must not hold the backup credentials, so the
 * admin provides them for the single test only. The secret is a PasswordType so it
 * is never rendered back into the page.
 */
final class BackupTestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('endpoint', TextType::class, [
                'required' => false,
                'label' => 'admin.storage.backup.field.endpoint',
                'help' => 'admin.storage.backup.field.endpoint_help',
            ])
            ->add('region', TextType::class, [
                'required' => false,
                'label' => 'admin.storage.backup.field.region',
            ])
            ->add('bucket', TextType::class, [
                'label' => 'admin.storage.backup.field.bucket',
                'constraints' => [new NotBlank()],
            ])
            ->add('prefix', TextType::class, [
                'required' => false,
                'label' => 'admin.storage.backup.field.prefix',
            ])
            ->add('pathStyle', CheckboxType::class, [
                'required' => false,
                'label' => 'admin.storage.backup.field.path_style',
            ])
            ->add('accessKeyId', TextType::class, [
                'required' => false,
                'label' => 'admin.storage.backup.field.access_key',
            ])
            ->add('secretAccessKey', PasswordType::class, [
                'required' => false,
                'label' => 'admin.storage.backup.field.secret_key',
                'always_empty' => true,
            ])
            ->add('runPgDump', CheckboxType::class, [
                'required' => false,
                'label' => 'admin.storage.backup.field.pg_dump',
                'help' => 'admin.storage.backup.field.pg_dump_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
