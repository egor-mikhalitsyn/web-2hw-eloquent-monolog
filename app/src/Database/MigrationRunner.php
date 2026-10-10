<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;

/**
 * Thin wrapper over Illuminate's Migrator, so migrations work without the
 * whole Laravel framework / artisan. Migration files are the same format as
 * in Laravel (anonymous class extending Illuminate\Database\Migrations\Migration).
 */
final class MigrationRunner
{
    private readonly Migrator $migrator;
    private readonly DatabaseMigrationRepository $repository;

    public function __construct(
        ConnectionResolverInterface $resolver,
        private readonly string $path,
        string $table = 'migrations',
        private readonly Filesystem $files = new Filesystem(),
    ) {
        $this->repository = new DatabaseMigrationRepository($resolver, $table);
        $this->migrator = new Migrator($this->repository, $resolver, $this->files);
    }

    private function ensureRepository(): void
    {
        if (!$this->repository->repositoryExists()) {
            $this->repository->createRepository();
        }
    }

    /** Run all pending migrations. @return list<string> names of the migrations executed */
    public function migrate(): array
    {
        $this->ensureRepository();

        return $this->names($this->migrator->run([$this->path]));
    }

    /** Roll back the last batch (or $steps migrations). @return list<string> */
    public function rollback(int $steps = 0): array
    {
        $this->ensureRepository();

        return $this->names($this->migrator->rollback([$this->path], $steps > 0 ? ['step' => $steps] : []));
    }

    /** Roll back everything. @return list<string> */
    public function reset(): array
    {
        $this->ensureRepository();

        return $this->names($this->migrator->reset([$this->path]));
    }

    /** @return list<array{name:string, ran:bool, batch:?int}> */
    public function status(): array
    {
        $this->ensureRepository();

        $ran = $this->repository->getMigrationBatches();
        $rows = [];
        foreach ($this->migrator->getMigrationFiles($this->path) as $name => $_file) {
            $rows[] = ['name' => $name, 'ran' => isset($ran[$name]), 'batch' => $ran[$name] ?? null];
        }

        return $rows;
    }

    /** Create a new empty migration file. @return string path of the created file */
    public function make(string $name, ?string $table = null, bool $create = false): string
    {
        $name = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name) ?? '', '_'));
        if ($name === '' || ($table !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1)) {
            throw new \InvalidArgumentException('Use a non-empty migration name and a valid table identifier.');
        }
        $file = sprintf('%s/%s_%s.php', $this->path, date('Y_m_d_His'), $name);
        if ($this->files->exists($file)) {
            throw new \RuntimeException('Migration already exists: ' . $file);
        }

        $this->files->ensureDirectoryExists($this->path);

        $up = $create && $table
            ? "Capsule::schema()->create('{$table}', function (Blueprint \$table) {\n            \$table->id();\n            \$table->timestamps();\n        });"
            : ($table
                ? "Capsule::schema()->table('{$table}', function (Blueprint \$table) {\n            //\n        });"
                : '//');
        $down = $create && $table ? "Capsule::schema()->dropIfExists('{$table}');" : '//';

        $this->files->put($file, <<<PHP
<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        {$up}
    }

    public function down(): void
    {
        {$down}
    }
};

PHP);

        return $file;
    }

    /**
     * @param array<array-key, string> $files
     * @return list<string>
     */
    private function names(array $files): array
    {
        return array_values(array_map(
            fn (string $file) => $this->migrator->getMigrationName($file),
            $files,
        ));
    }
}
