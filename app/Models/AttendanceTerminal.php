<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceTerminal extends Model
{
    use BelongsToInstitution, HasFactory;

    protected $fillable = [
        'institution_id',
        'name',
        'code',
        'mode', // exclusivamente 'entry' (Ingreso) o 'exit' (Salida)
        'location',
        'device_identifier',
        'description',
        'is_active',
        'last_heartbeat_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_heartbeat_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeEntry(Builder $query): Builder
    {
        return $query->where('mode', 'entry');
    }

    public function scopeExit(Builder $query): Builder
    {
        return $query->where('mode', 'exit');
    }

    public function getModeLabelAttribute(): string
    {
        return $this->mode === 'entry' ? 'Ingreso' : 'Salida';
    }
}
