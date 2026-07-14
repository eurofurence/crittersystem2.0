<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Builds a ZIP archive in memory.
 *
 * ZipArchive can only write to a real filesystem path, but exports are stored through
 * {@see ExportStorage}, whose backend may be object storage with no local path at all. The archive is
 * therefore assembled in a temporary file and returned as bytes; the temporary file is always removed,
 * including when the build fails, because these archives contain personal data.
 */
final class ZipBuilder
{
    /** @param array<string, string> $entries filename => contents */
    public static function build(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'critter-export-');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create a temporary file for the export archive.');
        }

        try {
            $zip = new \ZipArchive();
            $opened = $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new \RuntimeException(sprintf('Unable to create the export archive (ZipArchive error %d).', $opened));
            }

            foreach ($entries as $name => $contents) {
                $zip->addFromString($name, $contents);
            }
            $zip->close();

            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new \RuntimeException('Unable to read back the export archive.');
            }

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }
}
