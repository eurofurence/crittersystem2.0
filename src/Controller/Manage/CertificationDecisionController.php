<?php

namespace App\Controller\Manage;

use App\Entity\Certification;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Repository\CertificationRepository;
use App\Repository\UserCertificationRepository;
use App\Service\CertificationService;
use App\Service\UserSearchResultFormatter;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Deciding on a volunteer's certification: the queue of applications, and approve / decline / revoke
 * on a single record.
 *
 * Behind `certification:approve` rather than `certification:manage`: editing what a certification is
 * and deciding who holds it are different jobs, and the catalogue carries a separate privilege for
 * the second one. `certification:view` would be no gate at all: every volunteer holds it.
 *
 * A record is addressed by the certification and the volunteer, both by public uuid. The pair is
 * unique, and it keeps the two things being authorised visible in the URL.
 */
#[Route('/manage/certifications')]
#[IsGranted('certification:approve')]
final class CertificationDecisionController extends AbstractController
{
    public function __construct(
        private readonly UserCertificationRepository $records,
        private readonly CertificationService $service,
    ) {
    }

    #[Route('/queue', name: 'app_manage_certification_queue', methods: ['GET'])]
    public function queue(): Response
    {
        return $this->render('manage/certification/queue.html.twig', [
            'pending' => $this->records->findPending(),
        ]);
    }

