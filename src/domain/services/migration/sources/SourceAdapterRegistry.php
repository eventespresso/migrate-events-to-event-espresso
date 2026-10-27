<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources;

use EE_Dependency_Map;
use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\core\exceptions\InvalidIdentifierException;
use EventEspresso\core\services\collections\CollectionDetails;
use EventEspresso\core\services\collections\CollectionDetailsException;
use EventEspresso\core\services\collections\CollectionInterface;
use EventEspresso\core\services\collections\CollectionLoader;
use EventEspresso\core\services\collections\CollectionLoaderException;
use EventEspresso\core\services\database\TableAnalysis;
use EventEspresso\core\services\locators\FileLocator;
use EventEspresso\EventMigrator\application\services\migration\SourceAdapter;
use EventEspresso\EventMigrator\application\services\migration\SourceDetection;

/**
 * Class SourceAdapterRegistry
 *
 * Discovers every concrete adapter by file: each lives in its own directory
 * under sources/ as "<dir>/<Studly>SourceAdapter.php" (e.g.
 * events_manager/EventsManagerSourceAdapter.php) with the namespace following
 * the path. The directory name must be a valid namespace segment (snake_case);
 * the adapter's slug() may still be hyphenated. The same discovery feeds
 * EE_Event_Migrator::after_registration() through adapterDependencies(), so a
 * new adapter file gets its TableAnalysis dependency registered with no other
 * change (REQ-023).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class SourceAdapterRegistry
{
    public const COLLECTION_NAME = 'event_migrator_sources';

    public const FILE_MASK = '*/*SourceAdapter.php';

    private string $sources_dir;

    private ?CollectionInterface $adapters = null;


    /**
     * @param string $sources_dir The directory to scan; defaults to this add-on's sources/ directory.
     */
    public function __construct(string $sources_dir = '')
    {
        $this->sources_dir = $sources_dir !== '' ? $sources_dir : __DIR__;
    }


    /**
     * Every discovered adapter, slug => adapter.
     *
     * @return array<string, SourceAdapter>
     * @throws CollectionDetailsException
     * @throws CollectionLoaderException
     * @throws InvalidDataTypeException
     */
    public function all(): array
    {
        $adapters = [];
        foreach ($this->collection() as $adapter) {
            if ($adapter instanceof SourceAdapter) {
                $adapters[ $adapter->slug() ] = $adapter;
            }
        }
        ksort($adapters, SORT_STRING);
        return $adapters;
    }


    /**
     * @throws InvalidIdentifierException When no adapter has the slug.
     */
    public function get(string $slug): SourceAdapter
    {
        $adapters = $this->all();
        if (! isset($adapters[ $slug ])) {
            throw new InvalidIdentifierException(esc_html($slug), 'events-manager');
        }
        return $adapters[ $slug ];
    }


    public function has(string $slug): bool
    {
        return isset($this->all()[ $slug ]);
    }


    /**
     * Adapters whose source plugin is installed, with their detection result,
     * sorted by display name: slug => ['adapter' => SourceAdapter, 'detection' => SourceDetection].
     * Not-installed sources are omitted; inactive ones are included so the
     * wizard can show them disabled with the reason (REQ-001).
     *
     * @return array<string, array{adapter: SourceAdapter, detection: SourceDetection}>
     * @throws CollectionDetailsException
     * @throws CollectionLoaderException
     * @throws InvalidDataTypeException
     */
    public function detected(): array
    {
        $detected = [];
        foreach ($this->all() as $slug => $adapter) {
            $detection = $adapter->detect();
            if ($detection->isInstalled()) {
                $detected[ $slug ] = ['adapter' => $adapter, 'detection' => $detection];
            }
        }
        uasort(
            $detected,
            static fn(array $a, array $b): int => strcasecmp($a['adapter']->name(), $b['adapter']->name())
        );
        return $detected;
    }


    /**
     * The fully qualified class names of every adapter file under a sources directory, derived from the path
     * because the namespace follows it. Nothing is loaded or built.
     *
     * @param string $sources_dir The directory to scan; defaults to this add-on's sources/ directory.
     * @return string[] sorted
     * @throws InvalidDataTypeException
     */
    public static function discoverClassNames(string $sources_dir = ''): array
    {
        $class_names = array_values(self::classNamesByFile($sources_dir !== '' ? $sources_dir : __DIR__));
        sort($class_names, SORT_STRING);
        return $class_names;
    }


    /**
     * The dependency-map entry of every discovered adapter: each takes only TableAnalysis (SourceAdapterBase
     * reads $wpdb from the globals because wpdb is not a dependency-map service).
     *
     * @param string $sources_dir The directory to scan; defaults to this add-on's sources/ directory.
     * @return array<string, array<string, string>> adapter class => [dependency class => load strategy]
     * @throws InvalidDataTypeException
     */
    public static function adapterDependencies(string $sources_dir = ''): array
    {
        $dependencies = [];
        foreach (self::discoverClassNames($sources_dir) as $class_name) {
            $dependencies[ $class_name ] = [TableAnalysis::class => EE_Dependency_Map::load_from_cache];
        }
        return $dependencies;
    }


    /**
     * The adapter class names under the scanned directory, each file loaded so the class exists whether or not
     * the directory is covered by a PSR-4 autoloader.
     *
     * @return string[] sorted
     * @throws InvalidDataTypeException
     */
    private function loadClassNames(): array
    {
        $class_names = self::classNamesByFile($this->sources_dir);
        foreach ($class_names as $file_path => $class_name) {
            if (! class_exists($class_name, false)) {
                require_once $file_path;
            }
        }
        $class_names = array_values($class_names);
        sort($class_names, SORT_STRING);
        return $class_names;
    }


    /**
     * @return array<string, string> adapter file path => fully qualified class name
     * @throws InvalidDataTypeException
     */
    private static function classNamesByFile(string $sources_dir): array
    {
        $locator = new FileLocator();
        $locator->setFileMask(self::FILE_MASK);
        $class_names = [];
        foreach ($locator->locate($sources_dir) as $file_path) {
            $class_names[ $file_path ] = __NAMESPACE__
                . '\\' . basename(dirname($file_path))
                . '\\' . basename($file_path, '.php');
        }
        return $class_names;
    }


    /**
     * Loads the adapters once through the core CollectionLoader, which builds
     * each one with its registered constructor dependencies.
     *
     * @throws CollectionDetailsException
     * @throws CollectionLoaderException
     * @throws InvalidDataTypeException
     */
    private function collection(): CollectionInterface
    {
        if (! $this->adapters instanceof CollectionInterface) {
            $loader = new CollectionLoader(
                new CollectionDetails(
                    self::COLLECTION_NAME,
                    SourceAdapter::class,
                    $this->loadClassNames(),
                    [],
                    '',
                    CollectionDetails::ID_CALLBACK_METHOD,
                    'slug'
                )
            );
            $this->adapters = $loader->getCollection();
        }
        return $this->adapters;
    }
}
// End of file SourceAdapterRegistry.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources/SourceAdapterRegistry.php
