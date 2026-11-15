<?php

namespace App\Models\Concerns;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::creating(function ($model) {
            if (empty($model->institution_id) && Auth::check() && Auth::user()->institution_id) {
                $model->institution_id = Auth::user()->institution_id;
            }
        });
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function scopeForInstitution(Builder $query, ?int $institutionId = null): Builder
    {
        $id = $institutionId ?? Auth::user()?->institution_id;

        if ($id) {
            return $query->where($this->getTable().'.institution_id', $id);
        }

        return $query;
    }
}
