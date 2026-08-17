<?php

namespace App\Controller\Backstage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Repository\LocationCheckInRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserRepository;
use App\Service\DisplaySettings;
use App\Service\Security\LocationCheckInService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The security desk at the venue door: find somebody, decide whether they may come in, and record
 * that they did.
 *
 * This is venue entry, not the once-per-event arrival flag the backstage desk sets. The two are
 * separate on purpose, and nothing here reads or writes `State.arrived`.
 *
 * Lookup reuses the info desk's own finder, so the rules that surface already enforces apply
 * unchanged: an email must match exactly, a number is a registration number and never a database
 * id, and a partial term only ever matches a username. Personal data renders through the `pii`
 * filters, so an operator without `user:pii:view` sees a masked address rather than a real one.
 */
#[Route('/backstage/security')]
#[IsGranted('security:checkin')]
final class SecurityCheckInController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LocationCheckInRepository $checkIns,
        private readonly ShiftEntryRepository $entries,
        private readonly LocationCheckInService $service,
        private readonly DisplaySettings $display,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Search, plus how the day is going so far.
     *
     * A single exact hit goes straight to the person: at a door, the extra click is a queue.
     */
    #[Route('', name: 'app_backstage_security', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $results = $query === '' ? [] : $this->users->locate($query);

        if (\count($results) === 1) {
            return $this->redirectToRoute('app_backstage_security_user', ['id' => $results[0]->getUuid()]);
        }

        return $this->render('backstage/security/search.html.twig', [
            'q' => $query,
            'results' => $results,
            'today' => $this->countsFor($this->service->localDate(new \DateTimeImmutable())),
        ]);
    }

    /** One person: who they are, why they are here today, whether they are inside, and their history. */
    #[Route('/{id}', name: 'app_backstage_security_user', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function user(#[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        $now = new \DateTimeImmutable();
        $day = $this->service->localDate($now);

        return $this->render('backstage/security/user.html.twig', [
            'subject' => $user,
            'decision' => $this->service->decide($user, $now),
            'inside' => $this->service->isInside($user, $now),
            'shifts' => $this->entries->findForUserBetween($user, $day, $day->modify('+1 day')),
            'history' => $this->checkIns->findHistoryForUser($user),
            'windowSeconds' => $this->service->windowSeconds(),
        ]);
    }

    /**
     * Admit somebody.
     *
     * An override is refused unless the caller holds the privilege for it, so a screen that offers
     * the field to everybody still cannot be used by everybody. The service refuses outright when
     * the rules say no and no reason was given, which is what keeps the two halves in step.
     */
    #[Route('/{id}/enter', name: 'app_backstage_security_enter', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function enter(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if (!$this->isCsrfTokenValid('security-checkin'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('common.flash.invalid_token'));

            return $this->redirectToRoute('app_backstage_security_user', ['id' => $user->getUuid()]);
        }

        $reason = trim((string) $request->request->get('override_reason', ''));
        if ($reason !== '' && !$this->isGranted('security:checkin:override')) {
            $this->addFlash('danger', new TranslatableMessage('backstage.security.flash.override_denied'));

            return $this->redirectToRoute('app_backstage_security_user', ['id' => $user->getUuid()]);
        }

        try {
            $row = $this->service->enter($user, $this->getUser(), $reason === '' ? null : $reason);
        } catch (\RuntimeException) {
            $this->addFlash('danger', new TranslatableMessage('backstage.security.flash.refused'));

            return $this->redirectToRoute('app_backstage_security_user', ['id' => $user->getUuid()]);
        }

        $this->addFlash('success', new TranslatableMessage(
            $row->isOverridden() ? 'backstage.security.flash.entered_override' : 'backstage.security.flash.entered',
            ['%name%' => $user->getName()],
        ));

        return $this->redirectToRoute('app_backstage_security_user', ['id' => $user->getUuid()]);
    }

    /** Take an entry back. The entry stays in the record; a withdrawal is appended beside it. */
    #[Route('/{id}/withdraw', name: 'app_backstage_security_withdraw', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function withdraw(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if ($this->isCsrfTokenValid('security-checkin'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->service->withdraw($user, $this->getUser());
            $this->addFlash('success', new TranslatableMessage('backstage.security.flash.withdrawn', ['%name%' => $user->getName()]));
        }

        return $this->redirectToRoute('app_backstage_security_user', ['id' => $user->getUuid()]);
    }

    /**
     * How many came in on a day, split staff and non-staff, with the list behind it.
     *
     * The date is cast rather than parsed strictly: a hand-edited or empty value shows today rather
     * than answering 400.
     */
    #[Route('/report/day', name: 'app_backstage_security_report', methods: ['GET'])]
    public function report(Request $request): Response
    {
        $day = $this->requestedDay($request);

        return $this->render('backstage/security/report.html.twig', [
            'day' => $day,
            'counts' => $this->countsFor($day),
            'rows' => $this->insideOn($day),
            'dates' => $this->checkIns->findActiveDates(),
        ]);
    }

    /**
     * The same day as a file.
     *
     * A list of who was on site travels further than a page does, so it is audited as a data export
     * rather than as an ordinary read. The empty escape character passed to fputcsv() is deliberate:
     * PHP 8.4 deprecates leaving it to a default that is going to change, and a backslash in an
     * override reason must not silently alter the next field.
     */
    #[Route('/report/day.csv', name: 'app_backstage_security_report_csv', methods: ['GET'])]
    public function reportCsv(Request $request): Response
    {
        $day = $this->requestedDay($request);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['username', 'registration_number', 'staff', 'entered_at', 'admitted_by', 'overridden', 'override_reason'], ',', '"', '');
        foreach ($this->insideOn($day) as $row) {
            fputcsv($handle, [
                $row->getUser()->getName(),
                (string) $row->getUser()->getPersonalData()?->getBadgeNumber(),
                $row->getUser()->isStaff() ? 'yes' : 'no',
                $row->getOccurredAt()->setTimezone($this->display->timezone())->format('Y-m-d H:i'),
                $row->getActor()?->getName() ?? '',
                $row->isOverridden() ? 'yes' : 'no',
                (string) $row->getOverrideReason(),
            ], ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $this->audit->log(AuditEvents::DATA_EXPORT, AuditEvents::READ, [
            'resourceType' => 'LocationCheckIn',
            'details' => ['day' => $day->format('Y-m-d')],
        ]);

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="location-check-in-%s.csv"', $day->format('Y-m-d')),
        ]);
    }

    private function requestedDay(Request $request): \DateTimeImmutable
    {
        $raw = trim((string) $request->query->get('day', ''));
        $parsed = $raw === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $parsed === false ? $this->service->localDate(new \DateTimeImmutable()) : $parsed;
    }

    /**
     * The people who ended that day inside, newest entry first.
     *
     * Somebody whose last row is a withdrawal is left out, so an entry logged by mistake does not
     * inflate the count even though the row that recorded it stays.
     *
     * @return \App\Entity\LocationCheckIn[]
     */
    private function insideOn(\DateTimeImmutable $day): array
    {
        $rows = array_filter(
            $this->checkIns->latestPerUserForDate($day),
            static fn ($row): bool => $row->isEntry(),
        );
        usort($rows, static fn ($a, $b): int => $b->getOccurredAt() <=> $a->getOccurredAt());

        return array_values($rows);
    }

    /** @return array{staff: int, volunteers: int, total: int} */
    private function countsFor(\DateTimeImmutable $day): array
    {
        $staff = 0;
        $volunteers = 0;
        foreach ($this->insideOn($day) as $row) {
            $row->getUser()->isStaff() ? ++$staff : ++$volunteers;
        }

        return ['staff' => $staff, 'volunteers' => $volunteers, 'total' => $staff + $volunteers];
    }
}
