<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\PersonalData;
use App\Entity\Settings;
use App\Entity\User;
use App\Form\AccountSettingsType;
use App\Form\Model\AccountSettingsData;
use App\Storage\FileStorage;
use App\Theme\ThemeCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Account settings: a Tabler-style page with a left navigation and
 * the editable profile/account form on the right. Read-only fields (username,
 * email, and full name for SSO accounts) are shown but disabled.
 */
#[Route('/settings')]
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThemeCatalog $themes,
        private readonly FileStorage $storage,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $personalData = $user->getPersonalData() ?? new PersonalData($user);
        $contact = $user->getContact() ?? new Contact($user);
        $settings = $user->getSettings() ?? new Settings($user);

        $data = new AccountSettingsData();
        $data->pronoun = $personalData->getPronoun();
        $data->firstName = $personalData->getFirstName();
        $data->lastName = $personalData->getLastName();
        $data->plannedArrivalDate = $personalData->getPlannedArrivalDate();
        $data->plannedDepartureDate = $personalData->getPlannedDepartureDate();
        $data->mobile = $contact->getMobile();
        $data->language = $settings->getLanguage();
        $data->theme = $settings->getTheme();

        $passwordChangeable = !$user->isSsoManaged();
        $form = $this->createForm(AccountSettingsType::class, $data, [
            'name_editable' => $user->canEditFullName(),
            'password_changeable' => $passwordChangeable,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $personalData->setPronoun($data->pronoun ?: null);
            if ($user->canEditFullName()) {
                $personalData->setFirstName($data->firstName ?: null)->setLastName($data->lastName ?: null);
            }
            $personalData->setPlannedArrivalDate($data->plannedArrivalDate)->setPlannedDepartureDate($data->plannedDepartureDate);
            $contact->setMobile($data->mobile ?: null);
            $settings->setLanguage($data->language);
            $settings->setTheme($data->theme !== null && $this->themes->has($data->theme) ? $data->theme : null);

            $this->handleAvatar($form->get('avatar')->getData(), $user, $personalData);

            $passwordOk = true;
            if ($passwordChangeable) {
                $newPassword = (string) $form->get('newPassword')->getData();
                if ($newPassword !== '') {
                    if (!$this->hasher->isPasswordValid($user, (string) $form->get('currentPassword')->getData())) {
                        $form->get('currentPassword')->addError(new FormError('Current password is incorrect.'));
                        $passwordOk = false;
                    } else {
                        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
                    }
                }
            }

            if ($passwordOk) {
                foreach ([$personalData, $contact, $settings] as $entity) {
                    $this->em->persist($entity);
                }
                $user->setPersonalData($personalData)->setContact($contact)->setSettings($settings);
                $this->em->flush();

                $this->addFlash('success', 'Settings saved.');

                return $this->redirectToRoute('app_settings');
            }
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
            'user' => $user,
            'hasAvatar' => $personalData->getAvatarPath() !== null,
        ]);
    }

    #[Route('/theme', name: 'app_settings_theme', methods: ['GET', 'POST'])]
    public function theme(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $settings = $user->getSettings();

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('settings-theme', (string) $request->request->get('_token'))) {
            $choice = (string) $request->request->get('theme', '');
            $newSlug = $choice === '' ? null : $this->themes->find($choice)?->slug;
            if ($settings !== null) {
                $settings->setTheme($newSlug);
                $this->em->flush();
                $this->addFlash('success', $newSlug !== null
                    ? sprintf('Theme set to "%s".', $this->themes->find($newSlug)->name)
                    : 'Theme reset to the system default.');
            }

            return $this->redirectToRoute('app_settings_theme');
        }

        return $this->render('settings/theme.html.twig', [
            'themes' => $this->themes->all(),
            'current' => $settings?->getTheme(),
        ]);
    }

    private function handleAvatar(?UploadedFile $file, User $user, PersonalData $personalData): void
    {
        if ($file === null) {
            return;
        }

        $extension = $file->guessExtension() ?: 'bin';
        $key = sprintf('avatars/%d/%s.%s', $user->getId() ?? 0, bin2hex(random_bytes(8)), $extension);
        $old = $personalData->getAvatarPath();

        $stream = fopen($file->getPathname(), 'r');
        $this->storage->writeStream($key, $stream, $file->getMimeType());
        if (\is_resource($stream)) {
            fclose($stream);
        }
        $personalData->setAvatarPath($key);

        if ($old !== null && $old !== $key && $this->storage->exists($old)) {
            $this->storage->delete($old);
        }
    }
}
