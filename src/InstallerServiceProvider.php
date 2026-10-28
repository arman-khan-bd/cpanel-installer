<?php

declare(strict_types=1);

namespace NextPHP\Installer;

/**
 * Class InstallerServiceProvider
 *
 * Package discovery provider for NextPHP / Nexus frameworks.
 * Automatically detects whether the application has been installed,
 * and boots the setup wizard if uninstalled.
 */
class InstallerServiceProvider
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 4);
    }

    /**
     * Check installation status and register installer if needed.
     */
    public function boot(): void
    {
        $installer = new Installer($this->baseDir);
        if (!$installer->isInstalled()) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';

            // Route to installer if visiting root or install route
            if ($path === '/' || str_starts_with($path, '/install')) {
                $installer->dispatch();
                exit(0);
            }
        }
    }
}
