<?php

namespace App\Command;

use App\Entity\Certification;
use App\Entity\Department;
use App\Entity\Faq;
use App\Entity\Group;
use App\Entity\Location;
use App\Entity\NeededVolunteerType;
use App\Entity\News;
use App\Entity\PersonalData;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftTask;
use App\Entity\State;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Entity\UserGroupAssignment;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Entity\Worklog;
use App\Enum\ShiftAudience;
use App\Enum\ShiftEntryState;
use App\Enum\ShiftState;
use App\Service\EventConfigStore;
use App\Service\Install\Installer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Populate a database with a fictional event for demonstrations, training material and
 * screenshots.
 *
 * Every person, department and location here is invented. That is the point: screenshots taken
 * from a database seeded with real volunteers carry names, e-mail addresses and badge numbers into
 * whatever slide deck or manual they end up in. Run this against a throwaway database instead.
 *
 * Every account shares one password, so the command refuses to run in the prod environment and
 * refuses to touch a database that already holds users it did not create.
 */
#[AsCommand(
    name: 'app:demo:seed',
    description: 'Fill this database with a fictional event for demos and screenshots (never for production).',
)]
final class DemoSeedCommand extends Command
{
    private const DEFAULT_PASSWORD = 'demo1234';

    /** name => [slug, staffOnly, description] */
    private const DEPARTMENTS = [
        'Info Desk' => ['demo-info-desk', false, 'The first place attendees go when they are lost, confused, or looking for someone. Runs the counter in the foyer and answers the phone.'],
        'Security' => ['demo-security', false, 'Door watch, bag check and crowd care. Keeps the halls safe and the fire exits clear.'],
        'Stage & Tech' => ['demo-stage-tech', false, 'Sound, light and stage changeovers for the main hall programme.'],
        'Registration' => ['demo-registration', false, 'Badge printing, check-in and the pre-registration queue.'],
        'Artist Alley' => ['demo-artist-alley', false, 'Booth setup, artist support and the daily alley opening.'],
        'Volunteer Care' => ['demo-volunteer-care', true, 'Looks after the people who look after the event: the lounge, the snacks and the sleep-deprived.'],
    ];

    /**
     * Locations, in creation order so a parent always exists before its children.
     *
     * name => [alias, parent name or null, staffOnly]
     */
    private const LOCATIONS = [
        'Congress Centre' => ['demo-cc', null, false],
        'Main Hall' => ['demo-main-hall', 'Congress Centre', false],
        'Stage Left' => ['demo-stage-left', 'Main Hall', true],
        'Stage Right' => ['demo-stage-right', 'Main Hall', true],
        'Foyer' => ['demo-foyer', 'Congress Centre', false],
        'Info Counter' => ['demo-info-counter', 'Foyer', false],
        'Registration Desk' => ['demo-reg-desk', 'Foyer', false],
        'North Entrance' => ['demo-north-entrance', 'Foyer', false],
        'Hall 2' => ['demo-hall-2', 'Congress Centre', false],
        'Hotel Nordwind' => ['demo-hotel', null, false],
        'Volunteer Lounge' => ['demo-lounge', 'Hotel Nordwind', true],
    ];

    /** name => [department name, self-signup allowed] */
    private const VOLUNTEER_TYPES = [
        'Info Desk Crew' => ['Info Desk', true],
        'Security Crew' => ['Security', true],
        'Stage Crew' => ['Stage & Tech', true],
        'Registration Crew' => ['Registration', true],
        'Alley Support' => ['Artist Alley', true],
        'Volunteer Care Crew' => ['Volunteer Care', false],
    ];

    /** department name => task names */
    private const TASKS = [
        'Info Desk' => ['Counter Duty', 'Phone Duty', 'Lost & Found'],
        'Security' => ['Door Watch', 'Bag Check', 'Hall Patrol'],
        'Stage & Tech' => ['Stage Setup', 'Live Operation', 'Stage Teardown'],
        'Registration' => ['Badge Printing', 'Queue Marshalling', 'Late Registration'],
        'Artist Alley' => ['Alley Opening', 'Booth Support', 'Alley Closing'],
        'Volunteer Care' => ['Lounge Duty', 'Snack Run'],
    ];

