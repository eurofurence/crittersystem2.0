<?php

namespace App\Twig;

use App\Entity\User;
use App\Mercure\Topics;
use App\Security\OnboardingGate;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the current user's own topics to templates, so a live region names its topic without the
 * template building the string.
 *
 * Only topics belonging to the signed-in user are reachable here. A template cannot ask for another
 * user's, and naming a topic in markup grants nothing in any case - the subscriber token decides
 * what the hub will deliver (see {@see \App\Mercure\TopicBuilder}). This exists so the vocabulary
 * stays in one place, not as a security boundary.
 */
final class MercureTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly OnboardingGate $onboarding,
        private readonly string $mercurePublicUrl,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mercure_topic', $this->topic(...)),
            new TwigFunction('mercure_topic_conversation', Topics::conversation(...)),
            new TwigFunction('mercure_topic_department', Topics::departmentShifts(...)),
            new TwigFunction('mercure_enabled', $this->enabled(...)),
            new TwigFunction('live_regions_enabled', $this->liveRegionsEnabled(...)),
        ];
    }

    /**
     * Whether to render the live regions for the current user at all.
     *
     * Deliberately not "has completed onboarding". Administrators are exempt from the onboarding
     * gate, so that test hid the navbar bell and the status widget from every administrator, and
     * with the hub URL gated the same way it stopped their browser connecting at all - taking chat,
     * the planner and every other live surface down with it, silently. The only users who should not
     * get live regions are the ones the gate really does redirect, because the fragments those
     * regions fetch are refused for them.
     *
     * Independent of whether a hub is configured: with none, the regions fall back to polling.
     */
    public function liveRegionsEnabled(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && !$this->onboarding->blocks($user);
    }

    /**
     * Whether this deployment has a hub at all.
     *
     * With none configured the page must not advertise one. A live region that opens an EventSource
     * against a URL that cannot answer writes a SEVERE console entry on every retry - noise the user
     * cannot act on, and which buries real errors. Without the hub URL the regions fall back to
     * polling immediately instead.
     */
    public function enabled(): bool
    {
        return trim($this->mercurePublicUrl) !== '';
    }

    public function topic(string $name): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return '';
        }

        return match ($name) {
            'user_notifications' => Topics::userNotifications($user),
            'user_status' => Topics::userStatus($user),
            'user_calls' => Topics::userCalls($user),
            // Not user-specific, but only reachable by a claim holder: the token decides, not this.
            'info_desk_queue' => Topics::infoDeskQueue(),
            default => throw new \InvalidArgumentException(\sprintf('Unknown Mercure topic "%s".', $name)),
        };
    }
}
