<?php

declare(strict_types=1);

namespace App\Department;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\VolunteerType;
use App\Repository\DepartmentRepository;
use App\Repository\LocationRepository;
use App\Repository\ShiftRepository;
use App\Repository\VolunteerTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * JSON export/import of departments. Rows are matched to existing departments by name (or by an
 * explicit slug); the slug is auto-generated from the name when not provided, and made unique
 * against both the database and the rest of the batch.
 *
 * Location and volunteer-type links round-trip: they are referenced by the location's `alias` and
 * the volunteer type's `name` respectively, and are only rewritten when the row carries the key, so
 * a partial row leaves the existing links alone. An unresolved reference is dropped with a warning.
 */
final class DepartmentImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly SluggerInterface $slugger,
        private readonly LocationRepository $locations,
        private readonly VolunteerTypeRepository $volunteerTypes,
    ) {
    }

    /**
     * The current departments as JSON-ready rows, or a single example row when there are none, so
     * the export doubles as a template.
     *
     * @return array<int, array<string, mixed>>
     */
    public function export(): array
    {
        $departments = $this->departments->findAllOrdered();
        if ($departments === []) {
            return [$this->exampleRow()];
        }

        return array_map(fn (Department $d): array => $this->toRow($d), $departments);
    }

    /**
     * @param array<int, mixed> $rows
     *
     * @return array{imported: int, created: int, updated: int, warnings: string[]}
     */
    public function import(array $rows): array
    {
        $imported = 0;
        $created = 0;
        $updated = 0;
        $warnings = [];

        // Slugs assigned during this batch, so two unflushed new rows cannot collide.
        $batchSlugs = [];
        $vtIndex = $this->volunteerTypeIndex();

        foreach ($rows as $i => $row) {
            if (!\is_array($row)) {
                $warnings[] = "Row $i: expected an object.";
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $warnings[] = "Row $i: missing 'name'.";
                continue;
            }

            $providedSlug = isset($row['slug']) ? $this->slugify((string) $row['slug']) : '';

            $dept = $this->departments->findOneByName($name);
            if ($dept === null && $providedSlug !== '') {
                $dept = $this->departments->findOneBySlug($providedSlug);
            }
            $isNew = $dept === null;

            if ($isNew) {
                $slug = $providedSlug !== '' ? $providedSlug : $this->slugify($name);
                if (!$this->validSlug($slug)) {
                    $warnings[] = "Row $i: could not derive a valid slug for '$name'.";
                    continue;
                }
                $slug = $this->uniqueSlug($slug, $batchSlugs, null);
                $dept = new Department($name, $slug);
                $batchSlugs[$slug] = true;
                ++$created;
            } else {
                $dept->setName($name);
                if ($providedSlug !== '' && $providedSlug !== $dept->getSlug()) {
                    if ($this->validSlug($providedSlug)) {
                        $slug = $this->uniqueSlug($providedSlug, $batchSlugs, $dept);
                        $dept->setSlug($slug);
                        $batchSlugs[$slug] = true;
                    } else {
                        $warnings[] = "Row $i: invalid slug '$providedSlug' ignored.";
                    }
                }
                ++$updated;
            }

            if (\array_key_exists('description', $row)) {
                $dept->setDescription($row['description'] !== null ? (string) $row['description'] : null);
            }

            $dept->setStaffOnly((bool) ($row['staffonly'] ?? $row['staff_only'] ?? $dept->isStaffOnly()));

            if (\array_key_exists('organizational', $row)) {
                $wanted = (bool) $row['organizational'];
                // The organizational flag can only change while the
                // department owns no shifts.
                if ($wanted !== $dept->isOrganizational() && !$isNew && $this->shifts->countForDepartment($dept) > 0) {
                    $warnings[] = "Row $i: cannot change organizational flag for '$name' — it already has shifts.";
                } else {
                    $dept->setOrganizational($wanted);
                }
            }

            if (\array_key_exists('locations', $row)) {
                $this->applyLocations($dept, (array) $row['locations'], $i, $warnings);
            }
            if (\array_key_exists('volunteerTypes', $row)) {
                $this->applyVolunteerTypes($dept, (array) $row['volunteerTypes'], $vtIndex, $i, $warnings);
            }

            $this->em->persist($dept);
            ++$imported;
        }

        $this->em->flush();

        return ['imported' => $imported, 'created' => $created, 'updated' => $updated, 'warnings' => $warnings];
    }

    /** @param array<string, true> $batchSlugs */
    private function uniqueSlug(string $base, array $batchSlugs, ?Department $ignore): string
    {
        $slug = $base;
        $n = 2;
        while ($this->slugTaken($slug, $batchSlugs, $ignore)) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** @param array<string, true> $batchSlugs */
    private function slugTaken(string $slug, array $batchSlugs, ?Department $ignore): bool
    {
        if (isset($batchSlugs[$slug])) {
            return true;
        }
        $existing = $this->departments->findOneBySlug($slug);

        return $existing !== null && $existing !== $ignore;
    }

    private function slugify(string $value): string
    {
        return strtolower($this->slugger->slug($value)->toString());
    }

    private function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    /**
     * Replace the department's locations with those named by alias.
     *
     * @param array<int, mixed> $aliases
     * @param string[]          $warnings
     */
    private function applyLocations(Department $dept, array $aliases, int $i, array &$warnings): void
    {
        foreach ($dept->getLocations()->toArray() as $existing) {
            $dept->removeLocation($existing);
        }
        foreach ($aliases as $alias) {
            $location = $this->locations->findOneByAlias((string) $alias);
            if ($location === null) {
                $warnings[] = "Row $i: unknown location alias '$alias'.";
                continue;
            }
            $dept->addLocation($location);
        }
    }

    /**
     * Replace the department's volunteer types with those named.
     *
     * @param array<int, mixed>              $names
     * @param array<string, VolunteerType>   $vtIndex
     * @param string[]                       $warnings
     */
    private function applyVolunteerTypes(Department $dept, array $names, array $vtIndex, int $i, array &$warnings): void
    {
        foreach ($dept->getVolunteerTypes()->toArray() as $existing) {
            $dept->removeVolunteerType($existing);
        }
        foreach ($names as $name) {
            $type = $vtIndex[strtolower((string) $name)] ?? null;
            if ($type === null) {
                $warnings[] = "Row $i: unknown volunteer type '$name'.";
                continue;
            }
            $dept->addVolunteerType($type);
        }
    }

    /** @return array<string, VolunteerType> indexed by lower-cased name */
    private function volunteerTypeIndex(): array
    {
        $index = [];
        foreach ($this->volunteerTypes->findAll() as $type) {
            /** @var VolunteerType $type */
            $index[strtolower($type->getName())] = $type;
        }

        return $index;
    }

    /** @return array<string, mixed> */
    private function toRow(Department $d): array
    {
        return [
            'name' => $d->getName(),
            'slug' => $d->getSlug(),
            'description' => $d->getDescription(),
            'staffonly' => $d->isStaffOnly(),
            'organizational' => $d->isOrganizational(),
            'locations' => array_map(static fn (Location $l): string => $l->getAlias(), $d->getLocations()->toArray()),
            'volunteerTypes' => array_map(static fn (VolunteerType $v): string => $v->getName(), $d->getVolunteerTypes()->toArray()),
        ];
    }

    /** @return array<string, mixed> */
    private function exampleRow(): array
    {
        return [
            'name' => 'Logistics',
            'slug' => 'logistics',
            'description' => 'Move and store convention needs.',
            'staffonly' => false,
            'organizational' => false,
            'locations' => [],
            'volunteerTypes' => [],
        ];
    }
}
