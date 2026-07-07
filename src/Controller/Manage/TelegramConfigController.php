<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\TelegramConfiguration;
use App\Repository\TelegramConfigurationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Site-wide Telegram bot configuration. The API key is stored encrypted; it is
 * write-only here (never displayed) and only changed when a new value is given.
 */
#[Route('/manage/telegram')]
#[IsGranted('config:telegram')]
final class TelegramConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramConfigurationRepository $config,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_manage_telegram', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $config = $this->config->current() ?? new TelegramConfiguration();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('telegram_config', (string) $request->request->get('_token'))) {
                return $this->redirectToRoute('app_manage_telegram');
            }
            $config->setEnabled($request->request->getBoolean('enabled'))
                ->setApiEndpoint(trim((string) $request->request->get('api_endpoint')));

            $apiKey = (string) $request->request->get('api_key');
            if ($apiKey !== '') {
                $config->setApiKey($apiKey);
            }

            if ($config->getId() === null) {
                $this->em->persist($config);
            }
            $this->em->flush();
            $this->audit->log(AuditEvents::CONFIGURATION, AuditEvents::UPDATE, ['resourceType' => 'TelegramConfiguration']);
            $this->addFlash('success', 'Telegram configuration saved.');

            return $this->redirectToRoute('app_manage_telegram');
        }

        return $this->render('manage/telegram/edit.html.twig', ['config' => $config]);
    }
}
