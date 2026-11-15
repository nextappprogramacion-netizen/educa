<?php

namespace App\Services;

use ZipArchive;

class AttendanceExcelService
{
    /**
     * Genera un archivo Excel (.xlsx) válido en formato OpenXML a partir de los registros de asistencia.
     *
     * @param  array<string, mixed>  $institution
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $records
     * @return string Contenido binario del archivo .xlsx
     */
    public function generate(array $institution, array $filters, array $summary, array $records): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'att_xlsx_');
        $zip = new ZipArchive;
        $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/styles.xml
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="4">'
            .'<font><sz val="10"/><name val="Arial"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FF1E3A8A"/><name val="Arial"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FF1F2937"/><name val="Arial"/></font>'
            .'</fonts>'
            .'<fills count="5">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1E40AF"/></patternFill></fill>' // Cabecera tabla (Azul)
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/></patternFill></fill>' // Gris claro
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE0E7FF"/></patternFill></fill>' // Azul suave
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FFD1D5DB"/></left>'
            .'<right style="thin"><color rgb="FFD1D5DB"/></right>'
            .'<top style="thin"><color rgb="FFD1D5DB"/></top>'
            .'<bottom style="thin"><color rgb="FFD1D5DB"/></bottom>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="5">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>' // 0: Normal
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>' // 1: Título
            .'<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>' // 2: Header tabla
            .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>' // 3: Subtítulos
            .'<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>' // 4: Resumen
            .'</cellXfs>'
            .'</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // 5. xl/workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Reporte Asistencia" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // 6. xl/worksheets/sheet1.xml
        $sheetXml = $this->buildSheetXml($institution, $filters, $summary, $records);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();

        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        return $content ?: '';
    }

    /**
     * Construye el XML de la hoja de cálculo con encabezados, filtros, resumen y detalle.
     *
     * @param  array<string, mixed>  $institution
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $records
     */
    private function buildSheetXml(array $institution, array $filters, array $summary, array $records): string
    {
        $rows = [];
        $rowIdx = 1;

        // Título del Reporte
        $instName = $institution['name'] ?? 'Institución Educativa';
        $rows[] = $this->createRow($rowIdx++, [
            ['col' => 'A', 'val' => "REPORTE OFICIAL DE ASISTENCIA ESCOLAR - {$instName}", 'style' => 1],
        ]);

        // Fechas de consulta y filtros
        $dateInfo = 'Periodo: '.($filters['start_date'] ?? 'Todos').' al '.($filters['end_date'] ?? 'Todos');
        $gradeInfo = 'Grado: '.($filters['grade_name'] ?? 'Todos');
        $sectionInfo = 'Sección: '.($filters['section_name'] ?? 'Todas');
        $studentInfo = 'Filtro Estudiante: '.($filters['student_name'] ?? 'Todos');

        $rows[] = $this->createRow($rowIdx++, [
            ['col' => 'A', 'val' => $dateInfo, 'style' => 3],
            ['col' => 'C', 'val' => $gradeInfo, 'style' => 3],
            ['col' => 'E', 'val' => $sectionInfo, 'style' => 3],
            ['col' => 'G', 'val' => $studentInfo, 'style' => 3],
        ]);

        $rowIdx++; // Espacio en blanco

        // Bloque de Resumen Cuantitativo
        $rows[] = $this->createRow($rowIdx++, [
            ['col' => 'A', 'val' => 'RESUMEN GENERAL', 'style' => 4],
            ['col' => 'B', 'val' => 'TOTAL EVALUADOS: '.($summary['total_evaluated'] ?? 0), 'style' => 4],
            ['col' => 'D', 'val' => 'PUNTUALES: '.($summary['total_punctual'] ?? 0), 'style' => 4],
            ['col' => 'F', 'val' => 'TARDANZAS: '.($summary['total_late'] ?? 0), 'style' => 4],
            ['col' => 'H', 'val' => 'AUSENCIAS: '.($summary['total_absent'] ?? 0), 'style' => 4],
            ['col' => 'J', 'val' => 'SALIDAS: '.($summary['total_exits'] ?? 0), 'style' => 4],
            ['col' => 'L', 'val' => 'ANTICIPADAS: '.($summary['total_early_exits'] ?? 0), 'style' => 4],
        ]);

        $rowIdx++; // Espacio en blanco

        // Cabeceras de la Tabla de Datos
        $headers = [
            'A' => 'N°',
            'B' => 'Fecha',
            'C' => 'Código',
            'D' => 'DNI / Doc',
            'E' => 'Apellidos y Nombres',
            'F' => 'Grado y Sección',
            'G' => 'Turno',
            'H' => 'Hora Ingreso',
            'I' => 'Puntualidad / Estado',
            'J' => 'Hora Salida',
            'K' => 'Salida Anticipada',
            'L' => 'Motivo / Justificación / Observación',
        ];

        $headerCells = [];
        foreach ($headers as $col => $headerTitle) {
            $headerCells[] = ['col' => $col, 'val' => $headerTitle, 'style' => 2];
        }
        $rows[] = $this->createRow($rowIdx++, $headerCells);

        // Filas de Detalle
        $itemNum = 1;
        foreach ($records as $r) {
            $earlyExitText = ! empty($r['is_early_exit'])
                ? 'SÍ'.(! empty($r['early_exit_reason']) ? ' ('.$r['early_exit_reason'].')' : '')
                : 'No';

            $obs = $r['observation'] ?? '';
            if (! empty($r['is_justified'])) {
                $obs = trim($obs.' [Justificado]');
            }

            $rows[] = $this->createRow($rowIdx++, [
                ['col' => 'A', 'val' => (string) $itemNum++, 'style' => 0],
                ['col' => 'B', 'val' => (string) ($r['date'] ?? ''), 'style' => 0],
                ['col' => 'C', 'val' => (string) ($r['student_code'] ?? ''), 'style' => 0],
                ['col' => 'D', 'val' => (string) ($r['student_dni'] ?? ''), 'style' => 0],
                ['col' => 'E', 'val' => (string) ($r['student_name'] ?? ''), 'style' => 0],
                ['col' => 'F', 'val' => (string) ($r['classroom'] ?? ''), 'style' => 0],
                ['col' => 'G', 'val' => (string) ($r['shift'] ?? ''), 'style' => 0],
                ['col' => 'H', 'val' => (string) ($r['entry_time'] ?? '—'), 'style' => 0],
                ['col' => 'I', 'val' => (string) ($r['status_label'] ?? ''), 'style' => 0],
                ['col' => 'J', 'val' => (string) ($r['exit_time'] ?? '—'), 'style' => 0],
                ['col' => 'K', 'val' => $earlyExitText, 'style' => 0],
                ['col' => 'L', 'val' => $obs, 'style' => 0],
            ]);
        }

        // Anchos de columna
        $cols = '<cols>'
            .'<col min="1" max="1" width="6" customWidth="1"/>'
            .'<col min="2" max="2" width="12" customWidth="1"/>'
            .'<col min="3" max="3" width="14" customWidth="1"/>'
            .'<col min="4" max="4" width="14" customWidth="1"/>'
            .'<col min="5" max="5" width="30" customWidth="1"/>'
            .'<col min="6" max="6" width="22" customWidth="1"/>'
            .'<col min="7" max="7" width="12" customWidth="1"/>'
            .'<col min="8" max="8" width="14" customWidth="1"/>'
            .'<col min="9" max="9" width="18" customWidth="1"/>'
            .'<col min="10" max="10" width="14" customWidth="1"/>'
            .'<col min="11" max="11" width="20" customWidth="1"/>'
            .'<col min="12" max="12" width="35" customWidth="1"/>'
            .'</cols>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$cols
            .'<sheetData>'
            .implode("\n", $rows)
            .'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  array<int, array{col: string, val: string, style?: int}>  $cells
     */
    private function createRow(int $rowNum, array $cells): string
    {
        $cXml = [];
        foreach ($cells as $cell) {
            $col = $cell['col'];
            $val = htmlspecialchars($cell['val'], ENT_XML1, 'UTF-8');
            $styleAttr = isset($cell['style']) ? ' s="'.$cell['style'].'"' : '';
            $cXml[] = '<c r="'.$col.$rowNum.'" t="inlineStr"'.$styleAttr.'><is><t>'.$val.'</t></is></c>';
        }

        return '<row r="'.$rowNum.'">'.implode('', $cXml).'</row>';
    }
}
