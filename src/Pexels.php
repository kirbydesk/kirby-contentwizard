<?php

namespace Kirbydesk\Contentwizard;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use Kirby\Toolkit\Str;
use Throwable;

/**
 * Finds a photo on Pexels and adds it to a page as a pagewizard image
 * file (template `pwImage`), including alt text and the credit fields
 * of the legal tab (photographer, credit line, source, Pexels License).
 *
 * https://www.pexels.com/api/documentation/
 */
final class Pexels
{
    private const SEARCH = 'https://api.pexels.com/v1/search';

    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    /**
     * Search for $query and attach the best landscape match to $page.
     * Returns null when nothing suitable is found or the download fails —
     * the caller then leaves the image out.
     */
    public function attach(Page $page, string $query, string $alt, string $language): ?File
    {
        $photo = $this->search($query);
        if ($photo === null) return null;

        $url = $photo['src']['large2x'] ?? $photo['src']['large'] ?? null;
        if (!is_string($url)) return null;

        $tmp = tempnam(sys_get_temp_dir(), 'pexels');
        try {
            $response = Remote::get($url, ['timeout' => 30]);
            if ($response->code() !== 200) return null;
            F::write($tmp, $response->content());

            $photographer = (string) ($photo['photographer'] ?? '');
            $filename     = Str::slug($query) . '-pexels-' . ($photo['id'] ?? uniqid()) . '.jpg';

            $file = $page->createFile([
                'source'   => $tmp,
                'filename' => $filename,
                'template' => 'pwImage',
            ]);

            return $file->update([
                'imagetitle'       => $alt,
                'imagedescription' => $alt,
                'imagetype'        => 'true',
                'mediacreator'     => $photographer,
                'mediacredit'      => trim('Foto: ' . $photographer . ' / Pexels', ' /'),
                'mediasource'      => 'Pexels – ' . ($photo['url'] ?? 'https://www.pexels.com'),
                'medialicense'     => 'pexels-license',
            ], $language);
        } catch (Throwable) {
            return null;
        } finally {
            F::remove($tmp);
        }
    }

    private function search(string $query): ?array
    {
        try {
            $response = Remote::get(self::SEARCH . '?' . http_build_query([
                'query'       => $query,
                'orientation' => 'landscape',
                'per_page'    => 5,
            ]), [
                'headers' => ['Authorization: ' . $this->apiKey],
                'timeout' => 15,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->code() !== 200) return null;

        $photos = $response->json()['photos'] ?? [];
        return is_array($photos) && $photos !== [] ? $photos[0] : null;
    }
}
