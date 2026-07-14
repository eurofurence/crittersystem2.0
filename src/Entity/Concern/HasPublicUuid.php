<?php

namespace App\Entity\Concern;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Gives an entity a random, non-sequential public identifier (UUID v4) that is safe to expose
 * in URLs instead of the internal auto-increment primary key.
 *
 * The consuming entity MUST initialise the value in its constructor:
 *
 *     public function __construct(...)
 *     {
 *         $this->uuid = Uuid::v4();
 *         ...
 *     }
 *
 * The integer primary key stays the internal identity (FKs, joins); the UUID is what leaves the
 * server.
 */
trait HasPublicUuid
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }
}
