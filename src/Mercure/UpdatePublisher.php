<?php

namespace App\Mercure;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Queues Mercure updates during a request and sends them once it is over.
 *
 * Publishing inline would race the database. A signal tells the browser to re-fetch, and if it is
 * sent while the transaction is still open the browser can read the old row and show nothing new -
 * intermittently, under load, which is the worst way for this to fail. Updates are therefore
 * collected here and flushed on kernel.terminate, after the response and after the commit.
 *
 * Every update is published private, so the hub delivers it only to subscribers whose token names
 * the topic. A public update would go to every connected client regardless of their token; nothing
 * in this application is public in that sense.
 */
final class UpdatePublisher
{
    /** @var list<Update> */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Signal that something on these topics changed, without saying what.
     *
     * The browser answers by re-requesting the relevant endpoint, where the ordinary controller
     * authorization runs. Nothing about the change travels over the hub, so a subscriber holding a
     * token that has not yet caught up with a revocation learns only that activity happened.
     *
     * One update is queued per topic, each naming only its own topic. The name is needed so a page
     * with several live regions refreshes the one that changed rather than all of them, but putting
     * the whole list in a single shared payload would leak: a fan-out to two users' topics would
     * tell each of them that the other exists and has activity. One update per topic costs a few
     * more publishes and says nothing extra.
     *
     * @param string|list<string>  $topics
     * @param array<string, mixed> $payload extra hints (e.g. which conversation) - never user data
     */
    public function signal(string|array $topics, array $payload = []): void
    {
        foreach ((array) $topics as $topic) {
            $this->queue($topic, json_encode(
                ['signal' => true, 'topic' => $topic] + $payload,
                \JSON_THROW_ON_ERROR,
            ));
        }
    }

    /**
     * Push a rendered Turbo Stream fragment.
     *
     * Only for fragments that are identical for every subscriber of the topic AND render without
     * consulting the security context. Publishing happens outside a request, so `is_granted` in such
     * a template evaluates against nothing rather than against the recipient, and a viewer-dependent
     * fragment would be built for the wrong person while looking perfectly correct in a test that
     * only checks the markup. When in doubt use {@see signal()}.
     *
     * @param string|list<string> $topics
     */
    public function stream(string|array $topics, string $turboStreamHtml): void
    {
        $this->queue($topics, $turboStreamHtml);
    }

    /** @param string|list<string> $topics */
    private function queue(string|array $topics, string $data): void
    {
        $topics = (array) $topics;
        if ($topics === []) {
            return;
        }

        $this->pending[] = new Update($topics, $data, private: true);
    }

    /**
     * Send everything queued. Called on kernel.terminate and by the messenger worker after a
     * consumed message, so nothing is published before its transaction has committed.
     *
     * A hub that is down must never take a request down with it: the page degrades to its polling
     * fallback, which is what that fallback is for.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $update) {
            try {
                $this->hub->publish($update);
            } catch (\Throwable $e) {
                $this->logger->error('Mercure publish failed.', ['exception' => $e]);
            }
        }
    }

    /** @return list<Update> for tests: what is queued but not yet sent */
    public function pending(): array
    {
        return $this->pending;
    }
}
