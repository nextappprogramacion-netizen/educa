<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $institution = Institution::firstOrCreate(
            ['code' => 'SMP-001'],
            [
                'name' => 'I.E. San Martín de Porres',
                'document_number' => '20485963214',
                'email' => 'contacto@sanmartin.edu.pe',
                'phone' => '+51 987 654 321',
                'address' => 'Av. Los Álamos 450, Lima, Perú',
                'timezone' => 'America/Lima',
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'admin@educa.pe'],
            [
                'name' => 'Director San Martín',
                'password' => Hash::make('password'),
                'institution_id' => $institution->id,
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $academicYear = AcademicYear::firstOrCreate(
            [
                'institution_id' => $institution->id,
                'year' => (int) date('Y'),
            ],
            [
                'name' => 'Año Escolar '.date('Y'),
                'is_active' => true,
            ]
        );

        // Grados por Nivel
        $gradesData = [
            ['name' => 'Sala 5 Años', 'level' => 'Inicial', 'order_index' => 1],
            ['name' => '1° Primaria', 'level' => 'Primaria', 'order_index' => 2],
            ['name' => '2° Primaria', 'level' => 'Primaria', 'order_index' => 3],
            ['name' => '1° Secundaria', 'level' => 'Secundaria', 'order_index' => 4],
        ];

        $grades = [];
        foreach ($gradesData as $gData) {
            $grades[$gData['name']] = Grade::firstOrCreate(
                [
                    'institution_id' => $institution->id,
                    'name' => $gData['name'],
                ],
                [
                    'level' => $gData['level'],
                    'order_index' => $gData['order_index'],
                ]
            );
        }

        // Secciones
        $sections = [
            '1A_M' => Section::firstOrCreate(
                ['institution_id' => $institution->id, 'grade_id' => $grades['1° Primaria']->id, 'name' => 'A', 'shift' => 'Mañana']
            ),
            '1B_T' => Section::firstOrCreate(
                ['institution_id' => $institution->id, 'grade_id' => $grades['1° Primaria']->id, 'name' => 'B', 'shift' => 'Tarde']
            ),
            '2A_M' => Section::firstOrCreate(
                ['institution_id' => $institution->id, 'grade_id' => $grades['2° Primaria']->id, 'name' => 'A', 'shift' => 'Mañana']
            ),
            'SEC1A_M' => Section::firstOrCreate(
                ['institution_id' => $institution->id, 'grade_id' => $grades['1° Secundaria']->id, 'name' => 'A', 'shift' => 'Mañana']
            ),
        ];

        // Los estudiantes deben registrarse de forma real desde el sistema
    }
}
