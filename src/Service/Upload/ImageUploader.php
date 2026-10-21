<?php

declare(strict_types=1);

namespace App\Service\Upload;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Re-encodes uploaded images to WebP (strips EXIF/metadata, caps dimensions) and stores them.
 */
final readonly class ImageUploader
{
    public const MAX_DIMENSION = 1600;
    public const QUALITY = 82;

    public function __construct(
        #[Autowire(service: 'uploads.storage')]
        private FilesystemOperator $storage,
        #[Autowire('%env(UPLOADS_BASE_URL)%')]
        private string $baseUrl,
    ) {
    }

    /**
     * @return string the public URL of the stored image
     */
    public function upload(UploadedFile $file, string $directory, int $maxDimension = self::MAX_DIMENSION): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (false === $source) {
            throw new \InvalidArgumentException('Image illisible.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1.0, $maxDimension / max($width, $height));
        if ($ratio < 1.0) {
            $scaled = imagescale($source, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
            if (false !== $scaled) {
                $source = $scaled;
            }
        }

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        ob_start();
        imagewebp($source, null, self::QUALITY);
        $binary = (string) ob_get_clean();

        $path = \sprintf('%s/%s.webp', trim($directory, '/'), bin2hex(random_bytes(16)));
        $this->storage->write($path, $binary);

        return rtrim($this->baseUrl, '/').'/'.$path;
    }

    public function deleteByUrl(?string $url): void
    {
        $prefix = rtrim($this->baseUrl, '/').'/';
        if (null === $url || !str_starts_with($url, $prefix)) {
            return; // external image (e.g. seeded data): not ours to delete
        }

        $path = substr($url, \strlen($prefix));
        if ($this->storage->fileExists($path)) {
            $this->storage->delete($path);
        }
    }
}
