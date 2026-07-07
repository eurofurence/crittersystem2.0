<?php

namespace App\Controller\Api;

use App\Entity\Shift;
use App\Entity\User;
use App\Repository\NewsRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Syndication feeds, served under the API-key firewall so external
 * readers authenticate with ?key=. Each is gated by its seeded privilege.
 */
#[Route('/api')]
final class FeedController extends AbstractController
{
    #[IsGranted('export:atom')]
    #[Route('/atom', name: 'app_feed_atom', methods: ['GET'])]
    public function atom(NewsRepository $news): Response
    {
        $entries = '';
        foreach ($news->findFeed(false) as $n) {
            $entries .= \sprintf(
                "  <entry>\n    <title>%s</title>\n    <id>urn:news:%d</id>\n    <updated>%s</updated>\n    <content type=\"text\">%s</content>\n  </entry>\n",
                self::xml($n->getTitle()),
                $n->getId(),
                $n->getUpdatedAt()->format(\DATE_ATOM),
                self::xml($n->getFullText()),
            );
        }

        $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<feed xmlns=\"http://www.w3.org/2005/Atom\">\n"
            ."  <title>News</title>\n  <id>urn:critter:news</id>\n"
            .'  <updated>'.(new \DateTimeImmutable())->format(\DATE_ATOM)."</updated>\n"
            .$entries
            ."</feed>\n";

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
    }

    #[IsGranted('export:atom')]
    #[Route('/rss', name: 'app_feed_rss', methods: ['GET'])]
    public function rss(NewsRepository $news): Response
    {
        $items = '';
        foreach ($news->findFeed(false) as $n) {
            $items .= \sprintf(
                "    <item>\n      <title>%s</title>\n      <guid isPermaLink=\"false\">news-%d</guid>\n      <pubDate>%s</pubDate>\n      <description>%s</description>\n    </item>\n",
                self::xml($n->getTitle()),
                $n->getId(),
                $n->getCreatedAt()->format(\DATE_RSS),
                self::xml($n->getFullText()),
            );
        }

        $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<rss version=\"2.0\"><channel>\n    <title>News</title>\n    <description>Latest announcements</description>\n"
            .$items
            ."</channel></rss>\n";

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    #[IsGranted('export:ical')]
    #[Route('/ical', name: 'app_feed_ical', methods: ['GET'])]
    public function ical(ShiftEntryRepository $entries): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Emit absolute UTC instants (the trailing "Z"), never floating local
        // times. A floating time is interpreted in each device's own timezone,
        // so a volunteer whose phone is set to a different timezone than the
        // venue would be reminded at the wrong moment. UTC instants are
        // converted by every calendar client to the device's local time and
        // always fire at the correct absolute moment — which is what we want
        // for international attendees travelling to the event.
        $utc = new \DateTimeZone('UTC');
        $stamp = (new \DateTimeImmutable('now', $utc))->format('Ymd\THis\Z');

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Critter//Shifts//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:My Shifts'];
        foreach ($entries->findByUserOrdered($user) as $entry) {
            $shift = $entry->getShift();
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:shift-entry-'.$entry->getId().'@critter';
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = 'DTSTART:'.$shift->getStartsAt()->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$shift->getEndsAt()->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.self::escapeIcal($shift->getTitle().' ('.$entry->getVolunteerType()->getName().')');
            if ($shift->getLocation() !== null) {
                $lines[] = 'LOCATION:'.self::escapeIcal($shift->getLocation()->getName());
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return new Response(implode("\r\n", $lines)."\r\n", Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="my-shifts.ics"',
        ]);
    }

    #[IsGranted('export:shifts')]
    #[Route('/shifts-json-export', name: 'app_feed_shifts_json', methods: ['GET'])]
    public function shiftsJson(ShiftRepository $shifts): JsonResponse
    {
        $data = array_map(static fn (Shift $s): array => [
            'id' => $s->getId(),
            'title' => $s->getTitle(),
            'start' => $s->getStartsAt()->format(\DATE_ATOM),
            'end' => $s->getEndsAt()->format(\DATE_ATOM),
            'shiftType' => $s->getShiftType()?->getName(),
            'location' => $s->getLocation()?->getName(),
        ], $shifts->findUpcoming());

        return $this->json(['data' => $data]);
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    private static function escapeIcal(string $value): string
    {
        return addcslashes(str_replace(["\r\n", "\n"], '\\n', $value), ",;\\");
    }
}
