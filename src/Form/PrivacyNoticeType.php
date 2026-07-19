<?php

namespace App\Form;

use App\Entity\PrivacyNotice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PrivacyNoticeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('eventName', TextType::class, ['label' => 'manage.privacy_notice.field.event_name.label'])
            ->add('controllerOrg', TextType::class, ['label' => 'manage.privacy_notice.field.controller_org.label'])
            ->add('controllerEmail', EmailType::class, ['label' => 'manage.privacy_notice.field.controller_email.label'])
            ->add('contactEmail', EmailType::class, ['label' => 'manage.privacy_notice.field.contact_email.label'])
            ->add('deletionDays', IntegerType::class, ['label' => 'manage.privacy_notice.field.deletion_days.label'])
            ->add('bodyHtml', RichTextType::class, [
                'label' => 'manage.privacy_notice.field.body.label',
                'help' => 'manage.privacy_notice.field.body.help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PrivacyNotice::class]);
    }
}
