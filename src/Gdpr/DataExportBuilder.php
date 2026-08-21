<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Entity\GoodieDistribution;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\Worklog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Assembles the data a user is entitled to receive under the right to data
 * portability: their profile, consent opt-ins, volunteer types, shifts, hours,
 * the location/department names tied to those shifts, and goodies received.
 */
final class DataExportBuilder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'profile' => $this->profile($user),
            'consent' => $this->consent($user),
            'volunteer_types' => $this->volunteerTypes($user),
            'shifts' => $this->shifts($user),
            'hours' => $this->hours($user),
            'goodies' => $this->goodies($user),
        ];
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $personal = $user->getPersonalData();

        return [
            'username' => $user->getName(),
            'full_name' => trim(($personal?->getFirstName() ?? '').' '.($personal?->getLastName() ?? '')) ?: null,
            'email' => $user->getEmail(),
            'phone' => $user->getContact()?->getMobile(),
            'pronoun' => $personal?->getPronoun(),
            'account_source' => $user->getAccountSource(),
        ];
    }

    /** @return array<string, mixed> */
    private function consent(User $user): array
    {
        $consent = $user->getConsent();
        if ($consent === null) {
            return [];
        }

        return [
            'data_processing' => $consent->hasDataProcessing(),
            'full_name_visible' => $consent->isFullNameVisible(),
            'email_visible' => $consent->isEmailVisible(),
            'phone_visible' => $consent->isPhoneVisible(),
            'telegram_visible' => $consent->isTelegramVisible(),
            'consented_at' => $consent->getConsentedAt()?->format(\DATE_ATOM),
            'visibility_consented_at' => $consent->getVisibilityConsentedAt()?->format(\DATE_ATOM),
            'visibility_notice_version' => $consent->getVisibilityNoticeVersion(),
        ];
    }

    /** @return string[] */
    private function volunteerTypes(User $user): array
    {
        $rows = $this->em->getRepository(UserVolunteerType::class)->findBy(['user' => $user]);

        return array_map(static fn (UserVolunteerType $uvt) => $uvt->getVolunteerType()->getName(), $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function shifts(User $user): array
    {
        $entries = $this->em->getRepository(ShiftEntry::class)->findBy(['user' => $user]);

        return array_map(static function (ShiftEntry $entry): array {
            $shift = $entry->getShift();

            return [
                'title' => $shift->getTitle(),
                'starts_at' => $shift->getStartsAt()->format(\DATE_ATOM),
                'ends_at' => $shift->getEndsAt()->format(\DATE_ATOM),
                'location' => $shift->getLocation()?->getName(),
                'department' => $shift->getShiftTask()?->getDepartment()?->getName(),
            ];
        }, $entries);
    }

    /** @return array<string, mixed> */
    private function hours(User $user): array
    {
        $logs = $this->em->getRepository(Worklog::class)->findBy(['user' => $user]);
        $total = 0.0;
        $entries = [];
        foreach ($logs as $log) {
            /** @var Worklog $log */
            $total += $log->getHours();
            $entries[] = [
                'hours' => $log->getHours(),
                'comment' => $log->getComment(),
                'worked_at' => $log->getWorkedAt()->format(\DATE_ATOM),
            ];
        }

        return ['total' => $total, 'entries' => $entries];
    }

    /**
     * Revoked handovers are exported too, marked as such. They are still data held about the person,
     * and leaving them out would show a record the desk has corrected as if it never existed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function goodies(User $user): array
    {
        $rows = $this->em->getRepository(GoodieDistribution::class)->findBy(['user' => $user]);

        return array_map(static fn (GoodieDistribution $g): array => [
            'item' => $g->getItemName(),
            'quantity' => $g->getQuantity(),
            'received_at' => $g->getDistributedAt()->format(\DATE_ATOM),
            'revoked_at' => $g->getRevokedAt()?->format(\DATE_ATOM),
        ], $rows);
    }
}
