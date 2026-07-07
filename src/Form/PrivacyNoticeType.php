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
            ->add('eventName', TextType::class, ['label' => 'Event name'])
            ->add('controllerOrg', TextType::class, ['label' => 'Data controller — organization'])
            ->add('controllerEmail', EmailType::class, ['label' => 'Data controller — contact email'])
            ->add('contactEmail', EmailType::class, ['label' => 'Privacy / contact email'])
            ->add('deletionDays', IntegerType::class, ['label' => 'Days until data deletion'])
            ->add('bodyHtml', RichTextType::class, [
                'label' => 'Notice body',
                'help' => 'Variables: %event_name, %organization, %controller_email, %contact_email, %deletion_days',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PrivacyNotice::class]);
    }
}
