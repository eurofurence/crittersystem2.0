<?php

namespace App\Form;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A single-user form field that submits a public UUID (from the `user-select` type-ahead widget)
 * instead of eager-loading every user into a native `<select>`. Render it with the
 * `f.user_select(..., {multiple: false})` macro pointed at a search endpoint; the submitted UUID is
 * resolved back to the User here.
 */
final class UserPickerType extends AbstractType
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?User $user): string => $user !== null ? (string) $user->getUuid() : '',
            function (?string $uuid): ?User {
                if ($uuid === null || $uuid === '') {
                    return null;
                }
                $user = $this->users->findOneByUuid($uuid);
                if ($user === null) {
                    throw new TransformationFailedException('Unknown user.');
                }

                return $user;
            },
        ));
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }
}
