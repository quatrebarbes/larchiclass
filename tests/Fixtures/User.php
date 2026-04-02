<?php
namespace Quatrebarbes\Larchiclass\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class User extends Model
{
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden   = ['password'];
    protected $appends  = ['full_name'];
    protected $casts    = ['is_admin' => 'boolean'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
