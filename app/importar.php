<?php
declare(strict_types=1);

/*
 * Lectura de planillas para importar datos (xlsx o csv), sin librerías externas.
 * Solo se lee la primera hoja. Los archivos .xlsx protegidos con contraseña
 * están cifrados y no se pueden leer: hay que guardar una copia sin contraseña.
 */

const IMPORTAR_MAX_FILAS = 3000;

/**
 * @return array<int, array<int, string>> filas (la primera no vacía es el encabezado)
 * @throws RuntimeException con un mensaje para el usuario
 */
function leer_planilla(string $ruta, string $nombreOriginal, ?string $hoja = null): array
{
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    $inicio = (string)file_get_contents($ruta, false, null, 0, 8);
    if (strncmp($inicio, "\xD0\xCF\x11\xE0", 4) === 0) {
        throw new RuntimeException('El archivo está protegido con contraseña (o es un .xls antiguo). '
            . 'Ábralo en Excel y guarde una copia sin contraseña en formato .xlsx o .csv.');
    }
    if ($ext === 'xlsx' || $ext === 'xlsm') {
        $filas = leer_xlsx($ruta, $hoja);
    } elseif ($ext === 'csv' || $ext === 'txt') {
        $filas = leer_csv($ruta);
    } else {
        throw new RuntimeException('Formato no soportado. Use .xlsx o .csv.');
    }
    // Quitar filas vacías y recortar
    $filas = array_values(array_filter(array_map(
        static fn($f) => array_map(static fn($v) => trim((string)$v), $f),
        $filas
    ), static fn($f) => implode('', $f) !== ''));
    if (count($filas) < 2) {
        throw new RuntimeException('La planilla no tiene filas con datos.');
    }
    if (count($filas) > IMPORTAR_MAX_FILAS + 1) {
        throw new RuntimeException('La planilla tiene más de ' . IMPORTAR_MAX_FILAS . ' filas; divídala en partes.');
    }
    // Todas las filas con el mismo número de columnas que la más ancha
    $ancho = max(array_map('count', $filas));
    return array_map(static fn($f) => array_pad($f, $ancho, ''), $filas);
}

function leer_csv(string $ruta): array
{
    $texto = (string)file_get_contents($ruta);
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }
    $primera = strtok($texto, "\n") ?: '';
    $separador = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
    if (substr_count($primera, "\t") > substr_count($primera, $separador)) {
        $separador = "\t";
    }
    $h = fopen('php://memory', 'r+');
    fwrite($h, $texto);
    rewind($h);
    $filas = [];
    while (($f = fgetcsv($h, 0, $separador, '"', '')) !== false) {
        $filas[] = $f;
    }
    fclose($h);
    return $filas;
}

/** Nombres de las hojas de un .xlsx, en orden. */
function hojas_xlsx(string $ruta): array
{
    return array_keys(xlsx_rutas_hojas(xlsx_abrir($ruta)));
}

function xlsx_abrir(string $ruta): ZipArchive
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene la extensión zip de PHP; guarde la planilla como .csv.');
    }
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo .xlsx (¿está dañado o protegido?).');
    }
    return $zip;
}

/** @return array<string, string> nombre de hoja => ruta interna del XML */
function xlsx_rutas_hojas(ZipArchive $zip): array
{
    $libro = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($libro === false || $rels === false) {
        return ['Hoja1' => 'xl/worksheets/sheet1.xml'];
    }
    $destinos = [];
    $rl = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
    foreach ($rl->Relationship as $rel) {
        $destino = ltrim((string)$rel['Target'], '/');
        $destinos[(string)$rel['Id']] = strpos($destino, 'xl/') === 0 ? $destino : 'xl/' . $destino;
    }
    $hojas = [];
    $wb = simplexml_load_string($libro, 'SimpleXMLElement', LIBXML_NONET);
    foreach ($wb->sheets->sheet ?? [] as $s) {
        $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        if (isset($destinos[$rid])) {
            $hojas[(string)$s['name']] = $destinos[$rid];
        }
    }
    return $hojas ?: ['Hoja1' => 'xl/worksheets/sheet1.xml'];
}

/** Lee una hoja (por nombre) o la primera. */
function leer_xlsx(string $ruta, ?string $nombreHoja = null): array
{
    $zip = xlsx_abrir($ruta);

    // Textos compartidos
    $textos = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml !== false) {
        $sst = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($sst->si ?? [] as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            }
            foreach ($si->r ?? [] as $r) {
                $t .= (string)$r->t;
            }
            $textos[] = $t;
        }
    }

    // La hoja pedida, o la primera del libro (no siempre es sheet1.xml)
    $hojas = xlsx_rutas_hojas($zip);
    if ($nombreHoja !== null && !isset($hojas[$nombreHoja])) {
        $zip->close();
        throw new RuntimeException("La planilla no tiene una hoja llamada \"$nombreHoja\".");
    }
    $xml = $zip->getFromName($nombreHoja !== null ? $hojas[$nombreHoja] : reset($hojas));
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('No se encontró la primera hoja del libro.');
    }

    $sheet = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    $filas = [];
    foreach ($sheet->sheetData->row ?? [] as $row) {
        $fila = [];
        foreach ($row->c as $c) {
            $col = columna_indice(preg_replace('/\d+/', '', (string)$c['r']));
            $tipo = (string)$c['t'];
            if ($tipo === 's') {
                $v = $textos[(int)$c->v] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $v = (string)($c->is->t ?? '');
            } elseif ($tipo === 'b') {
                $v = (string)$c->v === '1' ? 'VERDADERO' : 'FALSO';
            } else {
                $v = (string)$c->v;
                // Números enteros guardados como 1651.0 o notación científica
                if ($v !== '' && is_numeric($v) && (float)$v == floor((float)$v) && abs((float)$v) < 1e15) {
                    $v = number_format((float)$v, 0, '', '');
                }
            }
            $fila[$col] = $v;
        }
        if ($fila) {
            $max = max(array_keys($fila));
            $filas[] = array_replace(array_fill(0, $max + 1, ''), $fila);
        }
    }
    return $filas;
}

/** "A" → 0, "Z" → 25, "AA" → 26 */
function columna_indice(string $letras): int
{
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $l) {
        $n = $n * 26 + (ord($l) - 64);
    }
    return $n - 1;
}

function columna_letra(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}
