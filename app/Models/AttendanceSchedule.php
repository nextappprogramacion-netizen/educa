<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSchedule extends Model
{
    use BelongsToInstitution, HasFactory;

    protected $fillable = [
        'institution_id',
        'academic_year_id',
        'name',
        'shift',
        'entry_time',
        'exit_time',
        'tolerance_minutes',
        'late_threshold_time',
        'days_of_week',
        'is_active',
    ];

    protected $casts = [
        'tolerance_minutes' => 'integer',
        'days_of_week' => 'array',
        'is_active' => 'boolean',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