    /**
     * The four accounts a walkthrough is built around, one per audience.
     *
     * username => [first name, last name, pronoun, group slug, department name or null]
     */
    private const ARCHETYPES = [
        'admin' => ['Alex', 'Adminson', 'they/them', 'global-admin', null],
        'morgan' => ['Morgan', 'Vale', 'she/her', 'department-manager', 'Info Desk'],
        'sparky' => ['Sparky', 'Emberfall', 'he/him', 'shift-manager', 'Stage & Tech'],
        'rowan' => ['Rowan', 'Fielding', 'they/them', 'volunteer', null],
    ];

    /** [username, first name, last name, pronoun] */
    private const VOLUNTEERS = [
        ['mikko', 'Mikko', 'Laine', 'he/him'],
        ['juniper', 'Juniper', 'Ashgrove', 'she/her'],
        ['tallow', 'Tallow', 'Brightwick', 'they/them'],
        ['pepper', 'Pepper', 'Solheim', 'she/her'],
        ['brisk', 'Brisk', 'Nordvig', 'he/him'],
        ['clover', 'Clover', 'Marsh', 'she/her'],
        ['dusty', 'Dusty', 'Renwick', 'he/him'],
        ['echo', 'Echo', 'Sandoval', 'they/them'],
        ['fennel', 'Fennel', 'Okonkwo', 'she/her'],
        ['gale', 'Gale', 'Thornbury', 'they/them'],
        ['hazel', 'Hazel', 'Kirbo', 'she/her'],
        ['indigo', 'Indigo', 'Ferrante', 'they/them'],
        ['juno', 'Juno', 'Halvard', 'she/her'],
        ['kestrel', 'Kestrel', 'Amaya', 'they/them'],
        ['linden', 'Linden', 'Petrov', 'he/him'],
        ['maple', 'Maple', 'Osei', 'she/her'],
        ['nimbus', 'Nimbus', 'Castellan', 'they/them'],
        ['onyx', 'Onyx', 'Bergstrom', 'he/him'],
        ['plum', 'Plum', 'Aldridge', 'she/her'],
        ['quill', 'Quill', 'Mbeki', 'they/them'],
        ['ridge', 'Ridge', 'Tanaka', 'he/him'],
        ['sable', 'Sable', 'Novak', 'she/her'],
        ['tamsin', 'Tamsin', 'Delacroix', 'she/her'],
        ['umber', 'Umber', 'Fitzgerald', 'they/them'],
        ['vesper', 'Vesper', 'Rasmussen', 'she/her'],
        ['willow', 'Willow', 'Achterberg', 'they/them'],
        ['xander', 'Xander', 'Quintero', 'he/him'],
        ['yarrow', 'Yarrow', 'Lindqvist', 'they/them'],
        ['zephyr', 'Zephyr', 'Okafor', 'he/him'],
        ['auburn', 'Auburn', 'Sciarra', 'she/her'],
        ['birch', 'Birch', 'Halloran', 'he/him'],
        ['cinder', 'Cinder', 'Vasquez', 'they/them'],
        ['drift', 'Drift', 'Yamamoto', 'he/him'],
        ['ember', 'Ember', 'Kowalski', 'she/her'],
        ['flint', 'Flint', 'Adeyemi', 'he/him'],
        ['garnet', 'Garnet', 'Bellweather', 'she/her'],
        ['heath', 'Heath', 'Sorensen', 'he/him'],
        ['ivory', 'Ivory', 'Nakamura', 'she/her'],
        ['jasper', 'Jasper', 'Okonjo', 'he/him'],
        ['larkspur', 'Larkspur', 'Ivanova', 'she/her'],
    ];

    /** @var array<string, Department> */
    private array $departments = [];
    /** @var array<string, Location> */
    private array $locations = [];
    /** @var array<string, VolunteerType> */
    private array $types = [];
    /** @var array<string, ShiftTask> */
    private array $tasks = [];
    /** @var array<string, User> */
    private array $users = [];

