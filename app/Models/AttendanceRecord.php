<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use BelongsToInstitution, HasFactory;

    protected $fillable = [
        'institution_id',
        'enrollment_id',
        'academic_year_id',
        'section_id',
        'date',
        'shift',
        'session_type',
        'status', // 'present', 'late', 'absent'
        'entry_time',
        'exit_time',
        'exit_type', // 'regular', 'early'
        'is_early_exit',
        'early_exit_reason',
        'early_exit_authorized_by',
        'exit_registered_by',
        'observation',
        'registered_by',
        'updated_by',
        'is_justified',
        'source',
    ];

    protected $casts = [
        'date' => 'date',
        'is_justified' => 'boolean',
        'is_early_exit' => 'boolean',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function earlyExitAuthorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'early_exit_authorized_by');
    }

    public function exitRegisteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exit_registered_by');
    }
}
