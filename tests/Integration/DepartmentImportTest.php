<?php

namespace App\Tests\Integration;

use App\Department\DepartmentImporter;
use App\Entity\Department;
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
}
