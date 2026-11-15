<?php

namespace App\Http\Requests\Attendance;

use App\Models\Institution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'document_number' => [
                'required',
                'string',
                'regex:/^[0-9]{8}$/',
                Rule::unique('students')->where(fn ($q) => $q->where('institution_id', $institutionId)),
            ],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('students')->where(fn ($q) => $q->where('institution_id', $institutionId)),
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
            'document_number.unique' => 'Ya existe un estudiante registrado con este DNI en la institución.',
            'code.unique' => 'Ya existe un estudiante registrado con este código en la institución.',
            'section_id.required' => 'Debe seleccionar un grado y sección válidos.',
            'section_id.exists' => 'La sección seleccionada no existe o no pertenece a su institución.',
        ];
    }
}
