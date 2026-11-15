<?php

namespace App\Services;

class AttendancePdfService
{
    /**
     * Ancho de página horizontal (Letter landscape)
     */
    private float $pageWidth = 792.0;

    /**
     * Alto de página horizontal
     */
    private float $pageHeight = 612.0;

    /**
     * Genera un documento PDF 1.4 válido con los registros de asistencia.
     *
     * @param  array<string, mixed>  $institution
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $records
     * @return string Contenido binario del archivo PDF
     */
    public function generate(array $institution, array $filters, array $summary, array $records): string
    {
        // Dividir los registros en páginas
        $rowsPerPageFirst = 20;
        $rowsPerPageOther = 28;

        $pagesData = [];
        $totalRecords = count($records);

        if ($totalRecords === 0) {
            $pagesData[] = [];
        } else {
            $offset = 0;
            $isFirst = true;
            while ($offset < $totalRecords) {
                $limit = $isFirst ? $rowsPerPageFirst : $rowsPerPageOther;
                $pagesData[] = array_slice($records, $offset, $limit);
                $offset += $limit;
                $isFirst = false;
            }
        }

        $totalPages = count($pagesData);

        // Estructura de objetos PDF
        // Objeto 1: Catalog
        // Objeto 2: Pages
        // Objetos 3..2+N: Page objects
        // Objeto: Font Helvetica
        // Objeto: Font Helvetica-Bold
        // Objetos: Content streams para cada página
        $pdfObjects = [];
        $pageObjectIds = [];
        $contentObjectIds = [];

        $nextId = 3;
        for ($i = 0; $i < $totalPages; $i++) {
            $pageObjectIds[] = $nextId++;
            $contentObjectIds[] = $nextId++;
        }

        $fontRegularId = $nextId++;
        $fontBoldId = $nextId++;

        // Objeto 1: Catalog
        $pdfObjects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        // Objeto 2: Pages
        $kidsStr = implode(' 0 R ', $pageObjectIds).' 0 R';
        $pdfObjects[2] = "<< /Type /Pages /Kids [{$kidsStr}] /Count {$totalPages} >>";

        // Fuentes estándar
        $pdfObjects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $pdfObjects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        // Generar cada página y su stream de contenido
        $globalIndex = 1;
        for ($p = 0; $p < $totalPages; $p++) {
            $pageObjId = $pageObjectIds[$p];
            $contentObjId = $contentObjectIds[$p];
            $pageRecords = $pagesData[$p];
            $pageNumber = $p + 1;

            $streamContent = $this->renderPageContent(
                $pageNumber,
                $totalPages,
                $institution,
                $filters,
                $summary,
                $pageRecords,
                $globalIndex
            );

            $streamLength = strlen($streamContent);

            $pdfObjects[$pageObjId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] "
                ."/Resources << /Font << /F1 {$fontRegularId} 0 R /F2 {$fontBoldId} 0 R >> >> "
                ."/Contents {$contentObjId} 0 R >>";

            $pdfObjects[$contentObjId] = "<< /Length {$streamLength} >>\nstream\n{$streamContent}\nendstream";
        }

        // Ensamblar archivo PDF completo con tabla xref precisa
        return $this->buildPdfBinary($pdfObjects, count($pdfObjects));
    }

