<?php

namespace Modules\User\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\User\Database\Factories\DepartmentFactory;
use Modules\User\Enums\Role;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // A department can have more than one manager.
    public function managers(): HasMany
    {
        return $this->users()->where('role', Role::Manager);
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }
}
