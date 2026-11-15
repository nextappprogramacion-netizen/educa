<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceIncident extends Model
{
    use BelongsToInstitution, HasFactory;

    protected $fillable = [
        'institution_id',
        'student_id',
        'enrollment_id',
        'attendance_record_id',
        'type', // 'tardiness', 'card_forgotten', 'early_exit'
        'date',
        'time',
        'reason',
        'responsible_name',
        'responsible_relationship',
        'registered_by',
        'status', // 'registered', 'justified', 'pending'
        'is_justified',
        'justification_reason',
        'justified_by',
        'justified_at',
    ];

    protected $casts = [
        'date' => 'date',
        'is_justified' => 'boolean',
        'justified_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function justifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by');
    }
}