    /**
     * Renderiza el contenido vectorial de una página.
     *
     * @param  array<string, mixed>  $institution
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $records
     */
    private function renderPageContent(
        int $pageNumber,
        int $totalPages,
        array $institution,
        array $filters,
        array $summary,
        array $records,
        int &$globalIndex
    ): string {
        $c = [];

        // Encabezado principal (en todas las páginas)
        $instName = $institution['name'] ?? 'Institución Educativa';
        $instNameClean = $this->sanitizeText($instName);

        // Barra superior decorativa institucional
        $c[] = '0.12 0.25 0.68 rg'; // Azul institucional
        $c[] = '30 585 732 4 re f';

        // Título del reporte
        $c[] = 'BT /F2 13 Tf 0.1 0.15 0.3 rg 30 568 Td ('.$this->escapePdf('REPORTE OFICIAL DE ASISTENCIA ESCOLAR').') Tj ET';
        $c[] = 'BT /F1 9 Tf 0.3 0.35 0.4 rg 30 555 Td ('.$this->escapePdf("Institución: {$instNameClean} | Fecha de emisión: ".date('d/m/Y H:i')).') Tj ET';

        $currentY = 540;

        // Si es la primera página, mostramos los filtros aplicados y los KPIs de resumen
        if ($pageNumber === 1) {
            // Cuadro de filtros
            $c[] = '0.96 0.97 0.98 rg 30 500 732 40 re f';
            $c[] = '0.85 0.88 0.92 RG 30 500 732 40 re s';

            $filterDates = 'Rango de fechas: '.($filters['start_date'] ?? 'Todos').' al '.($filters['end_date'] ?? 'Todos');
            $filterGrade = 'Grado: '.$this->sanitizeText($filters['grade_name'] ?? 'Todos');
            $filterSection = 'Sección: '.$this->sanitizeText($filters['section_name'] ?? 'Todas');
            $filterStudent = 'Estudiante: '.$this->sanitizeText($filters['student_name'] ?? 'Todos');

            $c[] = 'BT /F2 8 Tf 0.2 0.25 0.35 rg 40 526 Td ('.$this->escapePdf('FILTROS ACTIVOS:').') Tj ET';
            $c[] = 'BT /F1 8 Tf 0.3 0.35 0.4 rg 120 526 Td ('.$this->escapePdf("{$filterDates}   |   {$filterGrade}   |   {$filterSection}   |   {$filterStudent}").') Tj ET';

            // Indicadores de resumen (mini-tarjetas)
            $totalEval = $summary['total_evaluated'] ?? 0;
            $punctual = $summary['total_punctual'] ?? 0;
            $late = $summary['total_late'] ?? 0;
            $absent = $summary['total_absent'] ?? 0;
            $exits = $summary['total_exits'] ?? 0;
            $earlyExits = $summary['total_early_exits'] ?? 0;

            $summaryText = "TOTAL EVALUADOS: {$totalEval}   |   PUNTUALES: {$punctual}   |   TARDANZAS: {$late}   |   FALTAS: {$absent}   |   SALIDAS REG.: ".($exits - $earlyExits)."   |   SALIDAS ANTICIPADAS: {$earlyExits}";
            $c[] = 'BT /F2 8 Tf 0.1 0.3 0.5 rg 40 508 Td ('.$this->escapePdf($summaryText).') Tj ET';

            $currentY = 485;
        }

        // Cabeceras de la tabla
        $tableY = $currentY;
        $rowH = 16.0;

        // Fondo de la cabecera
        $c[] = "0.15 0.23 0.42 rg 30 {$tableY} 732 {$rowH} re f";

        // Columnas: N°(25), Fecha(55), Código(55), Estudiante(155), Grado/Sec(95), Ingreso(50), Estado(72), Salida(50), Anticipada(75), Observación(100)
        $cols = [
            ['title' => 'N°', 'w' => 25, 'x' => 30],
            ['title' => 'Fecha', 'w' => 55, 'x' => 55],
            ['title' => 'Código', 'w' => 55, 'x' => 110],
            ['title' => 'Estudiante', 'w' => 155, 'x' => 165],
            ['title' => 'Grado / Sección', 'w' => 95, 'x' => 320],
            ['title' => 'Ingreso', 'w' => 50, 'x' => 415],
            ['title' => 'Puntualidad', 'w' => 72, 'x' => 465],
            ['title' => 'Salida', 'w' => 50, 'x' => 537],
            ['title' => 'Salida Anticipada', 'w' => 75, 'x' => 587],
            ['title' => 'Observación / Motivo', 'w' => 70, 'x' => 662],
        ];

        $c[] = 'BT /F2 7.5 Tf 1 1 1 rg';
        foreach ($cols as $col) {
            $textX = $col['x'] + 4;
            $textY = $tableY + 4.5;
            $c[] = "{$textX} {$textY} Td (".$this->escapePdf($col['title']).') Tj';
            $c[] = "-{$textX} -{$textY} Td"; // Regresar al origen
        }
        $c[] = 'ET';

        // Filas de datos
        $rowY = $tableY - $rowH;
        $isEven = false;

        foreach ($records as $r) {
            // Fondo alternado
            if ($isEven) {
                $c[] = "0.97 0.98 0.99 rg 30 {$rowY} 732 {$rowH} re f";
            }
            $isEven = ! $isEven;

            // Línea separadora tenue
            $c[] = "0.9 0.92 0.94 RG 30 {$rowY} 732 0.5 re s";

            $date = $r['date'] ?? '';
            $code = $r['student_code'] ?? '';
            $student = $this->sanitizeText($r['student_name'] ?? '');
            $class = $this->sanitizeText($r['classroom'] ?? '');
            $entry = $r['entry_time'] ?? '—';
            $status = $this->sanitizeText($r['status_label'] ?? '');
            $exit = $r['exit_time'] ?? '—';
            $early = ! empty($r['is_early_exit']) ? 'Sí'.(! empty($r['early_exit_reason']) ? ' ('.$this->sanitizeText($r['early_exit_reason']).')' : '') : 'No';
            $obs = $this->sanitizeText($r['observation'] ?? '');

            // Truncar textos largos para que no se desborden de sus celdas
            if (strlen($student) > 28) {
                $student = substr($student, 0, 26).'..';
            }
            if (strlen($class) > 18) {
                $class = substr($class, 0, 16).'..';
            }
            if (strlen($early) > 16) {
                $early = substr($early, 0, 14).'..';
            }
            if (strlen($obs) > 16) {
                $obs = substr($obs, 0, 14).'..';
            }

            // Color del texto de estado según puntualidad
            $statusColor = '0.2 0.25 0.3 rg';
            if (str_contains(strtolower($status), 'puntual') || str_contains(strtolower($status), 'presente')) {
                $statusColor = '0.05 0.55 0.25 rg'; // Verde
            } elseif (str_contains(strtolower($status), 'tarde') || str_contains(strtolower($status), 'tardanza')) {
                $statusColor = '0.75 0.45 0.05 rg'; // Ámbar
            } elseif (str_contains(strtolower($status), 'falta') || str_contains(strtolower($status), 'ausente')) {
                $statusColor = '0.75 0.15 0.15 rg'; // Rojo
            }

            $c[] = 'BT /F1 7 Tf 0.2 0.25 0.3 rg';
            $textY = $rowY + 4.5;

            // N°
            $c[] = "34 {$textY} Td (".$this->escapePdf((string) $globalIndex).") Tj -34 -{$textY} Td";
            // Fecha
            $c[] = "59 {$textY} Td (".$this->escapePdf($date).") Tj -59 -{$textY} Td";
            // Código
            $c[] = "114 {$textY} Td (".$this->escapePdf($code).") Tj -114 -{$textY} Td";
            // Estudiante
            $c[] = "169 {$textY} Td (".$this->escapePdf($student).") Tj -169 -{$textY} Td";
            // Grado / Sección
            $c[] = "324 {$textY} Td (".$this->escapePdf($class).") Tj -324 -{$textY} Td";
            // Ingreso
            $c[] = "419 {$textY} Td (".$this->escapePdf($entry).") Tj -419 -{$textY} Td";
            $c[] = 'ET';

            // Estado (con su color)
            $c[] = "BT /F2 7 Tf {$statusColor}";
            $c[] = "469 {$textY} Td (".$this->escapePdf($status).") Tj -469 -{$textY} Td";
            $c[] = 'ET';

            // Salida, Anticipada y Observación
            $earlyColor = ! empty($r['is_early_exit']) ? '0.8 0.3 0.1 rg' : '0.2 0.25 0.3 rg';
            $c[] = 'BT /F1 7 Tf 0.2 0.25 0.3 rg';
            $c[] = "541 {$textY} Td (".$this->escapePdf($exit).") Tj -541 -{$textY} Td";
            $c[] = 'ET';

            $c[] = "BT /F1 7 Tf {$earlyColor}";
            $c[] = "591 {$textY} Td (".$this->escapePdf($early).") Tj -591 -{$textY} Td";
            $c[] = 'ET';

            $c[] = 'BT /F1 7 Tf 0.4 0.45 0.5 rg';
            $c[] = "666 {$textY} Td (".$this->escapePdf($obs).") Tj -666 -{$textY} Td";
            $c[] = 'ET';

            $rowY -= $rowH;
            $globalIndex++;
        }

        // Borde exterior de la tabla
        $tableTotalH = $tableY - $rowY;
        $c[] = "0.7 0.75 0.8 RG 30 {$rowY} 732 {$tableTotalH} re s";

        // Pie de página (Paginación y aviso oficial)
        $c[] = '0.8 0.84 0.9 RG 30 25 732 0.5 re s';
        $c[] = 'BT /F1 8 Tf 0.4 0.45 0.5 rg 30 15 Td ('.$this->escapePdf('Sistema Integrado de Asistencia Escolar - Documento Oficial de Consulta').') Tj ET';
        $c[] = 'BT /F2 8 Tf 0.2 0.25 0.3 rg 680 15 Td ('.$this->escapePdf("Página {$pageNumber} de {$totalPages}").') Tj ET';

        return implode("\n", $c);
    }

