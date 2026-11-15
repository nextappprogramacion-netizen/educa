<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StudentCredential extends Model
{
    use BelongsToInstitution, HasFactory;

    protected $fillable = [
        'institution_id',
        'student_id',
        'qr_code',
        'status',
        'issued_at',
        'expires_at',
        'invalidated_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Genera un código QR único y seguro criptográficamente sin incluir datos personales.
     */
    public static function generateSecureQrCode(): string
    {
        do {
            $token = 'EDUCA-SEC-'.Str::uuid()->toString();
        } while (static::where('qr_code', $token)->exists());

        return $token;
    }
}
