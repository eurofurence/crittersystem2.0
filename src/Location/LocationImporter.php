<?php

declare(strict_types=1);

namespace App\Location;

use App\Entity\Location;
use App\Repository\LocationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * JSON export/import of locations. A row is identified by its `alias`: an existing location with the
 * same alias is updated in place, otherwise a new one is created. Name and alias are both mandatory.
 *
 * Parents are referenced by the parent's alias and resolved in a second pass, so a child may appear
 * before its parent in the file; a link that would exceed the two-level nesting cap or form a cycle
 * is dropped with a warning rather than persisted invalid.
 */
final class LocationImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationRepository $locations,
    ) {
    }

    /**
     * Every location as JSON-ready rows, ordered by name. When there are none yet, a single example
     * row is returned so the download always doubles as a fill-in template.
     *
     * @return array<int, array<string, mixed>>
     */
    public function export(): array
    {
        $locations = $this->locations->findAllOrdered();
        if ($locations === []) {
            return [$this->exampleRow()];
        }

        return array_map(fn (Location $l): array => $this->toRow($l), $locations);
    }

    /**
     * @param array<int, mixed> $rows
     *
     * @return array{imported: int, warnings: string[]}
     */
    public function import(array $rows): array
    {
        $imported = 0;
        $warnings = [];

        /** @var array<string, Location> $byAlias every location touched this batch, keyed by alias */
        $byAlias = [];
        /** @var array<string, string> $usedNames lower(name) => alias, to catch within-batch name clashes */
        $usedNames = [];
        /** @var array<string, ?string> $parents alias => parent alias, resolved in pass two */
        $parents = [];

        foreach ($rows as $i => $row) {
            if (!\is_array($row)) {
                $warnings[] = "Row $i: expected an object.";
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $alias = trim((string) ($row['alias'] ?? ''));
            if ($name === '' || $alias === '') {
                $warnings[] = "Row $i: 'name' and 'alias' are both required.";
                continue;
            }
            if (mb_strlen($name) > 128 || mb_strlen($alias) > 64) {
                $warnings[] = "Row $i: name (max 128) or alias (max 64) is too long.";
                continue;
            }
            if (isset($byAlias[$alias])) {
                $warnings[] = "Row $i: alias '$alias' appears more than once; only the first is applied.";
                continue;
            }

            // Name is unique. Refuse a name already worn by a different location (in the DB or earlier
            // in this batch), which would otherwise abort the whole flush.
            $clash = $this->locations->findOneByName($name);
            if (($clash !== null && $clash->getAlias() !== $alias)
                || (($usedNames[mb_strtolower($name)] ?? $alias) !== $alias)) {
                $warnings[] = "Row $i: the name '$name' is already used by another location; skipped.";
                continue;
            }

            $location = $this->locations->findOneByAlias($alias);
            if ($location === null) {
                $location = new Location($name);
                $location->setAlias($alias);
                $this->em->persist($location);
            }

            $location->setName($name)
                ->setDescription($this->nullableString($row['description'] ?? null))
                ->setMapUrl($this->nullableString($row['mapUrl'] ?? null))
                ->setEmbedHtml($this->nullableString($row['embedHtml'] ?? null))
                ->setPhone($this->nullableString($row['phone'] ?? null))
                ->setStaffOnly((bool) ($row['staffOnly'] ?? false));

            $byAlias[$alias] = $location;
            $usedNames[mb_strtolower($name)] = $alias;
            if (\array_key_exists('parent', $row)) {
                $parents[$alias] = $row['parent'] === null ? null : trim((string) $row['parent']);
            }
            ++$imported;
        }

        $this->linkParents($byAlias, $parents, $warnings);
        $this->em->flush();

        return ['imported' => $imported, 'warnings' => $warnings];
    }

    /**
     * @param array<string, Location> $byAlias
     * @param array<string, ?string>  $parents
     * @param string[]                $warnings
     */
    private function linkParents(array $byAlias, array $parents, array &$warnings): void
    {
        foreach ($parents as $alias => $parentAlias) {
            $location = $byAlias[$alias];
            if ($parentAlias === null || $parentAlias === '') {
                $location->setParent(null);
                continue;
            }
            $parent = $byAlias[$parentAlias] ?? $this->locations->findOneByAlias($parentAlias);
            if ($parent === null) {
                $warnings[] = "Location '$alias': unknown parent alias '$parentAlias'; left as a root.";
                continue;
            }
            $location->setParent($parent);
        }

        // Validate the assembled tree only once every parent is in place, so depth is measured against
        // the final shape rather than whatever order the rows happened to be in.
        foreach ($byAlias as $alias => $location) {
            if (!$this->parentChainOk($location)) {
                $location->setParent(null);
                $warnings[] = "Location '$alias': its parent would exceed two levels of nesting or form a cycle; left as a root.";
            }
        }
    }

    /** True when walking up from the location stays within the two-level cap and never loops. */
    private function parentChainOk(Location $location): bool
    {
        $depth = 0;
        $seen = [];
        for ($node = $location->getParent(); $node !== null; $node = $node->getParent()) {
            if ($node === $location || \in_array($node, $seen, true)) {
                return false;
            }
            $seen[] = $node;
            if (++$depth > 2) {
                return false;
            }
        }

        return true;
    }

    private function toRow(Location $l): array
    {
        return [
            'name' => $l->getName(),
            'alias' => $l->getAlias(),
            'description' => $l->getDescription(),
            'mapUrl' => $l->getMapUrl(),
            'embedHtml' => $l->getEmbedHtml(),
            'phone' => $l->getPhone(),
            'staffOnly' => $l->isStaffOnly(),
            'parent' => $l->getParent()?->getAlias(),
        ];
    }

    private function exampleRow(): array
    {
        return [
            'name' => 'Main Hall',
            'alias' => 'main-hall',
            'description' => 'Primary event space',
            'mapUrl' => null,
            'embedHtml' => null,
            'phone' => '100',
            'staffOnly' => false,
            'parent' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
