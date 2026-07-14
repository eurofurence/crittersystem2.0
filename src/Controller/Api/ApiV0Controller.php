<?php

namespace App\Controller\Api;

use App\Entity\Location;
use App\Entity\News;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Repository\LocationRepository;
use App\Repository\NewsRepository;
use App\Repository\ShiftRepository;
use App\Repository\ShiftTaskRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\EventConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Public, read-only JSON API. Lives under the stateless ^/api
 * firewall; an API key (Bearer / X-API-Key / ?key=) is only required where
 * noted (e.g. users/self). Staff-only records are excluded.
 */
#[Route('/api/v0-beta')]
final class ApiV0Controller extends AbstractController
{
    public function __construct(
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly LocationRepository $locations,
        private readonly ShiftTaskRepository $shiftTasks,
        private readonly ShiftRepository $shifts,
        private readonly NewsRepository $news,
        private readonly EventConfigStore $config,
    ) {
    }

    #[Route('', name: 'app_api_v0_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'version' => 'v0-beta',
            'endpoints' => [
                'info' => '/api/v0-beta/info',
                'volunteertypes' => '/api/v0-beta/volunteertypes',
                'locations' => '/api/v0-beta/locations',
                'shifttypes' => '/api/v0-beta/shifttypes',
                'shifts' => '/api/v0-beta/shifts',
                'news' => '/api/v0-beta/news',
                'self' => '/api/v0-beta/users/self',
            ],
        ]);
    }

    #[Route('/info', name: 'app_api_v0_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        return $this->json([
            'name' => $this->config->get(EventConfigStore::KEY_NAME),
            'welcome' => $this->config->get(EventConfigStore::KEY_WELCOME_MESSAGE),
            'accessMode' => $this->config->get(EventConfigStore::KEY_ACCESS_MODE, 'public'),
            'timeline' => [
                'buildupStart' => $this->isoOrNull(EventConfigStore::KEY_BUILDUP_START),
                'eventStart' => $this->isoOrNull(EventConfigStore::KEY_EVENT_START),
                'eventEnd' => $this->isoOrNull(EventConfigStore::KEY_EVENT_END),
                'teardownEnd' => $this->isoOrNull(EventConfigStore::KEY_TEARDOWN_END),
            ],
        ]);
    }

    #[Route('/volunteertypes', name: 'app_api_v0_volunteertypes', methods: ['GET'])]
    public function volunteerTypeList(): JsonResponse
    {
        $items = array_filter($this->volunteerTypes->findAllOrdered(), fn (VolunteerType $t) => !$t->isStaffOnly());

        return $this->json(['data' => array_map($this->volunteerType(...), array_values($items))]);
    }

    #[Route('/locations', name: 'app_api_v0_locations', methods: ['GET'])]
    public function locationList(): JsonResponse
    {
        $items = array_filter($this->locations->findAllOrdered(), fn (Location $l) => !$l->isStaffOnly());

        return $this->json(['data' => array_map($this->location(...), array_values($items))]);
    }

    #[Route('/locations/{id}/shifts', name: 'app_api_v0_location_shifts', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function locationShifts(#[MapEntity(mapping: ['id' => 'uuid'])] Location $location): JsonResponse
    {
        return $this->json(['data' => array_map($this->shift(...), $this->shifts->findBy(['location' => $location], ['startsAt' => 'ASC']))]);
    }

    #[Route('/shifttypes', name: 'app_api_v0_shifttypes', methods: ['GET'])]
    public function shiftTaskList(): JsonResponse
    {
        $items = array_filter($this->shiftTasks->findAllOrdered(), fn (ShiftTask $t) => !$t->isStaffOnly());

        return $this->json(['data' => array_map($this->shiftTask(...), array_values($items))]);
    }

    #[Route('/shifttypes/{id}/shifts', name: 'app_api_v0_shifttype_shifts', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function shiftTaskShifts(#[MapEntity(mapping: ['id' => 'uuid'])] ShiftTask $shiftTask): JsonResponse
    {
        return $this->json(['data' => array_map($this->shift(...), $this->shifts->findBy(['shiftTask' => $shiftTask], ['startsAt' => 'ASC']))]);
    }

    #[Route('/shifts', name: 'app_api_v0_shifts', methods: ['GET'])]
    public function shiftList(): JsonResponse
    {
        return $this->json(['data' => array_map($this->shift(...), $this->shifts->findUpcoming())]);
    }

    #[Route('/news', name: 'app_api_v0_news', methods: ['GET'])]
    public function newsList(): JsonResponse
    {
        return $this->json(['data' => array_map($this->newsItem(...), $this->news->findFeed(false))]);
    }

    #[Route('/users/self', name: 'app_api_v0_self', methods: ['GET'])]
    public function self(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'API key required.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'privileges' => $user->getPrivilegeNames(),
        ]);
    }

    private function isoOrNull(string $key): ?string
    {
        return $this->config->getDate($key)?->format(\DATE_ATOM);
    }

    /** @return array<string, mixed> */
    private function volunteerType(VolunteerType $t): array
    {
        return ['id' => $t->getId(), 'name' => $t->getName(), 'description' => $t->getDescription(), 'restricted' => $t->isRestricted()];
    }

    /** @return array<string, mixed> */
    private function location(Location $l): array
    {
        return ['id' => $l->getId(), 'name' => $l->getName(), 'description' => $l->getDescription(), 'dect' => $l->getDect()];
    }

    /** @return array<string, mixed> */
    private function shiftTask(ShiftTask $t): array
    {
        return ['id' => $t->getId(), 'name' => $t->getName(), 'description' => $t->getDescription()];
    }

    /** @return array<string, mixed> */
    private function shift(Shift $s): array
    {
        return [
            'id' => $s->getId(),
            'title' => $s->getTitle(),
            'start' => $s->getStartsAt()->format(\DATE_ATOM),
            'end' => $s->getEndsAt()->format(\DATE_ATOM),
            'durationHours' => $s->getDurationHours(),
            'shiftTask' => $s->getShiftTask()?->getName(),
            'location' => $s->getLocation()?->getName(),
        ];
    }

    /** @return array<string, mixed> */
    private function newsItem(News $n): array
    {
        return [
            'id' => $n->getId(),
            'title' => $n->getTitle(),
            'text' => $n->getFullText(),
            'pinned' => $n->isPinned(),
            'createdAt' => $n->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
