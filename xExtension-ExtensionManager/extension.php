<?php

class ExtensionManagerExtension extends Minz_Extension {

	public const ID = 'Extension Manager';

	private const REPO_URL = 'https://github.com/FreshRSS/Extensions';

	public function init(): void {
		$this->registerTranslates();
		$this->registerController('extensionManager');
		$this->registerController('extensionManager', 'configuration');
		$this->registerController('extensionManager', 'update');
		$this->registerController('extensionManager', 'install');
	}

	public function handleConfigureAction(): void {
		$ext = $this;
		Minz_Request::forward(array('c' => 'extensionManager', 'a' => 'configuration'), true);
	}

	public static function getDefaultConfig(): array {
		return [
			'repos' => [
				self::REPO_URL,
			],
		];
	}

	public static function getRepositoryList(): array {
		$config = self::getConfig();
		return $config['repos'] ?? [];
	}

	public static function saveRepositoryList(array $repos): void {
		$config = self::getConfig();
		$config['repos'] = $repos;
		self::saveConfig($config);
	}

	public static function fetchRepoCatalog(string $repoUrl): array {
		$repoUrl = rtrim($repoUrl, '/');

		$zipUrl = $repoUrl . '/archive/refs/heads/main.zip';
		$zipData = self::downloadZip($zipUrl);

		if ($zipData === false) {
			$zipUrl = $repoUrl . '/archive/refs/heads/master.zip';
			$zipData = self::downloadZip($zipUrl);
		}

		if ($zipData === false) {
			return ['error' => 'Failed to download from ' . $repoUrl];
		}

		$tmpFile = tempnam(sys_get_temp_dir(), 'freshrss-ext-');
		if ($tmpFile === false) {
			return ['error' => 'Failed to create temporary file'];
		}

		file_put_contents($tmpFile, $zipData);

		$zip = new ZipArchive();
		if ($zip->open($tmpFile) !== true) {
			unlink($tmpFile);
			return ['error' => 'Failed to open downloaded archive'];
		}

		$catalog = [];

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry = $zip->getNameIndex($i);

			if ($entry === false || !str_ends_with($entry, '/extensions.json')) {
				continue;
			}

			$json = $zip->getFromIndex($i);
			if ($json === false) {
				continue;
			}

			$data = json_decode($json, true);
			if (!is_array($data)) {
				continue;
			}

			if (isset($data['extensions']) && is_array($data['extensions'])) {
				$catalog = array_merge($catalog, $data['extensions']);
			}
		}

		$zip->close();
		unlink($tmpFile);

		return [
			'extensions' => $catalog,
		];
	}

	/**
	 * Download a ZIP archive.
	 *
	 * FreshRSS unregisters unsafe stream wrappers during bootstrap.
	 * Restore HTTPS temporarily so file_get_contents() can fetch GitHub archives.
	 */
	private static function downloadZip($zipUrl) {
		$httpsWasRegistered = in_array('https', stream_get_wrappers(), true);
		if (!$httpsWasRegistered) {
			@stream_wrapper_restore('https');
		}

		$context = stream_context_create([
			'http' => [
				'timeout' => 30,
				'user_agent' => 'FreshRSS-ExtensionManager/1.0',
				'follow_location' => true,
				'max_redirects' => 5,
			],
		]);

		$result = @file_get_contents($zipUrl, false, $context);

		if (!$httpsWasRegistered && in_array('https', stream_get_wrappers(), true)) {
			@stream_wrapper_unregister('https');
		}

		return $result;
	}

	public static function downloadAndInstall(string $repoUrl, string $extensionName): array {
		$catalog = self::fetchRepoCatalog($repoUrl);

		if (isset($catalog['error'])) {
			return $catalog;
		}

		foreach ($catalog['extensions'] as $extension) {
			if (($extension['name'] ?? '') !== $extensionName) {
				continue;
			}

			$extensionUrl = $extension['repo'] ?? '';
			if ($extensionUrl === '') {
				return ['error' => 'Extension repository URL is missing'];
			}

			$zipUrl = rtrim($extensionUrl, '/') . '/archive/refs/heads/main.zip';
			$zipData = self::downloadZip($zipUrl);

			if ($zipData === false) {
				$zipUrl = rtrim($extensionUrl, '/') . '/archive/refs/heads/master.zip';
				$zipData = self::downloadZip($zipUrl);
			}

			if ($zipData === false) {
				return ['error' => 'Failed to download from ' . $extensionUrl];
			}

			$tmpFile = tempnam(sys_get_temp_dir(), 'freshrss-ext-');
			if ($tmpFile === false) {
				return ['error' => 'Failed to create temporary file'];
			}

			file_put_contents($tmpFile, $zipData);

			$zip = new ZipArchive();
			if ($zip->open($tmpFile) !== true) {
				unlink($tmpFile);
				return ['error' => 'Failed to open downloaded archive'];
			}

			$root = $zip->getNameIndex(0);
			if ($root === false) {
				$zip->close();
				unlink($tmpFile);
				return ['error' => 'Invalid extension archive'];
			}

			$root = explode('/', $root)[0];
			$targetDir = FRESHRSS_PATH . '/extensions/' . $extensionName;

			if (!is_dir($targetDir)) {
				mkdir($targetDir, 0755, true);
			}

			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = $zip->getNameIndex($i);

				if ($name === false || !str_starts_with($name, $root . '/')) {
					continue;
				}

				$relative = substr($name, strlen($root) + 1);

				if ($relative === '') {
					continue;
				}

				$destination = $targetDir . '/' . $relative;

				if (str_ends_with($name, '/')) {
					if (!is_dir($destination)) {
						mkdir($destination, 0755, true);
					}
					continue;
				}

				$destinationDir = dirname($destination);
				if (!is_dir($destinationDir)) {
					mkdir($destinationDir, 0755, true);
				}

				$data = $zip->getFromIndex($i);
				if ($data !== false) {
					file_put_contents($destination, $data);
				}
			}

			$zip->close();
			unlink($tmpFile);

			return [
				'success' => true,
			];
		}

		return ['error' => 'Extension not found in repository'];
	}

	private static function getConfig(): array {
		$config = Minz_Configuration::getConfiguration();
		return $config->extensions[self::ID] ?? self::getDefaultConfig();
	}

	private static function saveConfig(array $config): void {
		$configuration = Minz_Configuration::getConfiguration();
		$configuration->extensions[self::ID] = $config;
		$configuration->save();
	}

	private function registerTranslates(): void {
		Minz_Translate::registerExtension($this->getPath());
	}
}