    /**
     * Construye el documento PDF final calculando con precisión la tabla xref.
     *
     * @param  array<int, string>  $objects
     */
    private function buildPdfBinary(array $objects, int $maxObjId): string
    {
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        for ($id = 1; $id <= $maxObjId; $id++) {
            if (! isset($objects[$id])) {
                continue;
            }
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n".$objects[$id]."\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $totalCount = $maxObjId + 1;

        $out .= "xref\n";
        $out .= "0 {$totalCount}\n";
        $out .= "0000000000 65535 f \n";

        for ($id = 1; $id <= $maxObjId; $id++) {
            $offset = $offsets[$id] ?? 0;
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        $out .= "trailer\n";
        $out .= "<< /Size {$totalCount} /Root 1 0 R >>\n";
        $out .= "startxref\n";
        $out .= "{$xrefOffset}\n";
        $out .= "%%EOF\n";

        return $out;
    }

    /**
     * Limpia caracteres fuera de WinAnsi / ASCII y convierte tildes y ñ.
     */
    private function sanitizeText(string $text): string
    {
        // Reemplazo de caracteres especiales comunes en español a ASCII para asegurar compatibilidad universal
        $replacements = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U', '°' => ' ',
        ];

        return strtr($text, $replacements);
    }

    /**
     * Escapa caracteres especiales en cadenas literales de PDF.
     */
    private function escapePdf(string $text): string
    {
        return str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $text
        );
    }
}