    /**
     * Type-ahead source for the holder picker. Answers with public uuids, like every other user
     * search, and is behind the same privilege as the grant it feeds.
     */
    #[Route('/user-search', name: 'app_manage_certification_user_search', methods: ['GET'])]
    public function userSearch(Request $request, UserRepository $users, UserSearchResultFormatter $formatter): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        return new JsonResponse($formatter->results($q === '' ? [] : $users->searchByName($q)));
    }

    /**
     * Add a certification to somebody directly, from either the certification's page or the user's.
     *
     * Both identifiers travel in the body rather than the path: the same form is rendered with one
     * side fixed and the other chosen, and a path would have to change as the picker changes.
     *
     * The holders are read through `request->all()['user']` because the picker submits `user[]`,
     * one entry per chip, while the user's own page submits a single fixed `user`. Both are treated
     * as a list, since granting to one person and to twenty is the same action. Neither accessor
     * copes with both shapes: `all('user')` insists the value is an array and throws on the single
     * hidden field, and `get('user')` throws on the picker's array.
     */
    #[Route('/grant', name: 'app_manage_certification_grant', methods: ['POST'])]
    public function grant(Request $request, CertificationRepository $certifications, UserRepository $users): Response
    {
        if (!$this->isCsrfTokenValid('cert-grant', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('common.flash.invalid_token'));

            return $this->redirectToRoute('app_manage_certification_index');
        }

        $certification = $certifications->findOneByUuid((string) $request->request->get('certification'));

        $submitted = $request->request->all()['user'] ?? [];
        $holders = [];
        foreach ((array) $submitted as $uuid) {
            $holder = $users->findOneByUuid(trim((string) $uuid));
            if ($holder !== null) {
                $holders[$holder->getId()] = $holder;
            }
        }

        if ($certification === null || $holders === []) {
            $this->addFlash('danger', new TranslatableMessage('manage.certification.flash.grant_incomplete'));

            return $this->grantRedirect($request, $certification, null);
        }

        $status = (string) $request->request->get('status', UserCertification::STATUS_APPROVED);
        if (!\in_array($status, [UserCertification::STATUS_APPROVED, UserCertification::STATUS_PENDING], true)) {
            $status = UserCertification::STATUS_APPROVED;
        }

        try {
            $certified = $this->date($request->request->get('date_certified'));
            $expires = $this->date($request->request->get('date_expires'));
        } catch (\Exception) {
            $this->addFlash('danger', new TranslatableMessage('manage.certification.flash.grant_bad_date'));

            return $this->grantRedirect($request, $certification, reset($holders) ?: null);
        }

        $notes = trim((string) $request->request->get('notes')) ?: null;
        foreach ($holders as $holder) {
            $this->service->grant($certification, $holder, $status, $certified, $expires, $notes, $this->actor());
        }

        $this->addFlash('success', \count($holders) === 1
            ? new TranslatableMessage('manage.certification.flash.granted', [
                '%name%' => reset($holders)->getName(),
                '%certification%' => $certification->getTitle(),
            ])
            : new TranslatableMessage('manage.certification.flash.granted_many', [
                '%count%' => \count($holders),
                '%certification%' => $certification->getTitle(),
            ]));

        return $this->grantRedirect($request, $certification, \count($holders) === 1 ? reset($holders) : null);
    }

    /** A blank date field is "not given", not a malformed request. */
    private function date(mixed $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);

        return $value === '' ? null : new \DateTimeImmutable($value);
    }

    private function grantRedirect(Request $request, ?Certification $certification, ?User $holder): Response
    {
        if ($request->request->get('from') === 'user' && $holder !== null) {
            return $this->redirectToRoute('app_manage_user_edit', ['id' => $holder->getUuid()]);
        }

        return $certification !== null
            ? $this->redirectToRoute('app_manage_certification_show', ['id' => $certification->getUuid()])
            : $this->redirectToRoute('app_manage_certification_index');
    }

    #[Route('/{id}/holders/{userId}/approve', name: 'app_manage_certification_approve', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'userId' => Requirement::UUID])]
    public function approve(
        Request $request,
        #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification,
        #[MapEntity(mapping: ['userId' => 'uuid'])] User $holder,
    ): Response {
        $record = $this->record($certification, $holder, $request);
        if (!$record instanceof UserCertification) {
            return $this->back($request, $certification);
        }

        $this->service->approve($record, $this->actor(), trim((string) $request->request->get('reason')) ?: null);
        $this->addFlash('success', new TranslatableMessage('manage.certification.flash.approved', ['%name%' => $holder->getName()]));

        return $this->back($request, $certification);
    }

    #[Route('/{id}/holders/{userId}/reject', name: 'app_manage_certification_reject', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'userId' => Requirement::UUID])]
    public function reject(
        Request $request,
        #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification,
        #[MapEntity(mapping: ['userId' => 'uuid'])] User $holder,
    ): Response {
        return $this->decideAgainst($request, $certification, $holder, 'reject');
    }

    #[Route('/{id}/holders/{userId}/revoke', name: 'app_manage_certification_revoke', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'userId' => Requirement::UUID])]
    public function revoke(
        Request $request,
        #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification,
        #[MapEntity(mapping: ['userId' => 'uuid'])] User $holder,
    ): Response {
        return $this->decideAgainst($request, $certification, $holder, 'revoke');
    }

    /**
     * Declining and revoking both take something away, and both demand a reason: the volunteer is
     * told what was decided, and a bare "no" is not something they can act on.
     */
    private function decideAgainst(Request $request, Certification $certification, User $holder, string $action): Response
    {
        $record = $this->record($certification, $holder, $request);
        if (!$record instanceof UserCertification) {
            return $this->back($request, $certification);
        }

        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('danger', new TranslatableMessage('manage.certification.flash.reason_required'));

            return $this->back($request, $certification);
        }

        if ($action === 'reject') {
            $this->service->reject($record, $this->actor(), $reason);
            $this->addFlash('success', new TranslatableMessage('manage.certification.flash.rejected', ['%name%' => $holder->getName()]));
        } else {
            $this->service->revoke($record, $this->actor(), $reason);
            $this->addFlash('success', new TranslatableMessage('manage.certification.flash.revoked', ['%name%' => $holder->getName()]));
        }

        return $this->back($request, $certification);
    }

    /**
     * Decide on several records at once.
     *
     * Each entry names both sides as `<certification uuid>:<user uuid>`, because the queue spans
     * certifications and a row there is not identified by the page it sits on. A record the action
     * does not fit is skipped rather than forced - a list worked through in a hurry should not turn
     * a decline into an approval - and the count of skipped ones is reported back.
     */
    #[Route('/bulk', name: 'app_manage_certification_bulk', methods: ['POST'])]
    public function bulk(Request $request, CertificationRepository $certifications, UserRepository $users): Response
    {
        if (!$this->isCsrfTokenValid('cert-bulk', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('common.flash.invalid_token'));

            return $this->redirectToRoute('app_manage_certification_queue');
        }

        $action = (string) $request->request->get('action');
        if (!\in_array($action, ['approve', 'reject', 'revoke'], true)) {
            return $this->redirectToRoute('app_manage_certification_queue');
        }

        $reason = trim((string) $request->request->get('reason'));
        if ($action !== 'approve' && $reason === '') {
            $this->addFlash('danger', new TranslatableMessage('manage.certification.flash.reason_required'));

            return $this->bulkRedirect($request);
        }

        $applied = 0;
        $skipped = 0;
        foreach ((array) $request->request->all('records') as $pair) {
            $record = $this->pair((string) $pair, $certifications, $users);
            if ($record === null || !$this->fits($action, $record)) {
                ++$skipped;
                continue;
            }

            match ($action) {
                'approve' => $this->service->approve($record, $this->actor(), $reason ?: null),
                'reject' => $this->service->reject($record, $this->actor(), $reason),
                'revoke' => $this->service->revoke($record, $this->actor(), $reason),
            };
            ++$applied;
        }

        $this->addFlash('success', new TranslatableMessage('manage.certification.flash.bulk_applied', ['%count%' => $applied]));
        if ($skipped > 0) {
            $this->addFlash('warning', new TranslatableMessage('manage.certification.flash.bulk_skipped', ['%count%' => $skipped]));
        }

        return $this->bulkRedirect($request);
    }

    /** Whether the action means anything for the state this record is in. */
    private function fits(string $action, UserCertification $record): bool
    {
        $status = $record->effectiveStatus();

        return match ($action) {
            'approve' => $status !== UserCertification::STATUS_APPROVED,
            'reject' => $status === UserCertification::STATUS_PENDING,
            'revoke' => \in_array($status, [UserCertification::STATUS_APPROVED, UserCertification::STATUS_SELF_CONFIRMED], true),
            default => false,
        };
    }

    private function pair(string $pair, CertificationRepository $certifications, UserRepository $users): ?UserCertification
    {
        [$certificationUuid, $userUuid] = array_pad(explode(':', $pair, 2), 2, '');
        $certification = $certifications->findOneByUuid($certificationUuid);
        $holder = $users->findOneByUuid($userUuid);

        return $certification !== null && $holder !== null
            ? $this->records->findOneByUserAndCertification($holder, $certification)
            : null;
    }

    private function bulkRedirect(Request $request): Response
    {
        $certification = (string) $request->request->get('certification');

        return $certification !== '' && $request->request->get('from') !== 'queue'
            ? $this->redirectToRoute('app_manage_certification_show', ['id' => $certification])
            : $this->redirectToRoute('app_manage_certification_queue');
    }

    /**
     * Take the certification away from every current holder.
     *
     * The count is put in front of whoever asks for it before anything happens: this is the one
     * action here that touches people who were never looked at individually.
     */
    #[Route('/{id}/revoke-all', name: 'app_manage_certification_revoke_all', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function revokeAll(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        if (!$this->isCsrfTokenValid('cert-revoke-all'.$certification->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('common.flash.invalid_token'));

            return $this->redirectToRoute('app_manage_certification_show', ['id' => $certification->getUuid()]);
        }

        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('danger', new TranslatableMessage('manage.certification.flash.reason_required'));

            return $this->redirectToRoute('app_manage_certification_show', ['id' => $certification->getUuid()]);
        }

        $revoked = $this->service->revokeAll($certification, $this->actor(), $reason);
        $this->addFlash('success', new TranslatableMessage('manage.certification.flash.revoked_all', ['%count%' => $revoked]));

        return $this->redirectToRoute('app_manage_certification_show', ['id' => $certification->getUuid()]);
    }

    /** The record these two identify, once the token proves the request came from our own form. */
    private function record(Certification $certification, User $holder, Request $request): ?UserCertification
    {
        if (!$this->isCsrfTokenValid('cert-decide'.$certification->getId().'-'.$holder->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('common.flash.invalid_token'));

            return null;
        }

        $record = $this->records->findOneByUserAndCertification($holder, $certification);
        if ($record === null) {
            $this->addFlash('danger', new TranslatableMessage('manage.certification.flash.no_record'));
        }

        return $record;
    }

    /**
     * Back where the decision was made. The queue and the certification's own page both list records,
     * and being thrown to the other one loses the place in a list being worked through.
     */
    private function back(Request $request, Certification $certification): Response
    {
        if ($request->request->get('from') === 'queue') {
            return $this->redirectToRoute('app_manage_certification_queue');
        }

        return $this->redirectToRoute('app_manage_certification_show', ['id' => $certification->getUuid()]);
    }

    private function actor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
