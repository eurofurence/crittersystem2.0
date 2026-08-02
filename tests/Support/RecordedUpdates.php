<?php

namespace App\Tests\Support;

use Symfony\Component\Mercure\Update;

/**
 * Captures what the application would have published, in place of a real hub.
 *
 * No hub runs during the suite, and without this the publisher would attempt a real HTTP call for
 * every update. Its own error handling swallows the failure - deliberately, so a hub outage never
 * takes a request down - which means the only visible effect would be a DNS timeout per test and no
 * way to assert that anything was published at all.
 *
 * The store is static so a test can read it without reaching into the container, while the class is
 * still an ordinary invokable service that MockHub can be handed as its publisher. Tests must call
 * {@see clear()} in setUp: the recording outlives any one kernel.
 */
final class RecordedUpdates
{
    /** @var list<Update> */
    private static array $updates = [];

    public function __invoke(Update $update): string
    {
        return self::record($update);
    }

    public static function record(Update $update): string
    {
        self::$updates[] = $update;

        return 'test-'.\count(self::$updates);
    }

    public static function clear(): void
    {
        self::$updates = [];
    }

    /** @return list<Update> */
    public static function all(): array
    {
        return self::$updates;
    }

    /**
     * Updates delivered to a given topic.
     *
     * @return list<Update>
     */
    public static function forTopic(string $topic): array
    {
        return array_values(array_filter(
            self::$updates,
            static fn (Update $update) => \in_array($topic, $update->getTopics(), true),
        ));
    }
}
