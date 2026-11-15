<?php

namespace App\Http\Requests\Attendance;

use App\Models\Institution;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $student = $this->route('student');
        if ($student instanceof Student) {
            return (int) $student->institution_id === (int) $this->user()->institution_id;
        }

        return true;
    }

    public function rules(): array
    {
        $institutionId = $this->user()?->institution_id;
        if (! $institutionId) {
            $defaultInstitution = Institution::where('is_active', true)->first();
            if ($defaultInstitution) {
                $institutionId = $defaultInstitution->id;
                $this->user()?->forceFill(['institution_id' => $institutionId])->save();
            }
        }

        $studentId = $this->route('student') instanceof Student
            ? $this->route('student')->id
            : $this->route('student');

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'document_number' => [
                'required',
                'string',
                'regex:/^[0-9]{8}$/',
                Rule::unique('students')
                    ->where(fn ($q) => $q->where('institution_id', $institutionId))
                    ->ignore($studentId),
            ],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('students')
                    ->where(fn ($q) => $q->where('institution_id', $institutionId))
                    ->ignore($studentId),
            ],
            'gender' => ['nullable', 'string', 'in:M,F'],
            'birth_date' => ['nullable', 'date'],
            'section_id' => [
                'required',
                'integer',
                Rule::exists('sections', 'id')->where(fn ($q) => $q->where('institution_id', $institutionId)),
            ],
            'photo_url' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre del estudiante es obligatorio.',
            'last_name.required' => 'Los apellidos del estudiante son obligatorios.',
            'document_number.required' => 'El DNI es obligatorio.',
            'document_number.regex' => 'El DNI debe tener exactamente 8 dígitos numéricos.',
            'document_number.unique' => 'Este DNI ya pertenece a otro estudiante en la institución.',
            'code.unique' => 'Este código de estudiante ya pertenece a otro alumno en la institución.',
            'section_id.required' => 'Debe seleccionar un grado y sección válidos.',
        ];
    }
}
