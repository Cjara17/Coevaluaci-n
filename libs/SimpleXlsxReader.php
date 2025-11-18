<?php

/**
 * Lector sencillo de archivos XLSX enfocado en extraer las filas de la primera hoja.
 * No depende de Composer y solo requiere la extensión ZipArchive habilitada.
 */
class SimpleXlsxReader
{
    /**
     * Devuelve un arreglo de filas (cada fila es un arreglo indexado de celdas).
     *
     * @throws RuntimeException si el archivo no es válido o no se puede leer.
     */
    public static function rows(string $filepath): array
    {
        if (!is_file($filepath)) {
            throw new RuntimeException('El archivo XLSX no existe.');
        }

        if (!extension_loaded('zip')) {
            throw new RuntimeException('La extensión ZipArchive no está habilitada en el servidor.');
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo XLSX.');
        }

        try {
            $sharedStrings = self::parseSharedStrings($zip);
            $worksheetPath = self::firstWorksheetPath($zip);
            $sheetXml = $zip->getFromName($worksheetPath);
            if ($sheetXml === false) {
                throw new RuntimeException('No se pudo leer la hoja principal del archivo XLSX.');
            }
            return self::parseSheetData($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    private static function parseSharedStrings(ZipArchive $zip): array
    {
        $shared = [];
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return $shared;
        }

        $doc = simplexml_load_string($xml);
        if (!$doc) {
            return $shared;
        }

        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
                continue;
            }

            if (isset($si->r)) {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $shared[] = $text;
                continue;
            }

            $shared[] = '';
        }

        return $shared;
    }

    private static function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) {
            throw new RuntimeException('El archivo XLSX no contiene workbook.xml.');
        }

        $workbook = simplexml_load_string($workbookXml);
        if (!$workbook || !isset($workbook->sheets->sheet[0])) {
            throw new RuntimeException('El libro de Excel no contiene hojas.');
        }

        $sheet = $workbook->sheets->sheet[0];
        $sheetIdAttr = $sheet->attributes('r', true);
        $sheetId = $sheetIdAttr ? (string) $sheetIdAttr['id'] : '';

        if ($sheetId === '') {
            throw new RuntimeException('No se pudo determinar la hoja principal del libro.');
        }

        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml === false) {
            throw new RuntimeException('El archivo XLSX carece de relaciones del workbook.');
        }

        $rels = simplexml_load_string($relsXml);
        if (!$rels) {
            throw new RuntimeException('No se pudieron leer las relaciones del workbook.');
        }

        $target = null;
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $sheetId) {
                $target = (string) $rel['Target'];
                break;
            }
        }

        if ($target === null) {
            throw new RuntimeException('No se encontró la hoja asociada al libro.');
        }

        $target = preg_replace('/^\.\.\//', '', $target);
        return 'xl/' . ltrim($target, '/');
    }

    private static function parseSheetData(string $sheetXml, array $sharedStrings): array
    {
        $sheet = simplexml_load_string($sheetXml);
        if (!$sheet || !isset($sheet->sheetData)) {
            return [];
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $current = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $letters = preg_replace('/\d+/', '', $ref);
                $columnIndex = self::columnIndex($letters);
                $current[$columnIndex] = self::cellValue($cell, $sharedStrings);
            }

            if (!empty($current)) {
                $maxIndex = max(array_keys($current));
                $rowData = array_fill(0, $maxIndex + 1, '');
                foreach ($current as $idx => $value) {
                    $rowData[$idx] = $value;
                }
                $rows[] = $rowData;
            } else {
                $rows[] = [];
            }
        }

        return $rows;
    }

    private static function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }

    private static function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            $key = isset($cell->v) ? (int) $cell->v : null;
            return $key !== null && isset($sharedStrings[$key]) ? $sharedStrings[$key] : '';
        }

        if ($type === 'inlineStr') {
            if (isset($cell->is->t)) {
                return (string) $cell->is->t;
            }

            $text = '';
            foreach ($cell->is->t as $inline) {
                $text .= (string) $inline;
            }
            return $text;
        }

        if ($type === 'b') {
            return (isset($cell->v) && (string) $cell->v === '1') ? 'TRUE' : 'FALSE';
        }

        if (!isset($cell->v)) {
            return '';
        }

        return (string) $cell->v;
    }
}

