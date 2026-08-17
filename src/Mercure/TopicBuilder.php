<?php

namespace App\Mercure;

use App\Entity\Department;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\UserGroupAssignmentRepository;
use App\Security\PrivilegeScopeResolver;
use App\Service\Chat\ConversationService;

/**
 * The exact set of topics one user may subscribe to.
 *
 * This is the whole authorization boundary of the live transport. Whatever this returns is signed
 * into the subscriber token, and the hub will deliver those topics and nothing else. The browser
 * cannot influence it: a page that asks to listen on a topic outside this list simply receives
 * nothing.
 *
 * Three rules hold everything together:
 *
 *  - Department scope comes from {@see PrivilegeScopeResolver}, which is also what
 *    {@see \App\Security\PrivilegeVoter} votes with. Asking `is_granted('shift:manage')` here
 *    instead would be catastrophic: without a subject that voter grants unconditionally, so every
 *    manager would be handed every department's topics.
 *  - Nothing is templated, with exactly one deliberate exception: a grant that is event-wide
 *    (an administrator, or a privilege held through an unscoped assignment) yields
 *    {@see Topics::allDepartmentShifts()} rather than one entry per department. That authorizes the
 *    same set, but enumerating it is unbounded.
 *  - No branch here may add topics in proportion to the size of the event. The token is carried in
 *    a cookie the browser drops over roughly 4 KB, and it is a response header nginx answers 502
 *    for over its buffer, so an unbounded list does not degrade - it takes the page down. Both
 *    exceptions above exist for that reason, as does {@see Topics::allStaffShifts()}: a right that
 *    is genuinely event-wide gets one topic, never a list. A per-user set that cannot grow with the
 *    event (their own departments, their own conversations) may still be enumerated.
 */
final class TopicBuilder
{
    /**
     * Scoped privileges that make department shift activity relevant to a user. Holding any of them
     * in a department means that department's planner, grid, staffing and apply rows are visible, so
     * their change signals are too.
     */
    private const DEPARTMENT_PRIVILEGES = ['shift:manage', 'shift:assign', 'assignment:manage', 'department:manage', 'board:view'];

    public function __construct(
        private readonly PrivilegeScopeResolver $scopes,
        private readonly UserGroupAssignmentRepository $assignments,
        private readonly ConversationRepository $conversations,
        private readonly ConversationService $chat,
    ) {
    }

    /**
     * Conversation topics are filtered through the predicate the controller enforces rather than
     * taken from the participant rows alone, so the token can never be wider than the predicate if
     * either side changes. Today a participant row always implies permission, so this rejects
     * nothing. Being narrower is safe and already the case: an Info Desk member who has never
     * opened a support thread has no row for it and reaches it through the queue topic instead.
     *
     * The Info Desk queue goes to chat:claim holders, because watching any support thread is the
     * same right that lets a responder claim one.
     *
     * Staff get the one all-staff topic, because other departments' all-staff shifts appear on
     * their apply screen and one topic per department running such a shift is unbounded.
     *
     * @return list<string> topic strings, de-duplicated and ordered for a stable token
     */
    public function forUser(User $user): array
    {
        $topics = [
            Topics::announcements(),
            Topics::userNotifications($user),
            Topics::userStatus($user),
            Topics::userCalls($user),
        ];

        foreach ($this->conversations->findForParticipant($user) as $conversation) {
            if ($this->chat->mayParticipate($conversation, $user)) {
                $topics[] = Topics::conversation($conversation);
            }
        }

        if ($this->scopes->holds($user, 'chat:claim')) {
            $topics[] = Topics::infoDeskQueue();
        }

        $departments = $this->departmentsFor($user);
        if ($departments === null) {
            $topics[] = Topics::allDepartmentShifts();
        } else {
            foreach ($departments as $department) {
                $topics[] = Topics::departmentShifts($department);
            }
        }

        if ($user->isStaff()) {
            $topics[] = Topics::allStaffShifts();
        }

        $topics = array_values(array_unique($topics));
        sort($topics);

        return $topics;
    }

    /**
     * Departments whose shift activity this user may see: the ones they belong to, plus any where a
     * shift privilege reaches.
     *
     * @return Department[]|null null for "every department", which the caller expresses as one
     *                           templated selector rather than as a list
     */
    private function departmentsFor(User $user): ?array
    {
        $scoped = [];

        foreach (self::DEPARTMENT_PRIVILEGES as $privilege) {
            $held = $this->scopes->departmentsFor($user, $privilege);
            if ($held === null) {
                return null;
            }
            foreach ($held as $department) {
                $scoped[(string) $department->getUuid()] = $department;
            }
        }

        foreach ($this->assignments->findActiveDepartmentsForUser($user) as $department) {
            $scoped[(string) $department->getUuid()] = $department;
        }

        return array_values($scoped);
    }
}
