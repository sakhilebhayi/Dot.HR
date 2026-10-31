<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $normalized
 * @property int $position
 */
class ProfileSkill extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'normalized', 'position'];
}
