<?php
namespace Quatrebarbes\Larchiclass\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $fillable = ['title', 'body', 'user_id'];
    protected $hidden   = ['body'];
    protected $casts    = ['published_at' => 'datetime', 'views' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
