<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\PrivacyNotice;
use App\Form\PrivacyNoticeType;
use App\Repository\PrivacyNoticeRepository;
use App\Service\PrivacyNoticeProvider;
use App\Service\RichTextSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/manage/privacy-notice')]
#[IsGranted('config:privacy')]
final class PrivacyNoticeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrivacyNoticeRepository $notices,
        private readonly PrivacyNoticeProvider $provider,
        private readonly RichTextSanitizer $sanitizer,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_manage_privacy_notice', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $notice = $this->notices->current();
        $isNew = $notice === null;
        if ($isNew) {
            $notice = new PrivacyNotice();
            $this->provider->applyDefault($notice);
        }

        $form = $this->createForm(PrivacyNoticeType::class, $notice);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $notice->setBodyHtml($this->sanitizer->sanitize($notice->getBodyHtml()));
            if ($isNew) {
                $this->em->persist($notice);
            }
            $this->em->flush();
            $this->audit->log(AuditEvents::CONFIGURATION, AuditEvents::UPDATE, [
                'resourceType' => 'PrivacyNotice',
                'resourceId' => $notice->getId(),
            ]);
            $this->addFlash('success', new TranslatableMessage('manage.privacy_notice.flash.saved'));

            return $this->redirectToRoute('app_manage_privacy_notice');
        }

        return $this->render('manage/privacy_notice/edit.html.twig', [
            'form' => $form,
            'preview' => $this->provider->render($notice),
        ]);
    }

    #[Route('/reset-default', name: 'app_manage_privacy_notice_reset', methods: ['POST'])]
    public function resetDefault(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('privacy_reset', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_privacy_notice');
        }

        $notice = $this->notices->current() ?? new PrivacyNotice();
        $this->provider->applyDefault($notice);
        if ($notice->getId() === null) {
            $this->em->persist($notice);
        }
        $this->em->flush();
        $this->addFlash('success', new TranslatableMessage('manage.privacy_notice.flash.reset'));

        return $this->redirectToRoute('app_manage_privacy_notice');
    }
}
