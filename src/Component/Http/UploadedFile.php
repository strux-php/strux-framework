<?php

declare(strict_types=1);

namespace Strux\Component\Http;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Strux\Component\Filesystem\FilesystemInterface;

class UploadedFile
{
	private UploadedFileInterface $uploadedFile;

	public function __construct(UploadedFileInterface $psrUploadedFile)
	{
		$this->uploadedFile = $psrUploadedFile;
	}

	/**
	 * Checks if the file was uploaded successfully.
	 */
	public function isValid(): bool
	{
		return $this->uploadedFile->getError() === UPLOAD_ERR_OK;
	}

	/**
	 * Store the uploaded file on a filesystem disk, with validation.
	 *
	 * @param string $path The path to store the file in.
	 * @param string|null $disk The filesystem disk to use.
	 * @param array $validationRules Validation rules for the file.
	 * @return string The path where the file was stored (relative to the disk's root).
	 * @throws RuntimeException If the file is not valid or var fails.
	 */
	public function store(string $path, ?string $disk = null, array $validationRules = []): string
	{
		if (!$this->isValid()) {
			throw new RuntimeException($this->getUploadErrorMessage($this->uploadedFile->getError()));
		}

		if (!empty($validationRules)) {
			$this->validate($validationRules);
		}

		$filesystem = container(FilesystemInterface::class);
		if ($disk) {
			$filesystem = clone $filesystem;
			$filesystem->disk($disk);
		}

		$fileName = $this->hashName($path);

		$targetPath = $filesystem->path($fileName);

		$directory = dirname($targetPath);
		if (!is_dir($directory)) {
			mkdir($directory, 0755, true);
		}

		$this->uploadedFile->moveTo($targetPath);

		return $fileName;
	}

	public function getClientOriginalName(): string
	{
		return $this->uploadedFile->getClientFilename();
	}

	public function getClientOriginalExtension(): string
	{
		return strtolower(pathinfo($this->getClientOriginalName(), PATHINFO_EXTENSION));
	}

	public function getSize(): int
	{
		return $this->uploadedFile->getSize();
	}

	public function getClientMimeType(): string
	{
		return $this->uploadedFile->getClientMediaType();
	}

	public function hashName(string $path = ''): string
	{
		$hash = bin2hex(random_bytes(20));
		$extension = $this->getClientOriginalExtension();
		return ltrim(rtrim($path, '/') . '/' . $hash . '.' . $extension, '/');
	}

	public function validate(array $rules): bool
	{
		if (isset($rules['size']) && ($this->getSize() / 1024) > $rules['size']) { // size in KB
			throw new RuntimeException('File size exceeds the maximum limit of ' . $rules['size'] . ' KB.');
		}

		if (isset($rules['mimes'])) {
			$allowedMimes = is_array($rules['mimes']) ? $rules['mimes'] : [$rules['mimes']];
			if (!in_array($this->getClientOriginalExtension(), $allowedMimes)) {
				throw new RuntimeException('Invalid file type. Allowed types: ' . implode(', ', $allowedMimes));
			}
		}
		return true;
	}

	private function getUploadErrorMessage(int $errorCode): string
	{
		return match ($errorCode) {
			UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
			UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
			UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
			UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
			UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
			UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
			UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
			default => 'Unknown upload error.',
		};
	}
}
