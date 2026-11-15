<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\Student;
use App\Models\StudentCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CredentialController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $institutionId = $user->institution_id;

        if (! $institutionId) {
            $defaultInstitution = Institution::where('is_active', true)->first();
            if ($defaultInstitution) {
                $institutionId = $defaultInstitution->id;
                $user->forceFill(['institution_id' => $institutionId])->save();
            }
        }

        $search = $request->input('search');
        $statusFilter = $request->input('status', 'all');

        $query = Student::query()
            ->where('students.institution_id', $institutionId)
            ->with([
                'currentEnrollment.section.grade',
                'credential',
                'credentials',
            ]);

        if ($search) {
            $driver = DB::connection()->getDriverName();
            $like = $driver === 'pgsql' ? 'ilike' : 'like';
            $term = "%{$search}%";

            $query->where(function ($q) use ($like, $term, $driver) {
                $q->where('students.first_name', $like, $term)
                    ->orWhere('students.last_name', $like, $term)
                    ->orWhere('students.document_number', $like, $term)
                    ->orWhere('students.code', $like, $term);

                if ($driver === 'pgsql') {
                    $q->orWhereRaw("concat(students.first_name, ' ', students.last_name) ilike ?", [$term])
                        ->orWhereRaw("concat(students.last_name, ' ', students.first_name) ilike ?", [$term]);
                } else {
                    $q->orWhereRaw("(students.first_name || ' ' || students.last_name) like ?", [$term])
                        ->orWhereRaw("(students.last_name || ' ' || students.first_name) like ?", [$term]);
                }
            });
        }

        if ($statusFilter === 'active_credential') {
            $query->whereHas('credential', fn ($q) => $q->where('status', 'active'));
        } elseif ($statusFilter === 'pending') {
            $query->where('is_active', true)->where(function ($q) {
                $q->doesntHave('credential')
                    ->orWhereHas('credential', fn ($cq) => $cq->where('status', 'invalidated'));
            });
        } elseif ($statusFilter === 'blocked') {
            $query->whereHas('credential', fn ($q) => $q->where('status', 'blocked'));
        }

        $students = $query->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->get()
            ->map(function (Student $student) {
                $enrollment = $student->currentEnrollment;
                $classroom = $enrollment && $enrollment->section && $enrollment->section->grade
                    ? "{$enrollment->section->grade->name} - {$enrollment->section->name}"
                    : 'Sin asignar';

                $currentCredential = $student->credential;

                return [
                    'id' => $student->id,
                    'code' => $student->code,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'full_name' => $student->full_name,
                    'document_number' => $student->document_number,
                    'classroom' => $classroom,
                    'shift' => $student->shift ?? 'Mañana',
                    'is_active' => $student->is_active,
                    'avatar_url' => $student->avatar_url,
                    'credential' => $currentCredential ? [
                        'id' => $currentCredential->id,
                        'qr_code' => $currentCredential->qr_code,
                        'status' => $currentCredential->status,
                        'issued_at' => $currentCredential->issued_at?->format('d/m/Y H:i'),
                        'invalidated_at' => $currentCredential->invalidated_at?->format('d/m/Y H:i'),
                        'is_active' => $currentCredential->status === 'active',
                        'is_blocked' => $currentCredential->status === 'blocked',
                        'is_invalidated' => $currentCredential->status === 'invalidated',
                    ] : null,
                    'history' => $student->credentials->map(fn ($c) => [
                        'id' => $c->id,
                        'qr_code' => $c->qr_code,
                        'status' => $c->status,
                        'issued_at' => $c->issued_at?->format('d/m/Y H:i'),
                        'invalidated_at' => $c->invalidated_at?->format('d/m/Y H:i'),
                    ]),
                ];
            });

        $totalActiveStudents = Student::where('institution_id', $institutionId)->where('is_active', true)->count();

        $activeCredentials = StudentCredential::where('institution_id', $institutionId)
            ->where('status', 'active')
            ->whereHas('student', fn ($q) => $q->where('is_active', true))
            ->count();

        $pendingCredentials = Student::where('institution_id', $institutionId)
            ->where('is_active', true)
            ->whereDoesntHave('credential', fn ($q) => $q->whereIn('status', ['active', 'blocked']))
            ->count();

        $blockedCredentials = StudentCredential::where('institution_id', $institutionId)
            ->where('status', 'blocked')
            ->count();

        $invalidatedCredentials = StudentCredential::where('institution_id', $institutionId)
            ->where('status', 'invalidated')
            ->count();

        return Inertia::render('attendance/Credentials', [
            'students' => $students,
            'metrics' => [
                'total_students' => $totalActiveStudents,
                'active_credentials' => $activeCredentials,
                'pending_credentials' => $pendingCredentials,
                'blocked_credentials' => $blockedCredentials,
                'invalidated_credentials' => $invalidatedCredentials,
            ],
            'filters' => [
                'search' => $search ?? '',
                'status' => $statusFilter,
            ],
        ]);
    }

    public function generate(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        if ($student->institution_id !== $user->institution_id) {
            abort(403, 'No autorizado para gestionar credenciales de esta institución.');
        }

        if (! $student->is_active) {
            return back()->withErrors([
                'student' => 'No se puede generar un carnet QR para un estudiante inactivo.',
            ]);
        }

        // Si el estudiante ya cuenta con credencial previa, realizar una renovación para invalidar la anterior y preservar historial
        if ($student->credential) {
            return $this->renew($request, $student);
        }

        $qrCode = StudentCredential::generateSecureQrCode();

        StudentCredential::create([
            'institution_id' => $student->institution_id,
            'student_id' => $student->id,
            'qr_code' => $qrCode,
            'status' => 'active',
            'issued_at' => now(),
        ]);

        return back()->with('success', "Código QR único y seguro generado exitosamente para {$student->full_name}.");
    }

    /**
     * Renueva la credencial QR del estudiante, invalidando inmediatamente la anterior y conservando el historial.
     */
    public function renew(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        if ($student->institution_id !== $user->institution_id) {
            abort(403, 'No autorizado para gestionar credenciales de esta institución.');
        }

        if (! $student->is_active) {
            return back()->withErrors([
                'student' => 'No se puede renovar la credencial de un estudiante inactivo.',
            ]);
        }

        DB::transaction(function () use ($student) {
            // Invalida inmediatamente todas las credenciales activas o bloqueadas del alumno
            StudentCredential::where('student_id', $student->id)
                ->whereIn('status', ['active', 'blocked'])
                ->update([
                    'status' => 'invalidated',
                    'invalidated_at' => now(),
                ]);

            // Emite la nueva credencial con un código QR único y seguro en PostgreSQL
            StudentCredential::create([
                'institution_id' => $student->institution_id,
                'student_id' => $student->id,
                'qr_code' => StudentCredential::generateSecureQrCode(),
                'status' => 'active',
                'issued_at' => now(),
            ]);
        });

        return back()->with('success', "Credencial QR renovada exitosamente para {$student->full_name}. El QR anterior ha sido invalidado inmediatamente conservando su historial en PostgreSQL.");
    }

    /**
     * Bloquea la credencial QR del estudiante.
     */
    public function block(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        if ($student->institution_id !== $user->institution_id) {
            abort(403, 'No autorizado.');
        }

        $credential = $student->credential;
        if (! $credential || $credential->status === 'invalidated') {
            return back()->withErrors(['credential' => 'El estudiante no cuenta con una credencial válida para bloquear.']);
        }

        $credential->update([
            'status' => 'blocked',
        ]);

        return back()->with('success', "Credencial QR bloqueada exitosamente para {$student->full_name}.");
    }

    /**
     * Desbloquea y reactiva la credencial QR del estudiante.
     */
    public function unblock(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        if ($student->institution_id !== $user->institution_id) {
            abort(403, 'No autorizado.');
        }

        $credential = $student->credential;
        if (! $credential || $credential->status === 'invalidated') {
            return back()->withErrors(['credential' => 'El estudiante no cuenta con una credencial para desbloquear.']);
        }

        $credential->update([
            'status' => 'active',
        ]);

        return back()->with('success', "Credencial QR desbloqueada y reactivada para {$student->full_name}.");
    }

    public function toggleStatus(Request $request, Student $student): RedirectResponse
    {
        $credential = $student->credential;
        if (! $credential || $credential->status === 'invalidated') {
            return back()->withErrors(['credential' => 'El estudiante no cuenta con una credencial emitida.']);
        }

        return $credential->status === 'active'
            ? $this->block($request, $student)
            : $this->unblock($request, $student);
    }

    public function generateBatch(Request $request): RedirectResponse
    {
        $user = $request->user();
        $institutionId = $user->institution_id;

        $activeStudents = Student::query()
            ->where('institution_id', $institutionId)
            ->where('is_active', true)
            ->get();

        $count = 0;
        foreach ($activeStudents as $student) {
            $hasValidCredential = StudentCredential::where('student_id', $student->id)
                ->whereIn('status', ['active', 'blocked'])
                ->exists();

            if (! $hasValidCredential || $request->boolean('regenerate_all')) {
                if ($hasValidCredential) {
                    StudentCredential::where('student_id', $student->id)
                        ->whereIn('status', ['active', 'blocked'])
                        ->update([
                            'status' => 'invalidated',
                            'invalidated_at' => now(),
                        ]);
                }

                StudentCredential::create([
                    'institution_id' => $student->institution_id,
                    'student_id' => $student->id,
                    'qr_code' => StudentCredential::generateSecureQrCode(),
                    'status' => 'active',
                    'issued_at' => now(),
                ]);
                $count++;
            }
        }

        return back()->with('success', "Se procesaron {$count} credenciales QR únicas y seguras en PostgreSQL.");
    }
}
