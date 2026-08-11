<?php

/**
 * Bundle the slide deck into one file that can be emailed.
 *
 * Reads docs/slides/deck.html, replaces every <img src="img/*.png"> with an inline WebP data URI,
 * and writes docs/slides/critter-introduction.html. The result has no external references at all,
 * so it works from a USB stick, an email attachment or a laptop with no network.
 *
 *   php bin/build-slides.php [--width=1600] [--quality=80]
 *
 * Edit deck.html, not the bundled file: the bundle is overwritten on every run.
 */

$options = getopt('', ['width::', 'quality::', 'source::', 'out::']);

$source = $options['source'] ?? __DIR__.'/../docs/slides/deck.html';
$out = $options['out'] ?? __DIR__.'/../docs/slides/critter-introduction.html';
$width = (int) ($options['width'] ?? 1600);
$quality = (int) ($options['quality'] ?? 80);

if (!is_file($source)) {
    fwrite(STDERR, "No deck at {$source}\n");
    exit(1);
}

$html = file_get_contents($source);
$baseDir = dirname(realpath($source));
$cache = [];
$missing = [];
$originalBytes = 0;
$embeddedBytes = 0;

$html = preg_replace_callback(
    '#src="(img/[^"]+\.png)"#',
    function (array $m) use ($baseDir, $width, $quality, &$cache, &$missing, &$originalBytes, &$embeddedBytes): string {
        $rel = $m[1];
        if (isset($cache[$rel])) {
            return 'src="'.$cache[$rel].'"';
        }

        $path = $baseDir.'/'.$rel;
        if (!is_file($path)) {
            $missing[] = $rel;

            return $m[0];
        }

        $src = imagecreatefrompng($path);
        if ($src === false) {
            $missing[] = $rel;

            return $m[0];
        }

        $originalBytes += filesize($path);

        $sw = imagesx($src);
        $sh = imagesy($src);
        $tw = min($width, $sw);
        $th = (int) round($sh * $tw / $sw);

        $dst = imagecreatetruecolor($tw, $th);
        // Screenshots are opaque; flattening onto white avoids a needless alpha channel and keeps
        // the WebP roughly a third smaller.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        ob_start();
        imagewebp($dst, null, $quality);
        $webp = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        $embeddedBytes += \strlen($webp);
        $uri = 'data:image/webp;base64,'.base64_encode($webp);
        $cache[$rel] = $uri;

        printf("  %-34s %6dK -> %5dK\n", basename($rel), filesize($path) / 1024, \strlen($webp) / 1024);

        return 'src="'.$uri.'"';
    },
    $html,
);

if ($missing !== []) {
    fwrite(STDERR, "\nMissing images, left as references:\n  ".implode("\n  ", $missing)."\n");
    fwrite(STDERR, "Run bin/demo-screenshots.php first.\n");
}

file_put_contents($out, $html);

printf(
    "\n%d images, %dK of PNG became %dK of WebP.\nWrote %s (%dK)\n",
    \count($cache),
    $originalBytes / 1024,
    $embeddedBytes / 1024,
    realpath($out),
    filesize($out) / 1024,
);

exit($missing === [] ? 0 : 1);
