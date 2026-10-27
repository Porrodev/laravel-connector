<?php

namespace Porro\Laravel;

use Closure;
use Composer\InstalledVersions;

/**
 * The connector for the site's CMS or admin panel, when the site uses one
 * but only has this package. They add Porro's pages to the control panel,
 * so connecting without them works but misses the part people see.
 */
final class Companions
{
    /** Platform package => the Porro connector for it. */
    public const CONNECTORS = [
        'filament/filament' => ['porro/filament-connector', 'Filament', 'Site health and Blocking pages in the panel'],
        'statamic/cms' => ['porro/statamic-connector', 'Statamic', 'a Porro section in the control panel'],
    ];

    /** @var Closure(string): bool */
    private Closure $installed;

    /**
     * @param  (Closure(string): bool)|null  $installed  whether a Composer package is installed
     */
    public function __construct(?Closure $installed = null)
    {
        $this->installed = $installed ?? fn (string $package) => InstalledVersions::isInstalled($package);
    }

    /**
     * @return list<string> one hint per missing connector
     */
    public function hints(): array
    {
        $hints = [];

        foreach (self::CONNECTORS as $platform => [$connector, $name, $adds]) {
            if (($this->installed)($platform) && ! ($this->installed)($connector)) {
                $hints[] = "This site uses {$name}. Run `composer require {$connector}` for {$adds}.";
            }
        }

        return $hints;
    }
}
