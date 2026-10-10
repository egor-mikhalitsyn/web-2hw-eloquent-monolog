<?php

use App\Models\Demo;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Таблица demo для главной страницы. Раньше её создавал docker/postgres/initdb/01-init.sql,
 * теперь схемой управляют миграции (`make migrate`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('demo', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('created_at')->useCurrent();
            $table->text('note')->nullable();
        });

        Demo::create(['note' => 'Привет из миграции!']);
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('demo');
    }
};
