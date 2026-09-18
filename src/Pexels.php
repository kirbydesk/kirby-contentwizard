<?php

namespace Kirbydesk\Contentwizard;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use Kirby\Toolkit\Str;
use Throwable;

/**
 * Finds a photo or a short video on Pexels and adds it to a page as a
 * pagewizard file (`pwImage`, `pwBackgroundimage`, `pwVideo`), including
 * alt text / title and the credit fields of the legal tab (creator,
 * credit line, source, Pexels License).
 *
 * https://www.pexels.com/api/documentation/
 */
final class Pexels
{
    private const SEARCH       = 'https://api.pexels.com/v1/search';
    private const VIDEO_SEARCH = 'https://api.pexels.com/videos/search';

    /** Background videos: short clips at about this width keep files small. */
    private const VIDEO_MAX_DURATION = 30;
    private const VIDEO_WIDTH        = 1280;

    /**
     * Relative luminance (0–1) of the last attached photo/video, measured
     * from Pexels' average colour or the video's preview image.
     */
    public ?float $luminance = null;

    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    /**
     * Search for $query and attach the best landscape match to $page.
     * Returns null when nothing suitable is found or the download fails —
     * the caller then leaves the image out.
     */
    public function attach(Page $page, string $query, string $alt, string $language, string $template = 'pwImage'): ?File
    {
        $photo = $this->search(self::SEARCH, $query, 'photos')[0] ?? null;
        if ($photo === null) return null;

        $url = $photo['src']['large2x'] ?? $photo['src']['large'] ?? null;
        if (!is_string($url)) return null;

        $creator = (string) ($photo['photographer'] ?? '');
        $this->luminance = is_string($photo['avg_color'] ?? null) ? ThemeContrast::luminance($photo['avg_color']) : null;

        return $this->store($page, $url, Str::slug($query) . '-pexels-' . ($photo['id'] ?? uniqid()) . '.jpg', $template, [
            'imagetitle'       => $alt,
            'imagedescription' => $alt,
            'imagetype'        => 'true',
        ] + self::credit('Foto', $creator, $photo['url'] ?? null), $language);
    }

    /**
     * Search for a short landscape video and attach an MP4 of about
     * VIDEO_WIDTH pixels to $page (template `pwVideo`).
     */
    public function attachVideo(Page $page, string $query, string $title, string $language): ?File
    {
        $videos = $this->search(self::VIDEO_SEARCH, $query, 'videos', ['size' => 'medium']);

        // Prefer short clips; fall back to any result.
        usort($videos, fn ($a, $b) => (($a['duration'] ?? 99) > self::VIDEO_MAX_DURATION) <=> (($b['duration'] ?? 99) > self::VIDEO_MAX_DURATION));

        foreach ($videos as $video) {
            $file = self::videoFile($video['video_files'] ?? []);
            if ($file === null) continue;

            $this->luminance = is_string($video['image'] ?? null) ? self::measure($video['image']) : null;

            return $this->store($page, $file['link'], Str::slug($query) . '-pexels-' . ($video['id'] ?? uniqid()) . '.mp4', 'pwVideo', [
                'videotitle'       => $title,
                'videodescription' => $title,
            ] + self::credit('Video', (string) ($video['user']['name'] ?? ''), $video['url'] ?? null), $language);
        }

        return null;
    }

    /** Average luminance of an image URL (GD), or null. */
    private static function measure(string $url): ?float
    {
        if (!function_exists('imagecreatefromstring')) return null;

        try {
            $response = Remote::get($url, ['timeout' => 15]);
            if ($response->code() !== 200) return null;

            $image = @imagecreatefromstring($response->content());
            if ($image === false) return null;

            // Scale down to one pixel: its colour is the average.
            $pixel = imagecreatetruecolor(1, 1);
            imagecopyresampled($pixel, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
            $rgb = imagecolorat($pixel, 0, 0);

            return ThemeContrast::channelLuminance((($rgb >> 16) & 0xFF) / 255, (($rgb >> 8) & 0xFF) / 255, ($rgb & 0xFF) / 255);
        } catch (Throwable) {
            return null;
        }
    }

    /** Smallest MP4 at least VIDEO_WIDTH wide, else the widest below it. */
    private static function videoFile(array $files): ?array
    {
        $mp4 = array_values(array_filter($files, fn ($f) =>
            ($f['file_type'] ?? '') === 'video/mp4' && is_string($f['link'] ?? null) && ($f['width'] ?? 0) > 0
        ));
        if ($mp4 === []) return null;

        usort($mp4, fn ($a, $b) => $a['width'] <=> $b['width']);
        foreach ($mp4 as $file) {
            if ($file['width'] >= self::VIDEO_WIDTH && $file['width'] <= 1920) return $file;
        }
        $below = array_filter($mp4, fn ($f) => $f['width'] < self::VIDEO_WIDTH);
        return $below !== [] ? end($below) : null;
    }

    private static function credit(string $kind, string $creator, ?string $url): array
    {
        return [
            'mediacreator' => $creator,
            'mediacredit'  => trim($kind . ': ' . $creator . ' / Pexels', ' /'),
            'mediasource'  => 'Pexels – ' . ($url ?? 'https://www.pexels.com'),
            'medialicense' => 'pexels-license',
        ];
    }

    private function store(Page $page, string $url, string $filename, string $template, array $content, string $language): ?File
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pexels');
        try {
            $response = Remote::get($url, ['timeout' => 90]);
            if ($response->code() !== 200) return null;
            F::write($tmp, $response->content());

            $file = $page->createFile([
                'source'   => $tmp,
                'filename' => $filename,
                'template' => $template,
            ]);

            return $file->update($content, $language);
        } catch (Throwable) {
            return null;
        } finally {
            F::remove($tmp);
        }
    }

    /** @return list<array> */
    private function search(string $endpoint, string $query, string $key, array $params = []): array
    {
        try {
            $response = Remote::get($endpoint . '?' . http_build_query($params + [
                'query'       => $query,
                'orientation' => 'landscape',
                'per_page'    => 10,
            ]), [
                'headers' => ['Authorization: ' . $this->apiKey],
                'timeout' => 15,
            ]);
        } catch (Throwable) {
            return [];
        }

        if ($response->code() !== 200) return [];

        $items = $response->json()[$key] ?? [];
        return is_array($items) ? array_values($items) : [];
    }
}