    private \DateTimeImmutable $day1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Installer $installer,
        private readonly EventConfigStore $config,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password shared by every demo account', self::DEFAULT_PASSWORD);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->environment === 'prod') {
            $io->error('Every account this creates shares one password. It refuses to run in the prod environment.');

            return Command::FAILURE;
        }

        // Names here are unique, so a second run collides rather than refreshing anything. Recreate
        // the database instead: bin/demo-instance does that in one step.
        if ($this->em->getRepository(User::class)->count([]) > 0) {
            $io->error([
                'This database already holds accounts, and seeding on top of them would collide.',
                'Recreate it first: bin/demo-instance --reset',
            ]);

            return Command::FAILURE;
        }

        $password = (string) $input->getOption('password');

        $io->title('Seeding a fictional event');

        $this->installer->seedPrivilegesAndGroups();
        $this->installer->seedDomainDefaults();
        $io->writeln('Core privileges and groups are in place.');

        $this->seedEventConfig();
        $this->seedOrganisation();
        $io->writeln(\sprintf('%d departments, %d locations, %d volunteer types.', \count($this->departments), \count($this->locations), \count($this->types)));

        $this->seedUsers($password);
        $io->writeln(\sprintf('%d accounts.', \count($this->users)));

        $shifts = $this->seedShifts();
        $io->writeln(\sprintf('%d shifts across three event days.', $shifts));

        $this->seedContent();
        $this->em->flush();

        $io->success('The demo event is ready.');
        $io->table(
            ['Username', 'Role', 'Password'],
            [
                ['admin', 'Global admin', $password],
                ['morgan', 'Department manager (Info Desk)', $password],
                ['sparky', 'Shift manager (Stage & Tech)', $password],
                ['rowan', 'Volunteer', $password],
            ],
        );

        return Command::SUCCESS;
    }

    /**
     * The event runs from tomorrow so that every seeded shift is in the future and the
     * volunteer-facing pages have something to show. Build-up starts yesterday, which puts the
     * app into its "event is running" state.
     */
    private function seedEventConfig(): void
    {
        $this->day1 = (new \DateTimeImmutable('tomorrow'))->setTime(0, 0);

        $this->config->set(EventConfigStore::KEY_NAME, 'Nordwind Convention 2026');
        $this->config->set(EventConfigStore::KEY_WELCOME_MESSAGE, 'Welcome to the Nordwind Convention volunteer system. Thank you for helping us run the show.');
        $this->config->set(EventConfigStore::KEY_BUILDUP_START, $this->day1->modify('-2 days')->format('Y-m-d'));
        $this->config->set(EventConfigStore::KEY_EVENT_START, $this->day1->format('Y-m-d'));
        $this->config->set(EventConfigStore::KEY_EVENT_END, $this->day1->modify('+2 days')->format('Y-m-d'));
        $this->config->set(EventConfigStore::KEY_TEARDOWN_END, $this->day1->modify('+3 days')->format('Y-m-d'));

        // A demo instance has no identity provider behind it, so the password form has to stay on
        // the login page even where SSO is configured.
        $this->config->set(EventConfigStore::KEY_PASSWORD_LOGIN_ENABLED, true);
    }

    private function seedOrganisation(): void
    {
        foreach (self::DEPARTMENTS as $name => [$slug, $staffOnly, $description]) {
            $department = (new Department($name, $slug))
                ->setStaffOnly($staffOnly)
                ->setDescription($description);
            $this->em->persist($department);
            $this->departments[$name] = $department;
        }

        foreach (self::LOCATIONS as $name => [$alias, $parent, $staffOnly]) {
            $location = (new Location($name))
                ->setAlias($alias)
                ->setStaffOnly($staffOnly);
            if ($parent !== null) {
                $location->setParent($this->locations[$parent]);
            }
            $this->em->persist($location);
            $this->locations[$name] = $location;
        }

        foreach (self::VOLUNTEER_TYPES as $name => [$departmentName, $selfSignup]) {
            $type = (new VolunteerType($name))
                ->setShiftSelfSignup($selfSignup)
                ->setShowOnDashboard(true)
                ->setDescription(\sprintf('Volunteers trained to work with %s.', $departmentName));
            $department = $this->departments[$departmentName];
            $department->addVolunteerType($type);
            $this->em->persist($type);
            $this->types[$name] = $type;
        }

        foreach (self::TASKS as $departmentName => $names) {
            foreach ($names as $name) {
                $task = (new ShiftTask($name))->setDepartment($this->departments[$departmentName]);
                $this->em->persist($task);
                $this->tasks[$departmentName.': '.$name] = $task;
            }
        }

        $this->linkDepartmentLocations();
        $this->em->flush();
    }

    private function linkDepartmentLocations(): void
    {
        $map = [
            'Info Desk' => ['Info Counter', 'Foyer'],
            'Security' => ['North Entrance', 'Foyer', 'Main Hall'],
            'Stage & Tech' => ['Main Hall', 'Stage Left', 'Stage Right'],
            'Registration' => ['Registration Desk', 'Foyer'],
            'Artist Alley' => ['Hall 2'],
            'Volunteer Care' => ['Volunteer Lounge'],
        ];

        foreach ($map as $departmentName => $locationNames) {
            foreach ($locationNames as $locationName) {
                $this->departments[$departmentName]->addLocation($this->locations[$locationName]);
            }
        }
    }

    private function seedUsers(string $password): void
    {
        $groups = [];
        foreach ($this->em->getRepository(Group::class)->findAll() as $group) {
            $groups[$group->getSlug()] = $group;
        }
        $volunteerGroup = $groups['volunteer'];

        foreach (self::ARCHETYPES as $username => [$first, $last, $pronoun, $groupSlug, $departmentName]) {
            $user = $this->makeUser($username, $first, $last, $pronoun, $password);
            $department = $departmentName !== null ? $this->departments[$departmentName] : null;
            $this->em->persist(new UserGroupAssignment($user, $groups[$groupSlug], $department));
            if ($groupSlug !== 'volunteer') {
                $this->em->persist(new UserGroupAssignment($user, $volunteerGroup));
            }
        }

        foreach (self::VOLUNTEERS as [$username, $first, $last, $pronoun]) {
            $user = $this->makeUser($username, $first, $last, $pronoun, $password);
            $this->em->persist(new UserGroupAssignment($user, $volunteerGroup));
        }

        $this->em->flush();
        $this->confirmVolunteerTypeMemberships();
        $this->em->flush();
    }

    private function makeUser(string $username, string $first, string $last, string $pronoun, string $password): User
    {
        $index = \count($this->users);

        $user = new User();
        $user->setName($username)
            ->setEmail($username.'@demo.invalid')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->completeOnboarding();

        $personal = (new PersonalData($user))
            ->setFirstName($first)
            ->setLastName($last)
            ->setPronoun($pronoun)
            ->setBadgeNumber(4200 + $index)
            ->setShirtSize(['S', 'M', 'L', 'XL'][$index % 4]);
        $user->setPersonalData($personal);

        $state = (new State($user))
            ->setArrived($index % 3 !== 2)
            ->setActive(true);
        $user->setState($state);

        $this->em->persist($user);
        $this->em->persist($personal);
        $this->em->persist($state);
        $this->users[$username] = $user;

        return $user;
    }

    /**
     * Spread volunteers over the crews. A membership only lets someone sign up once it is
     * confirmed, so most are confirmed here and a handful are left pending to give the
     * management screens a queue to show.
     */
    private function confirmVolunteerTypeMemberships(): void
    {
        $typeNames = array_keys($this->types);
        $admin = $this->users['admin'];
        $index = 0;

        foreach ($this->users as $username => $user) {
            $memberships = match ($username) {
                'admin' => $typeNames,
                'morgan' => ['Info Desk Crew', 'Volunteer Care Crew'],
                'sparky' => ['Stage Crew'],
                'rowan' => ['Info Desk Crew', 'Registration Crew'],
                default => [
                    $typeNames[$index % \count($typeNames)],
                    $typeNames[($index + 3) % \count($typeNames)],
                ],
            };

            foreach (array_unique($memberships) as $typeName) {
                $membership = new UserVolunteerType($user, $this->types[$typeName]);
                if ($index % 11 !== 7) {
                    $membership->setConfirmedBy($admin);
                }
                $this->em->persist($membership);
            }
            ++$index;
        }
    }

    /**
     * Three event days of shifts. The mix is deliberate: most shifts are published and partly
     * staffed, a few are full, a few are empty and two are still drafts, so that the browse,
     * planner and publish screens each have something characteristic to show.
     *
     * @return int the number of shifts created
     */
    private function seedShifts(): int
    {
        $plans = $this->shiftPlans();
        $volunteers = array_values(array_filter(
            $this->users,
            static fn (User $u, string $name): bool => !\in_array($name, ['admin'], true),
            \ARRAY_FILTER_USE_BOTH,
        ));

        $created = 0;
        $cursor = 0;

        foreach ($plans as [$day, $departmentName, $taskName, $locationName, $typeName, $startHour, $hours, $needed, $fill, $state, $audience]) {
            $start = $this->day1->modify(\sprintf('+%d days', $day))->setTime($startHour, 0);

            $shift = (new Shift())
                ->setTitle($taskName)
                ->setDescription($this->shiftDescription($taskName))
                ->setStartsAt($start)
                ->setEndsAt($start->modify(\sprintf('+%d hours', $hours)))
                ->setDepartment($this->departments[$departmentName])
                ->setLocation($this->locations[$locationName])
                ->setShiftTask($this->tasks[$departmentName.': '.$taskName])
                ->setState($state)
                ->setAudience($audience);
            $shift->addNeededVolunteerType(new NeededVolunteerType($this->types[$typeName], $needed));
            $this->em->persist($shift);
            ++$created;

            for ($i = 0; $i < $fill; ++$i) {
                $user = $volunteers[$cursor % \count($volunteers)];
                ++$cursor;
                $entry = new ShiftEntry($shift, $this->types[$typeName], $user);
                $entry->setState($i === 0 ? ShiftEntryState::ASSIGNMENT : ShiftEntryState::APPLICATION);
                $this->em->persist($entry);
            }
        }

        $this->em->flush();

        return $created;
    }

    /**
     * @return list<array{int, string, string, string, string, int, int, int, int, ShiftState, ShiftAudience}>
     */
    private function shiftPlans(): array
    {
        $published = ShiftState::PUBLISHED;
        $draft = ShiftState::DRAFT;
        $public = ShiftAudience::PUBLIC_VOLUNTEER;
        $deptStaff = ShiftAudience::DEPARTMENT_STAFF;

        $plans = [];

        // [department, task, location, volunteer type] repeated across each day at fixed hours.
        $rota = [
            ['Info Desk', 'Counter Duty', 'Info Counter', 'Info Desk Crew', [9, 13, 17], 4],
            ['Info Desk', 'Phone Duty', 'Info Counter', 'Info Desk Crew', [10, 16], 3],
            ['Security', 'Door Watch', 'North Entrance', 'Security Crew', [8, 14, 20], 4],
            ['Security', 'Bag Check', 'Foyer', 'Security Crew', [9, 15], 3],
            ['Security', 'Hall Patrol', 'Main Hall', 'Security Crew', [12, 18], 3],
            ['Stage & Tech', 'Stage Setup', 'Main Hall', 'Stage Crew', [8], 3],
            ['Stage & Tech', 'Live Operation', 'Main Hall', 'Stage Crew', [11, 15, 19], 4],
            ['Stage & Tech', 'Stage Teardown', 'Main Hall', 'Stage Crew', [22], 2],
            ['Registration', 'Badge Printing', 'Registration Desk', 'Registration Crew', [8, 12], 4],
            ['Registration', 'Queue Marshalling', 'Foyer', 'Registration Crew', [9, 13], 3],
            ['Artist Alley', 'Alley Opening', 'Hall 2', 'Alley Support', [10], 2],
            ['Artist Alley', 'Booth Support', 'Hall 2', 'Alley Support', [12, 16], 4],
            ['Artist Alley', 'Alley Closing', 'Hall 2', 'Alley Support', [19], 2],
            ['Volunteer Care', 'Lounge Duty', 'Volunteer Lounge', 'Volunteer Care Crew', [10, 18], 6],
        ];

        $tick = 0;
        for ($day = 0; $day < 3; ++$day) {
            foreach ($rota as [$department, $task, $location, $type, $hours, $duration]) {
                foreach ($hours as $hour) {
                    $needed = 2 + ($tick % 3);
                    $fill = match ($tick % 5) {
                        0 => 0,
                        1, 2 => max(1, $needed - 1),
                        3 => $needed,
                        default => 1,
                    };
                    $audience = $department === 'Volunteer Care' ? $deptStaff : $public;
                    $plans[] = [$day, $department, $task, $location, $type, $hour, $duration, $needed, $fill, $published, $audience];
                    ++$tick;
                }
            }
        }

        // Two unpublished shifts, so the deck can show what a draft looks like before it is
        // released to volunteers.
        $plans[] = [2, 'Stage & Tech', 'Live Operation', 'Main Hall', 'Stage Crew', 21, 3, 4, 0, $draft, $public];
        $plans[] = [2, 'Info Desk', 'Lost & Found', 'Info Counter', 'Info Desk Crew', 14, 4, 2, 0, $draft, $public];

        return $plans;
    }

    private function shiftDescription(string $task): string
    {
        return match ($task) {
            'Counter Duty' => 'Staff the info counter: answer questions, hand out schedules and point people to the right hall. Briefing starts ten minutes before the shift.',
            'Phone Duty' => 'Take calls on the event line and pass anything urgent to the duty manager.',
            'Lost & Found' => 'Log, store and return lost property. Two people so the desk is never left alone.',
            'Door Watch' => 'Check badges at the north entrance and keep the doorway clear. High-visibility vest provided.',
            'Bag Check' => 'Bag inspection at the foyer entrance. You will be paired with an experienced crew member.',
            'Hall Patrol' => 'Walk the main hall, watch the fire exits and report anything that needs attention.',
            'Stage Setup' => 'Rig the stage for the day: microphones, monitors and the lighting check. Some lifting involved.',
            'Live Operation' => 'Run sound and light during the programme. Prior desk experience helps but is not required.',
            'Stage Teardown' => 'Strike the stage after the last act. Expect a late finish.',
            'Badge Printing' => 'Print and hand out badges at the registration desk. Training given at the start of the shift.',
            'Queue Marshalling' => 'Keep the registration queue moving and answer questions while people wait.',
            'Late Registration' => 'Evening registration for late arrivals.',
            'Alley Opening' => 'Help artists into the alley and get the tables ready before doors.',
            'Booth Support' => 'Cover booths for breaks and help artists who need a hand.',
            'Alley Closing' => 'Close the alley down for the night and check every table is secure.',
            'Lounge Duty' => 'Keep the volunteer lounge stocked, tidy and welcoming.',
            'Snack Run' => 'Fetch and distribute supplies for the crews on duty.',
            default => 'See the department briefing for details.',
        };
    }

    private function seedContent(): void
    {
        $admin = $this->users['admin'];

        $news = [
            ['Volunteer briefing times', 'Every department holds a short briefing ten minutes before each shift starts. Please arrive early enough to attend it. If you are new, say so at the briefing and someone will pair you with an experienced crew member.', true, false],
            ['The volunteer lounge is open', 'The lounge in the Hotel Nordwind is open around the clock for anyone on the volunteer roster. Drinks, snacks and a quiet corner to sit down in.', false, true],
            ['Shifts for day three are now open', 'The last day of the schedule has been published. Teardown shifts count double towards your hours, so if you can stay late, we would love the help.', false, false],
        ];

        foreach ($news as $i => [$title, $text, $pinned, $highlighted]) {
            $item = (new News())
                ->setTitle($title)
                ->setText($text)
                ->setAuthor($admin)
                ->setIsPinned($pinned)
                ->setIsHighlighted($highlighted);
            $this->em->persist($item);
            unset($i);
        }

        $faq = [
            ['Getting started', 'How many hours am I expected to work?', 'There is no hard minimum, but most volunteers sign up for somewhere between twelve and twenty hours across the event. Take what you can comfortably manage.'],
            ['Getting started', 'What if I have to cancel a shift?', 'Cancel it in the system as early as you can so someone else can take it. If the shift starts within a few hours, tell your department lead directly as well.'],
            ['Getting started', 'Do I need any experience?', 'No. Every shift has a briefing, and new volunteers are paired with someone experienced.'],
            ['At the event', 'Where do I check in?', 'At the info counter in the foyer. Bring your badge; you will be marked as arrived and can then sign up for shifts.'],
            ['At the event', 'What do I do if nobody turns up to relieve me?', 'Call the duty manager on the event line rather than leaving your post. The info desk can always reach someone.'],
        ];

        foreach ($faq as $order => [$category, $question, $answer]) {
            $entry = (new Faq($question, $answer))
                ->setCategory($category)
                ->setDisplayOrder($order);
            $this->em->persist($entry);
        }

        $this->seedCertifications($admin);
        $this->seedWorklogs($admin);
    }

    /**
     * Qualifications, with holders spread across the states the approval queue distinguishes, so
     * the management screens show a working queue rather than an empty one.
     */
    private function seedCertifications(User $admin): void
    {
        $now = new \DateTimeImmutable();

        $definitions = [
            ['First Aid', 'Basic first aid, refreshed every two years. Required for lead positions on any public-facing shift.', 730, false],
            ['Radio Operation', 'How to use the event radio net: channels, call signs and when to stay off the air.', 365, true],
            ['Crowd Safety', 'Managing queues, blocked exits and the difference between a busy room and a dangerous one.', 730, false],
            ['Stage Rigging', 'Working at height and handling stage equipment. Practical assessment required.', 1095, false],
        ];

        $holders = array_values(array_filter(
            $this->users,
            static fn (User $u, string $name): bool => $name !== 'admin',
            \ARRAY_FILTER_USE_BOTH,
        ));

        $index = 0;
        foreach ($definitions as [$title, $description, $validityDays, $selfConfirm]) {
            $certification = (new Certification($title))
                ->setDescription($description)
                ->setValidityPeriodDays($validityDays)
                ->setAllowSelfConfirmation($selfConfirm)
                ->setIsActive(true);
            $this->em->persist($certification);

            for ($i = 0; $i < 6; ++$i) {
                $holder = $holders[($index * 7 + $i) % \count($holders)];
                $record = new UserCertification($holder, $certification);

                if ($i < 4) {
                    $record->setStatus(UserCertification::STATUS_APPROVED)
                        ->setCertifiedBy($admin)
                        ->setDateCertified($now->modify('-'.(30 + $i * 40).' days'))
                        ->setDateExpires($now->modify('+'.($validityDays - 30 - $i * 40).' days'))
                        ->setDecidedAt($now->modify('-'.(30 + $i * 40).' days'));
                } else {
                    $record->setStatus(UserCertification::STATUS_PENDING);
                }

                $this->em->persist($record);
            }
            ++$index;
        }
    }

    /** Hours worked outside the shift system, which is what the worklog screen is for. */
    private function seedWorklogs(User $admin): void
    {
        $now = new \DateTimeImmutable();
        $entries = [
            ['mikko', 6.5, 'Built the info desk signage the week before the event.'],
            ['juniper', 4.0, 'Packed and labelled the volunteer welcome bags.'],
            ['tallow', 8.0, 'Drove the equipment van from the store to the venue.'],
            ['pepper', 3.5, 'Set up the volunteer lounge.'],
            ['brisk', 5.0, 'Radio check and channel assignment across all departments.'],
            ['clover', 2.5, 'Printed and laminated the department schedules.'],
        ];

        foreach ($entries as $offset => [$username, $hours, $comment]) {
            if (!isset($this->users[$username])) {
                continue;
            }
            $log = (new Worklog($this->users[$username]))
                ->setCreator($admin)
                ->setHours($hours)
                ->setComment($comment)
                ->setWorkedAt($now->modify('-'.($offset + 2).' days'));
            $this->em->persist($log);
        }
    }
}
