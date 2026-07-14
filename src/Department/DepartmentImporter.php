<?php

declare(strict_types=1);

namespace App\Department;

use App\Entity\Department;
use App\Repository\DepartmentRepository;
use App\Repository\ShiftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Upserts departments from the JSON bulk-upload format (same shape as the SSO
 * mapping import). Rows are matched to existing departments by name (or by an
 * explicit slug); the slug is auto-generated from the name when not provided,
 * and made unique against both the database and the rest of the batch.
 */
final class DepartmentImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly SluggerInterface $slugger,
    ) {
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
}
