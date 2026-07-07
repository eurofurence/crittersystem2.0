<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Reusable rich-text field. Renders a textarea enhanced by the `rich-text`
 * Stimulus controller; the value is HTML, sanitised on the server before use.
 * Reuse this anywhere rich content is edited (privacy notice, news, FAQ, ...).
 */
final class RichTextType extends AbstractType
{
    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => [
                'data-controller' => 'rich-text',
                'rows' => 12,
            ],
        ]);
    }
}
