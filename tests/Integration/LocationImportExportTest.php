<?php

namespace App\Tests\Integration;

use App\Entity\Location;
use App\Location\LocationImporter;
use App\Repository\LocationRepository;
use App\Tests\DatabaseTestCase;

/**
 * JSON export/import of locations: alias is the upsert key, name and alias are mandatory, and the
 * parent-by-alias links are resolved regardless of row order without breaching the nesting cap.
 */
final class LocationImportExportTest extends DatabaseTestCase
{
    private LocationImporter $importer;
    private LocationRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = static::getContainer()->get(LocationImporter::class);
        $this->repo = static::getContainer()->get(LocationRepository::class);
    }

    private function seed(string $name, string $alias): Location
    {
        $location = new Location($name);
        $location->setAlias($alias);
        $this->em->persist($location);
        $this->em->flush();

        return $location;
    }

    public function testExportOfAnEmptyDatabaseReturnsATemplateRow(): void
    {
        $rows = $this->importer->export();
        self::assertCount(1, $rows);
        self::assertArrayHasKey('name', $rows[0]);
        self::assertArrayHasKey('alias', $rows[0]);
    }

    public function testExportSerialisesFieldsAndParentAlias(): void
    {
        $parent = $this->seed('Venue', 'venue');
        $child = $this->seed('Hall', 'hall');
        $child->setParent($parent)->setPhone('123')->setStaffOnly(true);
        $this->em->flush();

        $byAlias = [];
        foreach ($this->importer->export() as $row) {
            $byAlias[$row['alias']] = $row;
        }

        self::assertSame('venue', $byAlias['hall']['parent']);
        self::assertSame('123', $byAlias['hall']['phone']);
        self::assertTrue($byAlias['hall']['staffOnly']);
        self::assertNull($byAlias['venue']['parent']);
    }

    public function testImportCreatesAndThenUpdatesByAlias(): void
    {
        $created = $this->importer->import([
            ['name' => 'Main Hall', 'alias' => 'main-hall', 'phone' => '100'],
        ]);
        self::assertSame(1, $created['imported']);
        self::assertSame('100', $this->repo->findOneByAlias('main-hall')->getPhone());

        // Same alias, different data → update in place, no second row.
        $updated = $this->importer->import([
            ['name' => 'Main Hall Renamed', 'alias' => 'main-hall', 'phone' => '200'],
        ]);
        self::assertSame(1, $updated['imported']);
        $this->em->clear();
        self::assertCount(1, $this->repo->findAll());
        $reloaded = $this->repo->findOneByAlias('main-hall');
        self::assertSame('Main Hall Renamed', $reloaded->getName());
        self::assertSame('200', $reloaded->getPhone());
    }

    public function testNameAndAliasAreMandatory(): void
    {
        $result = $this->importer->import([
            ['name' => 'No Alias'],
            ['alias' => 'no-name'],
            ['name' => 'Good', 'alias' => 'good'],
        ]);

        self::assertSame(1, $result['imported']);
        self::assertCount(2, $result['warnings']);
        self::assertNull($this->repo->findOneByAlias('no-name'));
        self::assertNotNull($this->repo->findOneByAlias('good'));
    }

    public function testParentByAliasResolvesRegardlessOfOrder(): void
    {
        // Child listed before its parent - the second pass still links them.
        $result = $this->importer->import([
            ['name' => 'Desk', 'alias' => 'desk', 'parent' => 'lobby'],
            ['name' => 'Lobby', 'alias' => 'lobby'],
        ]);

        self::assertSame(2, $result['imported']);
        $this->em->clear();
        self::assertSame('lobby', $this->repo->findOneByAlias('desk')->getParent()?->getAlias());
    }

    public function testUnknownParentIsWarnedAndLeftAsRoot(): void
    {
        $result = $this->importer->import([
            ['name' => 'Orphan', 'alias' => 'orphan', 'parent' => 'nowhere'],
        ]);

        self::assertSame(1, $result['imported']);
        self::assertNotEmpty($result['warnings']);
        self::assertNull($this->repo->findOneByAlias('orphan')->getParent());
    }

    public function testNestingDeeperThanTwoLevelsIsRejected(): void
    {
        $result = $this->importer->import([
            ['name' => 'A', 'alias' => 'a'],
            ['name' => 'B', 'alias' => 'b', 'parent' => 'a'],
            ['name' => 'C', 'alias' => 'c', 'parent' => 'b'],
            ['name' => 'D', 'alias' => 'd', 'parent' => 'c'],
        ]);

        $this->em->clear();
        self::assertNotEmpty($result['warnings']);
        // C (grandchild, depth 2) is allowed; D (depth 3) is dropped to a root.
        self::assertSame('b', $this->repo->findOneByAlias('c')->getParent()?->getAlias());
        self::assertNull($this->repo->findOneByAlias('d')->getParent());
    }

    public function testANameAlreadyUsedByAnotherLocationIsSkipped(): void
    {
        $this->seed('Existing', 'existing');

        $result = $this->importer->import([
            ['name' => 'Existing', 'alias' => 'different-alias'],
        ]);

        self::assertSame(0, $result['imported']);
        self::assertNotEmpty($result['warnings']);
        self::assertNull($this->repo->findOneByAlias('different-alias'));
    }
}
