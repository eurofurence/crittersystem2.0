<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\AvailabilityValue;
use App\Service\Availability\AvailabilityService;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use App\Service\Shift\PlannerPresenter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A user's global Planning Availability self-service. A crab.fit-style
 * grid where the user paints Preferred/Available/Avoid/Unavailable ranges over
 * the event window; confirmed assignments show as occupied overlays. One global
 * schedule, reusable by every department the user belongs to.
 */
#[IsGranted('ROLE_USER')]
final class AvailabilityController extends AbstractController
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly LoggerInterface $logger,
        private readonly PlannerPresenter $presenter,
        private readonly DisplaySettings $display,
        private readonly EventConfigStore $config,
    ) {
    }

    #[Route('/availability', name: 'app_availability', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tz = $this->display->timezone();
        [$rangeStart, $rangeEnd] = $this->range();

        $days = $this->presenter->dayList(
            $rangeStart,
            $rangeEnd,
            $tz,
            $this->config->getDate(EventConfigStore::KEY_EVENT_START),
            $this->config->getDate(EventConfigStore::KEY_EVENT_END),
        );

        $availability = $this->availability->getOrCreate($user);

        return $this->render('availability/index.html.twig', [
            'days' => $days,
            'ranges' => $this->serializeRanges($this->availability->rangesForUser($user), $tz),
            'overlays' => $this->serializeOverlays($this->availability->occupiedOverlays($user), $tz),
            'comment' => $availability->getComment(),
            'values' => AvailabilityValue::cases(),
            'timezone' => $tz->getName(),
            'rasterMinutes' => 60,
        ]);
    }

    #[Route('/availability', name: 'app_availability_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('availability_submit', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid token.'], 419);
        }

        $tz = $this->display->timezone();
        $payload = json_decode((string) $request->request->get('ranges', '[]'), true);
        $ranges = [];
        $rejected = 0;
        foreach (\is_array($payload) ? $payload : [] as $raw) {
            $value = AvailabilityValue::tryFrom((string) ($raw['value'] ?? ''));
            if ($value === null) {
                ++$rejected;
                continue;
            }
            try {
                $ranges[] = [
                    'start' => new \DateTimeImmutable((string) $raw['start'], $tz),
                    'end' => new \DateTimeImmutable((string) $raw['end'], $tz),
                    'value' => $value,
                ];
            } catch (\Exception $e) {
                ++$rejected;
                $this->logger->warning('Dropped an unparseable availability range: {reason}', [
                    'reason' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $this->availability->submit($user, $ranges, (string) $request->request->get('comment') ?: null);

        /*
         * The grid builds this payload itself, so a rejected entry means a client bug — and telling the
         * user "saved" while quietly discarding part of what they drew loses their work without a trace.
         */
        if ($rejected > 0) {
            $this->addFlash('warning', sprintf(
                'Your availability was saved, but %d entr%s could not be read and %s not stored. Please check the grid.',
                $rejected,
                $rejected === 1 ? 'y' : 'ies',
                $rejected === 1 ? 'was' : 'were',
            ));
        } else {
            $this->addFlash('success', 'Your availability was saved.');
        }

        return $this->redirectToRoute('app_availability');
    }

    /**
     * @param \App\Entity\AvailabilityRange[] $ranges
     *
     * @return list<array{day: string, startMin: int, endMin: int, value: string}>
     */
    private function serializeRanges(array $ranges, \DateTimeZone $tz): array
    {
        $out = [];
        foreach ($ranges as $range) {
            $start = $range->getStartsAt()->setTimezone($tz);
            $end = $range->getEndsAt()->setTimezone($tz);
            $out[] = [
                'day' => $start->format('Y-m-d'),
                'startMin' => (int) $start->format('H') * 60 + (int) $start->format('i'),
                'endMin' => $this->endMinutes($start, $end),
                'value' => $range->getValue()->value,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, title: string}> $overlays
     *
     * @return list<array{day: string, startMin: int, endMin: int, title: string}>
     */
    private function serializeOverlays(array $overlays, \DateTimeZone $tz): array
    {
        $out = [];
        foreach ($overlays as $overlay) {
            $start = $overlay['start']->setTimezone($tz);
            $end = $overlay['end']->setTimezone($tz);
            $out[] = [
                'day' => $start->format('Y-m-d'),
                'startMin' => (int) $start->format('H') * 60 + (int) $start->format('i'),
                'endMin' => $this->endMinutes($start, $end),
                'title' => $overlay['title'],
            ];
        }

        return $out;
    }

    private function endMinutes(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $minutes = (int) $start->format('H') * 60 + (int) $start->format('i')
            + (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);

        return min($minutes, 1440);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function range(): array
    {
        $start = $this->config->getDate(EventConfigStore::KEY_BUILDUP_START)
            ?? $this->config->getDate(EventConfigStore::KEY_EVENT_START)
            ?? new \DateTimeImmutable('today');
        $end = $this->config->getDate(EventConfigStore::KEY_TEARDOWN_END)
            ?? $this->config->getDate(EventConfigStore::KEY_EVENT_END)
            ?? $start->modify('+3 days');

        return [$start, $end->modify('+1 day')];
    }
}
