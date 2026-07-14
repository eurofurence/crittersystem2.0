<?php

namespace App\Controller;

use App\Gdpr\BanChecker;
use App\Repository\BannedIdentityRepository;
use App\Repository\UserRepository;
use App\Service\EventConfigStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public "appeal the ban" page. To users the ban is never named: it presents the
 * inability to reach "The Ledger-Keeper". Submitting flags the ban for admin
 * review and emails the site administrators. The response is always the same so
 * ban status is not disclosed.
 */
final class BanAppealController extends AbstractController
{
    public const LEDGER_KEEPER = "We can't reach The Ledger-Keeper right now — he seems to be on vacation.";

    public function __construct(
        private readonly BanChecker $bans,
        private readonly BannedIdentityRepository $banRepository,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly EventConfigStore $config,
    ) {
    }

    #[Route('/appeal', name: 'app_ban_appeal', methods: ['GET', 'POST'])]
    public function appeal(Request $request): Response
    {
        $done = false;
        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));
            if ($email !== '') {
                $ban = $this->banRepository->findOneByHash($this->bans->hashEmail($email));
                if ($ban !== null && !$ban->hasAppeal()) {
                    $ban->requestAppeal();
                    $this->em->flush();
                    $this->notifyAdmins();
                }
            }
            $done = true;
        }

        return $this->render('ban/appeal.html.twig', [
            'message' => self::LEDGER_KEEPER,
            'extraMessage' => $this->config->get(EventConfigStore::KEY_BAN_SCREEN_MESSAGE),
            'done' => $done,
        ]);
    }

    private function notifyAdmins(): void
    {
        foreach ($this->users->findByGroupRole('ROLE_ADMIN') as $admin) {
            $this->mailer->send(
                (new Email())->from('noreply@critter.example')->to($admin->getEmail())
                    ->subject('Ban appeal submitted')
                    ->text('A user has submitted a ban appeal. Review pending appeals in the ban management page.'),
            );
        }
    }
}
