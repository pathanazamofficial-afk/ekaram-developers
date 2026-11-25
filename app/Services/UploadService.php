<?php
declare(strict_types=1);

final class UploadService
{
    private const MAX_IMAGE_BYTES = 5242880;
    private const MAX_IMAGE_DIMENSION = 6000;

    public static function storeAmenityImage(array $upload): string
    {
        return self::storeImage($upload, 'amenities');
    }

    public static function storeImage(array $upload, string $category): string
    {
        return self::storeValidatedImage($upload, $category, ['image/jpeg', 'image/png']);
    }

    public static function storeMarketingAssociatePhoto(array $upload): string
    {
        return self::storeValidatedImage(
            $upload,
            'marketing-associates',
            ['image/jpeg', 'image/png', 'image/webp']
        );
    }

    private static function storeValidatedImage(array $upload, string $category, array $allowedMimes): string
    {
        if (!in_array($category, ['amenities', 'gallery', 'marketing-associates'], true)) {
            throw new InvalidArgumentException('Invalid image category');
        }
        if (!isset($upload['error'], $upload['tmp_name'], $upload['size'])
            || (int) $upload['error'] !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) $upload['tmp_name'])
            || (int) $upload['size'] < 1
            || (int) $upload['size'] > self::MAX_IMAGE_BYTES
        ) {
            throw new InvalidArgumentException('Choose an image smaller than 5 MB');
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fileInfo->file((string) $upload['tmp_name']);
        if (!in_array($mime, $allowedMimes, true)) {
            throw new InvalidArgumentException('Choose a valid JPEG, PNG, or WebP image');
        }

        $dimensions = getimagesize((string) $upload['tmp_name']);
        if ($dimensions === false
            || $dimensions[0] < 1
            || $dimensions[1] < 1
            || $dimensions[0] > self::MAX_IMAGE_DIMENSION
            || $dimensions[1] > self::MAX_IMAGE_DIMENSION
            || $dimensions[0] * $dimensions[1] > 30000000
        ) {
            throw new InvalidArgumentException('Image dimensions are invalid or too large');
        }

        $bytes = file_get_contents((string) $upload['tmp_name']);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read uploaded image');
        }
        $image = imagecreatefromstring($bytes);
        if ($image === false) {
            throw new InvalidArgumentException('The uploaded image could not be decoded');
        }

        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $category;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            imagedestroy($image);
            throw new RuntimeException('Unable to create the private upload directory');
        }

        $storeAsPng = $mime === 'image/png';
        $filename = bin2hex(random_bytes(16)) . ($storeAsPng ? '.png' : '.jpg');
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        $saved = $storeAsPng
            ? imagepng($image, $destination, 6)
            : imagejpeg($image, $destination, 86);
        imagedestroy($image);

        if (!$saved) {
            if (is_file($destination)) {
                unlink($destination);
            }
            throw new RuntimeException('Unable to store uploaded image');
        }

        if (PHP_OS_FAMILY !== 'Windows' && !chmod($destination, 0640)) {
            unlink($destination);
            throw new RuntimeException('Unable to restrict uploaded image permissions');
        }
        return $category . '/' . $filename;
    }

    public static function storeBrochure(array $upload): string
    {
        if (!isset($upload['error'], $upload['tmp_name'], $upload['size'])
            || (int) $upload['error'] !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) $upload['tmp_name'])
            || (int) $upload['size'] < 1
            || (int) $upload['size'] > 10485760
        ) {
            throw new InvalidArgumentException('Choose a PDF brochure smaller than 10 MB');
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fileInfo->file((string) $upload['tmp_name']);
        $signature = file_get_contents((string) $upload['tmp_name'], false, null, 0, 5);
        if ($mime !== 'application/pdf' || $signature !== '%PDF-') {
            throw new InvalidArgumentException('Only valid PDF brochures are accepted');
        }

        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'brochures';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the private brochure directory');
        }
        $filename = bin2hex(random_bytes(16)) . '.pdf';
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file((string) $upload['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to store uploaded brochure');
        }
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($destination, 0640)) {
            unlink($destination);
            throw new RuntimeException('Unable to restrict uploaded brochure permissions');
        }

        return 'brochures/' . $filename;
    }

    public static function deleteStoredFile(?string $relativePath): bool
    {
        if ($relativePath === null || !preg_match('~^(amenities|gallery|marketing-associates|brochures)/[a-f0-9]{32}\.(jpg|png|pdf)$~', $relativePath)) {
            return $relativePath === null;
        }
        $root = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads');
        $file = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($file === false) {
            return true;
        }
        if ($root === false || strpos(strtolower($file), strtolower($root . DIRECTORY_SEPARATOR)) !== 0) {
            return false;
        }

        return unlink($file);
    }
}
