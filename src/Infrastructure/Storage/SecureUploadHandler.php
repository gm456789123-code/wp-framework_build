<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\ValueObject\UuidV7;
use App\Infrastructure\Security\FieldEncryptor;
use finfo;
use InvalidArgumentException;
use RuntimeException;

final readonly class SecureUploadHandler
{
    /**
     * @param list<string> $allowedMimes
     * @param bool $testMode When false (the default, production behaviour),
     *   $tmpFilePath must be a file PHP itself registered as an HTTP upload
     *   (is_uploaded_file()) and is moved with move_uploaded_file() -- the
     *   spec's "Direct access to $_FILES is prohibited, must pass through
     *   SecureUploadHandler" only means something if this class is the one
     *   place verifying the file genuinely came from a POST upload, not an
     *   arbitrary server-side path a caller was tricked into passing in.
     *   is_uploaded_file()/move_uploaded_file() only recognize real uploads
     *   within a real PHP SAPI request, so tests (which write a plain temp
     *   file with tempnam()) opt into $testMode to use copy() instead.
     */
    public function __construct(
        private string $vaultDirectory,
        private FieldEncryptor $encryptor,
        private array $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        private bool $testMode = false,
    ) {
    }

    /**
     * Handles file upload validation, magic byte checking, UUID v7 renaming, and encrypted metadata.
     *
     * @return array{uuid: string, storedPath: string, mimeType: string, encryptedOriginalName: string, size: int}
     */
    public function handleUpload(string $tmpFilePath, string $originalFilename): array
    {
        if (!is_file($tmpFilePath)) {
            throw new InvalidArgumentException(sprintf('File does not exist: "%s".', $tmpFilePath));
        }

        if (!$this->testMode && !is_uploaded_file($tmpFilePath)) {
            throw new InvalidArgumentException('File was not submitted as an HTTP upload.');
        }

        // Magic bytes inspection
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($tmpFilePath);

        if ($detectedMime === false || !in_array($detectedMime, $this->allowedMimes, true)) {
            throw new InvalidArgumentException(
                sprintf('Disallowed file type: "%s". File extension is untrusted.', (string) $detectedMime)
            );
        }

        $size = filesize($tmpFilePath);
        if ($size === false) {
            throw new RuntimeException('Failed to read file size.');
        }

        // Ensure vault directory exists
        if (!is_dir($this->vaultDirectory) && !mkdir($this->vaultDirectory, 0700, true) && !is_dir($this->vaultDirectory)) {
            throw new RuntimeException(sprintf('Failed to create vault directory: "%s".', $this->vaultDirectory));
        }

        // Generate UUID v7 for storage
        $uuid = UuidV7::generate()->value;
        $targetFile = rtrim($this->vaultDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $uuid . '.vault';

        $moved = $this->testMode
            ? copy($tmpFilePath, $targetFile)
            : move_uploaded_file($tmpFilePath, $targetFile);

        if (!$moved) {
            throw new RuntimeException(sprintf('Failed to write uploaded file to vault: "%s".', $targetFile));
        }

        // Encrypt original filename metadata
        $encryptedName = $this->encryptor->encrypt($originalFilename);

        return [
            'uuid' => $uuid,
            'storedPath' => $targetFile,
            'mimeType' => $detectedMime,
            'encryptedOriginalName' => $encryptedName,
            'size' => $size,
        ];
    }
}
