<?php

namespace App\Tests\Integration;

use App\Department\DepartmentImporter;
use App\Entity\Department;
use App\Entity\Location;
use App\Entity\VolunteerType;
use App\Tests\DatabaseTestCase;

final class DepartmentImportTest extends DatabaseTestCase
{
    private function importer(): DepartmentImporter
    {
        /** @var DepartmentImporter $importer */
        $importer = static::getContainer()->get(DepartmentImporter::class);

        return $importer;
    }

    public function testCreatesDepartmentsAndAutoGeneratesSlug(): void
    {
        $result = $this->importer()->import([
            ['name' => 'Art Show', 'staffonly' => 1],
            ['name' => 'Logistics', 'slug' => 'Custom Logistics Slug', 'description' => 'Move things.', 'organizational' => 1],
        ]);

        self::assertSame(2, $result['imported']);
        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['updated']);
        self::assertEmpty($result['warnings']);

        $this->em->clear();
        $repo = $this->em->getRepository(Department::class);

        $art = $repo->findOneBy(['name' => 'Art Show']);
        self::assertNotNull($art);
        self::assertSame('art-show', $art->getSlug(), 'slug is generated from the name');
        self::assertTrue($art->isStaffOnly());

        $log = $repo->findOneBy(['name' => 'Logistics']);
        self::assertNotNull($log);
        self::assertSame('custom-logistics-slug', $log->getSlug(), 'provided slug is normalized');
        self::assertTrue($log->isOrganizational());
        self::assertSame('Move things.', $log->getDescription());
    }

    public function testSlugCollisionsAreMadeUnique(): void
    {
        $result = $this->importer()->import([
            ['name' => 'Security'],
            ['name' => 'Security ✦'],
        ]);

        self::assertSame(2, $result['created']);
        $this->em->clear();
        $slugs = array_map(
            static fn (Department $d): string => $d->getSlug(),
            $this->em->getRepository(Department::class)->findAll(),
        );
        self::assertContains('security', $slugs);
        self::assertContains('security-2', $slugs, 'colliding slug is suffixed');
    }

    public function testExistingDepartmentIsUpdatedNotDuplicated(): void
    {
        $this->em->persist(new Department('Registration', 'registration'));
        $this->em->flush();

        $result = $this->importer()->import([
            ['name' => 'Registration', 'description' => 'Badge pickup.', 'staffonly' => 1],
        ]);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['updated']);

        $this->em->clear();
        $repo = $this->em->getRepository(Department::class);
        self::assertCount(1, $repo->findAll(), 'no duplicate created');
        $dept = $repo->findOneBy(['name' => 'Registration']);
        self::assertSame('Badge pickup.', $dept->getDescription());
        self::assertTrue($dept->isStaffOnly());
        self::assertSame('registration', $dept->getSlug());
    }

    public function testRowWithoutNameIsReportedAndSkipped(): void
    {
        $result = $this->importer()->import([
            ['slug' => 'no-name'],
            ['name' => 'Valid'],
        ]);

        self::assertSame(1, $result['imported']);
        self::assertSame(1, $result['created']);
        self::assertNotEmpty($result['warnings']);
    }

    public function testExportOfAnEmptyDatabaseReturnsATemplateRow(): void
    {
        $rows = $this->importer()->export();

        self::assertCount(1, $rows);
        self::assertArrayHasKey('name', $rows[0]);
        self::assertArrayHasKey('slug', $rows[0]);
        self::assertArrayHasKey('locations', $rows[0]);
        self::assertArrayHasKey('volunteerTypes', $rows[0]);
    }

    public function testExportSerialisesFieldsAndRelations(): void
    {
        $location = new Location('Main Hall');
        $location->setAlias('main-hall');
        $type = new VolunteerType('Volunteer');
        $dept = (new Department('Logistics', 'logistics'))->setDescription('Move things.')->setStaffOnly(true);
        $dept->addLocation($location);
        $dept->addVolunteerType($type);
        $this->em->persist($location);
        $this->em->persist($type);
        $this->em->persist($dept);
        $this->em->flush();

        $rows = $this->importer()->export();

        self::assertCount(1, $rows);
        self::assertSame('logistics', $rows[0]['slug']);
        self::assertSame('Move things.', $rows[0]['description']);
        self::assertTrue($rows[0]['staffonly']);
        self::assertSame(['main-hall'], $rows[0]['locations']);
        self::assertSame(['Volunteer'], $rows[0]['volunteerTypes']);
    }

    public function testImportLinksLocationsByAliasAndVolunteerTypesByName(): void
    {
        $location = new Location('Main Hall');
        $location->setAlias('main-hall');
        $this->em->persist($location);
        $this->em->persist(new VolunteerType('Volunteer'));
        $this->em->flush();

        $result = $this->importer()->import([
            ['name' => 'Logistics', 'locations' => ['main-hall'], 'volunteerTypes' => ['Volunteer']],
        ]);

        self::assertEmpty($result['warnings']);
        $this->em->clear();
        $dept = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Logistics']);
        self::assertCount(1, $dept->getLocations());
        self::assertSame('main-hall', $dept->getLocations()->first()->getAlias());
        self::assertCount(1, $dept->getVolunteerTypes());
    }

    public function testUnknownRelationReferenceIsWarnedAndLeftUnlinked(): void
    {
        $result = $this->importer()->import([
            ['name' => 'Logistics', 'locations' => ['ghost'], 'volunteerTypes' => ['Nobody']],
        ]);

        self::assertSame(1, $result['imported']);
        self::assertCount(2, $result['warnings']);
        $this->em->clear();
        $dept = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Logistics']);
        self::assertCount(0, $dept->getLocations());
        self::assertCount(0, $dept->getVolunteerTypes());
    }

    /**
     * A global type is offered to every department already, so an import cannot claim it: doing so
     * would restrict a type the whole event staffs with to whichever department listed it.
     */
    public function testAGlobalTypeCannotBeClaimedByAnImport(): void
    {
        $global = (new VolunteerType('Staff'))->setGlobal(true)->setStaffOnly(true)->setHideOnShiftView(true);
        $ordinary = new VolunteerType('Rigging');
        $this->em->persist($global);
        $this->em->persist($ordinary);
        $this->em->flush();

        $result = $this->importer()->import([
            ['name' => 'Logistics', 'volunteerTypes' => ['Staff', 'Rigging']],
        ]);

        self::assertSame(1, $result['imported']);
        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('global', $result['warnings'][0]);

        $this->em->clear();
        $dept = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Logistics']);
        self::assertSame(['Rigging'], array_map(
            static fn (VolunteerType $t): string => $t->getName(),
            $dept->getVolunteerTypes()->toArray(),
        ));
    }

    public function testRelationKeysAreOnlyRewrittenWhenPresent(): void
    {
        $location = new Location('Main Hall');
        $location->setAlias('main-hall');
        $dept = new Department('Logistics', 'logistics');
        $dept->addLocation($location);
        $this->em->persist($location);
        $this->em->persist($dept);
        $this->em->flush();

        // A row without the 'locations' key must leave the existing link intact.
        $this->importer()->import([['name' => 'Logistics', 'description' => 'Updated.']]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Logistics']);
        self::assertSame('Updated.', $reloaded->getDescription());
        self::assertCount(1, $reloaded->getLocations(), 'an absent relation key leaves the links untouched');
    }
}
