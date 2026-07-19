<?php

declare(strict_types=1);

namespace App\Sso;

use App\Entity\Department;
use App\Entity\SsoGroupMapping;
use App\Entity\VolunteerType;
use App\Repository\BadgeRepository;
use App\Repository\DepartmentRepository;
use App\Repository\GroupRepository;
use App\Repository\SsoGroupMappingRepository;
use App\Repository\VolunteerTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Upserts SSO group mappings from the JSON bulk-upload format. Each row is keyed
 * by the structured SSO group id; slugs are resolved to local entities and any
 * that cannot be resolved are reported as warnings (the row is still saved).
 *
 * A referenced `department` slug that does not exist yet is created on the fly
 * (named after the mapping, or the humanized slug) and linked, so the SSO import
 * can bootstrap the departments it maps to.
 */
final class SsoMappingImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SsoGroupMappingRepository $mappings,
        private readonly GroupRepository $groups,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly BadgeRepository $badges,
        private readonly DepartmentRepository $departments,
    ) {
    }

    /**
     * The current mappings as JSON-ready rows, or a single example row when there are none, so the
     * export doubles as a template. The shape round-trips through {@see import()}.
     *
     * @return array<int, array<string, mixed>>
     */
    public function export(): array
    {
        $mappings = $this->mappings->findAllOrdered();
        if ($mappings === []) {
            return [$this->exampleRow()];
        }

        return array_map(fn (SsoGroupMapping $m): array => $this->toRow($m), $mappings);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{imported: int, warnings: string[]}
     */
    public function import(array $rows): array
    {
        $imported = 0;
        $warnings = [];
        $vtIndex = $this->volunteerTypeIndex();

        // Departments created during this batch, keyed by slug, so several rows
        // pointing at the same new department reuse the one unflushed instance.
        $createdDepartments = [];

        foreach ($rows as $i => $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                $warnings[] = "Row $i: missing 'id'.";
                continue;
            }

            $mapping = $this->mappings->findOneBySsoGroupId($id) ?? new SsoGroupMapping($id);
            $mapping->setName((string) ($row['name'] ?? $id))
                ->setSlug((string) ($row['slug'] ?? ''))
                ->setStaffOnly((bool) ($row['staffonly'] ?? false));

            if (isset($row['department'])) {
                $slug = $this->slugify((string) $row['department']);
                if ($slug === '') {
                    $mapping->setDepartment(null);
                    $warnings[] = "Row $i: invalid department slug '{$row['department']}'.";
                } else {
                    $dept = $createdDepartments[$slug] ?? $this->departments->findOneBySlug($slug);
                    if ($dept === null) {
                        $name = isset($row['name']) && trim((string) $row['name']) !== ''
                            ? trim((string) $row['name'])
                            : $this->humanizeSlug($slug);
                        $dept = new Department($this->uniqueDepartmentName($name, $createdDepartments), $slug);
                        $this->em->persist($dept);
                        $createdDepartments[$slug] = $dept;
                        $warnings[] = "Row $i: created department '{$dept->getName()}' ({$slug}).";
                    }
                    $mapping->setDepartment($dept);
                }
            }

            $mapping->clearPermissionGroups();
            foreach ((array) ($row['permissiongroup'] ?? []) as $slug) {
                $group = $this->groups->findOneBySlug((string) $slug);
                $group !== null ? $mapping->addPermissionGroup($group) : $warnings[] = "Row $i: unknown permission group '$slug'.";
            }

            $mapping->clearVolunteerTypes();
            foreach ((array) ($row['volunteertype'] ?? []) as $slug) {
                $vt = $vtIndex[strtolower((string) $slug)] ?? null;
                $vt !== null ? $mapping->addVolunteerType($vt) : $warnings[] = "Row $i: unknown volunteer type '$slug'.";
            }

            $mapping->clearBadges();
            foreach ((array) ($row['badges'] ?? []) as $slug) {
                $badge = $this->badges->findOneBySlug((string) $slug);
                $badge !== null ? $mapping->addBadge($badge) : $warnings[] = "Row $i: unknown badge '$slug'.";
            }

            $this->em->persist($mapping);
            ++$imported;
        }

        $this->em->flush();

        return ['imported' => $imported, 'warnings' => $warnings];
    }

    /** @return array<string, mixed> */
    private function toRow(SsoGroupMapping $m): array
    {
        return [
            'id' => $m->getSsoGroupId(),
            'name' => $m->getName(),
            'slug' => $m->getSlug(),
            'staffonly' => $m->isStaffOnly(),
            'department' => $m->getDepartment()?->getSlug(),
            'permissiongroup' => array_map(static fn ($g): string => $g->getSlug(), $m->getPermissionGroups()->toArray()),
            'volunteertype' => array_map(static fn ($v): string => $v->getName(), $m->getVolunteerTypes()->toArray()),
            'badges' => array_map(static fn ($b): string => $b->getSlug(), $m->getBadges()->toArray()),
        ];
    }

    /** @return array<string, mixed> */
    private function exampleRow(): array
    {
        return [
            'id' => '0RV39Y2PM21J4N6L',
            'name' => 'Art Show',
            'slug' => 'art-show',
            'staffonly' => false,
            'department' => 'art-show',
            'permissiongroup' => ['info-desk'],
            'volunteertype' => ['Volunteer'],
            'badges' => ['security'],
        ];
    }

    /** @return array<string, VolunteerType> indexed by name and slugified name */
    private function volunteerTypeIndex(): array
    {
        $index = [];
        foreach ($this->volunteerTypes->findAll() as $vt) {
            /** @var VolunteerType $vt */
            $index[strtolower($vt->getName())] = $vt;
            $index[$this->slugify($vt->getName())] = $vt;
        }

        return $index;
    }

    private function slugify(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '', '-');
    }

    /** "art-show" => "Art Show" - a readable default department name. */
    private function humanizeSlug(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * A department name that is unique against both existing departments and the
     * ones created earlier in this batch (Department.name is unique).
     *
     * @param array<string, Department> $created
     */
    private function uniqueDepartmentName(string $base, array $created): string
    {
        $taken = array_map(static fn (Department $d): string => strtolower($d->getName()), $created);
        $name = $base;
        $n = 2;
        while (\in_array(strtolower($name), $taken, true) || $this->departments->findOneByName($name) !== null) {
            $name = $base.' '.$n++;
        }

        return $name;
    }
}
