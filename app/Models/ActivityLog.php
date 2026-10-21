<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false; // we use created_at only

    protected $fillable = [
        'loggable_type', 'loggable_id', 'user_id', 'event',
        'old_value', 'new_value', 'metadata', 'created_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /** One line in the audit trail (who did what to which record). */
    public static function record(string $event, ?Model $subject = null, array $metadata = [], ?string $old = null, ?string $new = null): void
    {
        try {
            static::create([
                'loggable_type' => $subject ? $subject::class : null,
                'loggable_id' => $subject?->getKey(),
                'user_id' => auth()->id(),
                'event' => $event,
                'old_value' => $old,
                'new_value' => $new,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // the audit trail must never break the action itself
        }
    }

    // Polymorphic relationship
    public function loggable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}