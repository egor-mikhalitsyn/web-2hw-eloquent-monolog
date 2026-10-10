<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Таблица demo из учебного стенда (раньше читалась/писалась через PDO).
 *
 * @property int $id
 * @property string|null $note
 * @property \Illuminate\Support\Carbon $created_at
 */
class Demo extends Model
{
    protected $table = 'demo';

    protected $fillable = ['note'];

    // В таблице есть только created_at, колонки updated_at нет
    public const UPDATED_AT = null;
}
