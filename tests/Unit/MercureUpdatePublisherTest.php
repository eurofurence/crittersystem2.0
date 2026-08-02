<?php

namespace App\Tests\Unit;

use App\Mercure\UpdatePublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * How updates reach the hub, and when.
 *
 * Two properties matter here. Every update must be private, because a public one is delivered to
 * every connected client whatever their token says. And nothing may be sent before the request is
 * over: a signal that overtakes its own transaction tells the browser to re-read a row that is not
 * committed yet, so the change appears not to have happened - intermittently, under load.
 */
final class MercureUpdatePublisherTest extends TestCase
{
    /** @var list<Update> */
    private array $published = [];

    private function publisher(?\Closure $onPublish = null): UpdatePublisher
    {
        $this->published = [];

        $hub = new class($onPublish ?? function (Update $u): void { $this->published[] = $u; }) implements HubInterface {
            public function __construct(private readonly \Closure $onPublish)
            {
            }

            public function getPublicUrl(): string
            {
                return 'https://example.test/.well-known/mercure';
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return null;
            }

            public function publish(Update $update): string
            {
                ($this->onPublish)($update);

                return 'id';
            }
        };

        return new UpdatePublisher($hub, new NullLogger());
    }

    public function testNothingIsSentUntilFlush(): void
    {
        $publisher = $this->publisher();
        $publisher->signal('urn:critter:user:abc:notifications');

        self::assertSame([], $this->published, 'an update must not overtake its own transaction');
        self::assertCount(1, $publisher->pending());

        $publisher->flush();

        self::assertCount(1, $this->published);
    }

    public function testEverySignalIsPrivate(): void
    {
        $publisher = $this->publisher();
        $publisher->signal('urn:critter:user:abc:notifications');
        $publisher->stream('urn:critter:system:announcements', '<turbo-stream></turbo-stream>');
        $publisher->flush();

        foreach ($this->published as $update) {
            self::assertTrue($update->isPrivate(), 'a public update reaches every connected client');
        }
    }

    /** A signal says that something changed, and which topic changed. Never what changed. */
    public function testSignalPayloadCarriesNoDataBeyondItsOwnTopic(): void
    {
        $publisher = $this->publisher();
        $publisher->signal('urn:critter:conversation:abc');
        $publisher->flush();

        self::assertSame(
            '{"signal":true,"topic":"urn:critter:conversation:abc"}',
            $this->published[0]->getData(),
        );
    }

    /**
     * A fan-out must not tell one recipient about another.
     *
     * Publishing one update to several topics would put the whole list in a payload that every
     * recipient can read, so signalling two users would disclose each to the other. One update per
     * topic, each naming only itself.
     */
    public function testFanOutDoesNotDiscloseSiblingTopics(): void
    {
        $publisher = $this->publisher();
        $publisher->signal(['urn:critter:user:alice:calls', 'urn:critter:user:bob:calls']);
        $publisher->flush();

        self::assertCount(2, $this->published);
        foreach ($this->published as $update) {
            self::assertCount(1, $update->getTopics());
            self::assertStringNotContainsString(
                $update->getTopics()[0] === 'urn:critter:user:alice:calls' ? 'bob' : 'alice',
                $update->getData(),
            );
        }
    }

    public function testFlushIsNotRepeated(): void
    {
        $publisher = $this->publisher();
        $publisher->signal('urn:critter:user:abc:notifications');
        $publisher->flush();
        $publisher->flush();

        self::assertCount(1, $this->published);
    }

    /** A hub that is down must not take the request down with it; the page falls back to polling. */
    public function testAFailingHubDoesNotThrow(): void
    {
        $publisher = $this->publisher(static function (): void {
            throw new \RuntimeException('hub unreachable');
        });

        $publisher->signal('urn:critter:user:abc:notifications');
        $publisher->flush();

        self::assertSame([], $publisher->pending());
    }

    public function testEmptyTopicListPublishesNothing(): void
    {
        $publisher = $this->publisher();
        $publisher->signal([]);
        $publisher->flush();

        self::assertSame([], $this->published);
    }
}
