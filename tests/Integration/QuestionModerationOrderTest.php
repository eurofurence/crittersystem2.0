<?php

namespace App\Tests\Integration;

use App\Entity\Question;
use App\Entity\User;
use App\Repository\QuestionRepository;
use App\Tests\DatabaseTestCase;

/**
 * The moderation queue puts what still needs answering at the top.
 *
 * Ordering on `answeredAt` ascending does the opposite: PostgreSQL sorts nulls last for an
 * ascending order, so the unanswered questions the screen exists to work through sat underneath
 * every question already dealt with.
 */
final class QuestionModerationOrderTest extends DatabaseTestCase
{
    private function repository(): QuestionRepository
    {
        return static::getContainer()->get(QuestionRepository::class);
    }

    private function asker(): User
    {
        $user = new User();
        $user->setName('asker-'.bin2hex(random_bytes(3)))
            ->setEmail('asker-'.bin2hex(random_bytes(3)).'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * `createdAt` is assigned on persist only when it is still unset, so writing it first is what
     * makes the ordering deterministic rather than dependent on insertion speed.
     */
    private function question(User $asker, string $text, string $createdAt, bool $answered): Question
    {
        $question = new Question($asker, $text);
        (new \ReflectionProperty(Question::class, 'createdAt'))->setValue($question, new \DateTimeImmutable($createdAt));
        if ($answered) {
            $question->setAnsweredAt(new \DateTimeImmutable($createdAt));
        }
        $this->em->persist($question);
        $this->em->flush();

        return $question;
    }

    public function testUnansweredComeFirstAndNewestLeadsEachGroup(): void
    {
        $asker = $this->asker();
        $this->question($asker, 'answered and recent', '-30 minutes', true);
        $this->question($asker, 'older unanswered', '-2 hours', false);
        $this->question($asker, 'newer unanswered', '-1 hour', false);

        $texts = array_map(
            static fn (Question $q): string => $q->getText(),
            $this->repository()->findForModeration(),
        );

        self::assertSame(
            ['newer unanswered', 'older unanswered', 'answered and recent'],
            $texts,
        );
    }

    /** With nothing answered the queue is simply newest first. */
    public function testAllUnansweredAreNewestFirst(): void
    {
        $asker = $this->asker();
        $this->question($asker, 'oldest', '-3 hours', false);
        $this->question($asker, 'newest', '-1 hour', false);
        $this->question($asker, 'middle', '-2 hours', false);

        $texts = array_map(
            static fn (Question $q): string => $q->getText(),
            $this->repository()->findForModeration(),
        );

        self::assertSame(['newest', 'middle', 'oldest'], $texts);
    }
}
