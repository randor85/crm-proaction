<?php
declare(strict_types=1);

/*
 * Correos entrantes por IMAP.
 * Un cron del servidor (cron_correos.php) lee la bandeja de entrada de los buzones configurados en
 * Sistema → Correos y guarda un extracto de cada correo nuevo en correos_entrantes. Una rutina de Claude
 * los lee por api_correos.php, los clasifica y devuelve propuestas a la Bandeja de correos, donde una
 * persona las aprueba. Las claves de los buzones se guardan cifradas con la misma llave de las credenciales.
 *
 * El cliente IMAP es propio (sockets + TLS) para no depender de la extensión imap de PHP.
 * Solo lee: usa BODY.PEEK, así que no marca los correos como leídos ni los mueve.
 */

const CORREOS_DIAS_RETENCION = 14;     // los extractos se borran pasado este plazo
const CORREOS_MAX_CUERPO = 1500;       // caracteres del cuerpo que se guardan
const CORREOS_MAX_POR_LECTURA = 200;   // correos por buzón en cada lectura
const CORREOS_DIAS_PRIMERA = 2;        // la primera lectura de un buzón trae solo los últimos días

/* ================================================================
 * Ajustes simples guardados en la base (token de la rutina, servidor IMAP)
 * ================================================================ */

function ajuste(string $clave, ?string $defecto = null): ?string
{
    try {
        $v = q_valor('SELECT valor FROM ajustes WHERE clave = ?', [$clave]);
    } catch (PDOException $ex) {
        return $defecto;
    }
    return $v === false || $v === null ? $defecto : (string)$v;
}

function ajuste_guardar(string $clave, ?string $valor): void
{
    if (q_valor('SELECT 1 FROM ajustes WHERE clave = ?', [$clave])) {
        q('UPDATE ajustes SET valor = ?, actualizado_en = ? WHERE clave = ?', [$valor, ahora(), $clave]);
    } else {
        insertar('ajustes', ['clave' => $clave, 'valor' => $valor, 'actualizado_en' => ahora()]);
    }
}

function correos_servidor(): array
{
    return [
        'host'   => ajuste('imap_servidor', 'localhost'),
        'puerto' => (int)ajuste('imap_puerto', '993'),
    ];
}

/** Correos por clasificar (para Sistema y la Bandeja). 0 si la tabla aún no existe. */
function correos_por_clasificar(): int
{
    try {
        return (int)q_valor("SELECT COUNT(*) FROM correos_entrantes WHERE estado = 'nuevo'");
    } catch (PDOException $ex) {
        return 0;
    }
}

/* ================================================================
 * Cliente IMAP mínimo (RFC 3501) sobre TLS
 * ================================================================ */

final class ImapCliente
{
    /** @var resource|null */
    private $flujo = null;
    private int $n = 0;

    public function __construct(private string $host, private int $puerto, private int $espera = 25)
    {
    }

    public function conectar(): void
    {
        // En el mismo servidor el certificado no es de "localhost": solo ahí se omite la verificación.
        $local = in_array(strtolower($this->host), ['localhost', '127.0.0.1', '::1'], true);
        $contexto = stream_context_create(['ssl' => [
            'verify_peer' => !$local, 'verify_peer_name' => !$local, 'SNI_enabled' => true,
        ]]);
        $flujo = @stream_socket_client("ssl://{$this->host}:{$this->puerto}", $errno, $error, $this->espera,
            STREAM_CLIENT_CONNECT, $contexto);
        if (!$flujo) {
            throw new RuntimeException("No se pudo conectar a {$this->host}:{$this->puerto} ($error).");
        }
        stream_set_timeout($flujo, $this->espera);
        $this->flujo = $flujo;
        $saludo = $this->linea();
        if (strpos($saludo, '* OK') !== 0) {
            throw new RuntimeException('El servidor IMAP no respondió como se esperaba.');
        }
    }

    /** Ingreso con AUTHENTICATE PLAIN (evita problemas de comillas en la clave). */
    public function ingresar(string $usuario, string $clave): void
    {
        $tag = $this->tag();
        $this->escribir("$tag AUTHENTICATE PLAIN\r\n");
        $linea = $this->linea();
        if (strpos($linea, '+') !== 0) {
            throw new RuntimeException('El servidor no acepta AUTHENTICATE PLAIN.');
        }
        $this->escribir(base64_encode("\0$usuario\0$clave") . "\r\n");
        [$estado] = $this->leerHasta($tag);
        if ($estado !== 'OK') {
            throw new RuntimeException('Usuario o clave del buzón rechazados por el servidor.');
        }
    }

    /** @return int UIDVALIDITY de la carpeta */
    public function seleccionar(string $carpeta = 'INBOX'): int
    {
        [$estado, $lineas] = $this->comando('EXAMINE ' . $this->cadena($carpeta)); // EXAMINE = solo lectura
        if ($estado !== 'OK') {
            throw new RuntimeException("No se pudo abrir la carpeta $carpeta.");
        }
        foreach ($lineas as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    /** @return int[] UIDs que cumplen el criterio (p. ej. "UID 120:*" o "SINCE 05-Oct-2026") */
    public function buscarUids(string $criterio): array
    {
        [$estado, $lineas] = $this->comando("UID SEARCH $criterio");
        if ($estado !== 'OK') {
            throw new RuntimeException('La búsqueda en el buzón falló.');
        }
        $uids = [];
        foreach ($lineas as $l) {
            if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                    if (ctype_digit($u)) {
                        $uids[] = (int)$u;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** Mensaje crudo (hasta $maxBytes) y su fecha de llegada. @return array{0: string, 1: ?string} */
    public function traer(int $uid, int $maxBytes = 150000): array
    {
        [$estado, $lineas] = $this->comando("UID FETCH $uid (INTERNALDATE BODY.PEEK[]<0.$maxBytes>)");
        if ($estado !== 'OK') {
            throw new RuntimeException("No se pudo leer el correo $uid.");
        }
        $crudo = '';
        $llegada = null;
        foreach ($lineas as $l) {
            if (preg_match('/INTERNALDATE "([^"]+)"/', $l, $m)) {
                $llegada = $m[1];
            }
            if (preg_match('/BODY\[\]/', $l) && ($p = strpos($l, "\x00LITERAL\x00")) !== false) {
                $crudo = substr($l, $p + 9);
                $fin = strrpos($crudo, "\x00FIN\x00");
                $crudo = $fin === false ? $crudo : substr($crudo, 0, $fin);
            }
        }
        return [$crudo, $llegada];
    }

    public function salir(): void
    {
        if ($this->flujo) {
            try {
                $this->comando('LOGOUT');
            } catch (Throwable $ex) {
                // el servidor puede cerrar antes de responder
            }
            fclose($this->flujo);
            $this->flujo = null;
        }
    }

    /* ---------- protocolo ---------- */

    private function tag(): string
    {
        return 'p' . (++$this->n);
    }

    private function cadena(string $s): string
    {
        return '"' . addcslashes($s, "\\\"") . '"';
    }

    /** @return array{0: string, 1: string[]} */
    private function comando(string $cmd): array
    {
        $tag = $this->tag();
        $this->escribir("$tag $cmd\r\n");
        return $this->leerHasta($tag);
    }

    /**
     * Lee respuestas hasta la línea con la etiqueta. Un literal {n} se lee completo y queda dentro de la
     * misma línea lógica, marcado con \0LITERAL\0 para separarlo del encabezado de la respuesta.
     * @return array{0: string, 1: string[]}
     */
    private function leerHasta(string $tag): array
    {
        $lineas = [];
        while (true) {
            $linea = $this->linea();
            while (preg_match('/\{(\d+)\}$/', $linea, $m)) {
                $literal = $this->bytes((int)$m[1]);
                $linea = substr($linea, 0, -strlen($m[0])) . "\x00LITERAL\x00" . $literal . "\x00FIN\x00" . $this->linea();
            }
            if (strpos($linea, "$tag ") === 0) {
                $estado = strtoupper(strtok(substr($linea, strlen($tag) + 1), ' '));
                return [$estado, $lineas];
            }
            $lineas[] = $linea;
        }
    }

    private function linea(): string
    {
        $l = fgets($this->flujo);
        if ($l === false) {
            $meta = stream_get_meta_data($this->flujo);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'El servidor IMAP no respondió a tiempo.' : 'Se cortó la conexión IMAP.');
        }
        return rtrim($l, "\r\n");
    }

    private function bytes(int $n): string
    {
        $datos = '';
        while (strlen($datos) < $n) {
            $parte = fread($this->flujo, $n - strlen($datos));
            if ($parte === false || $parte === '') {
                throw new RuntimeException('Se cortó la conexión IMAP leyendo un correo.');
            }
            $datos .= $parte;
        }
        return $datos;
    }

    private function escribir(string $datos): void
    {
        if (@fwrite($this->flujo, $datos) === false) {
            throw new RuntimeException('No se pudo escribir en la conexión IMAP.');
        }
    }
}

/* ================================================================
 * Lectura de un correo (MIME)
 * ================================================================ */

/** Separa encabezados y cuerpo. @return array{0: array<string, string>, 1: string} */
function mime_partir(string $crudo): array
{
    $crudo = str_replace("\r\n", "\n", $crudo);
    $corte = strpos($crudo, "\n\n");
    $cabecera = $corte === false ? $crudo : substr($crudo, 0, $corte);
    $cuerpo = $corte === false ? '' : substr($crudo, $corte + 2);
    $cabecera = preg_replace("/\n[ \t]+/", ' ', $cabecera);
    $h = [];
    foreach (explode("\n", $cabecera) as $l) {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $l, $m)) {
            $k = strtolower($m[1]);
            $h[$k] = isset($h[$k]) ? $h[$k] . ', ' . $m[2] : $m[2];
        }
    }
    return [$h, $cuerpo];
}

/** "=?UTF-8?B?...?=" y similares → texto UTF-8. */
function mime_decodificar_encabezado(string $v): string
{
    if (strpos($v, '=?') === false) {
        return mime_utf8($v, 'UTF-8');
    }
    if (function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false) {
            return $d;
        }
    }
    return mb_decode_mimeheader($v);
}

/** Parámetro de un encabezado: charset, boundary, filename… */
function mime_parametro(string $valor, string $nombre): ?string
{
    return preg_match('/(?:^|;)\s*' . preg_quote($nombre, '/') . '\*?=\s*(?:"([^"]*)"|([^;\s]+))/i', $valor, $m)
        ? ($m[1] !== '' ? $m[1] : $m[2]) : null;
}

function mime_utf8(string $texto, ?string $charset): string
{
    $charset = strtoupper(trim((string)$charset)) ?: 'UTF-8';
    if (in_array($charset, ['UTF-8', 'US-ASCII', 'ASCII'], true) && mb_check_encoding($texto, 'UTF-8')) {
        return $texto;
    }
    if ($charset === 'UTF-8') {
        $charset = 'Windows-1252'; // dice UTF-8 pero no lo es: lo más común en correos chilenos
    }
    $alias = ['ISO-8859-1' => 'ISO-8859-1', 'LATIN1' => 'ISO-8859-1', 'WINDOWS-1252' => 'Windows-1252', 'CP1252' => 'Windows-1252', 'ISO-8859-15' => 'ISO-8859-15'];
    $desde = $alias[$charset] ?? $charset;
    try {
        $r = @mb_convert_encoding($texto, 'UTF-8', $desde);
    } catch (Throwable $ex) {
        $r = false;
    }
    return is_string($r) ? $r : mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
}

function mime_decodificar_cuerpo(string $cuerpo, ?string $codificacion): string
{
    switch (strtolower(trim((string)$codificacion))) {
        case 'base64':
            return (string)base64_decode(preg_replace('/\s+/', '', $cuerpo));
        case 'quoted-printable':
            return quoted_printable_decode($cuerpo);
        default:
            return $cuerpo;
    }
}

/** Texto legible de un HTML de correo. */
function html_a_texto(string $html): string
{
    $html = preg_replace('#<(script|style|head)\b.*?</\1>#is', ' ', $html);
    $html = preg_replace('#<(br|/p|/div|/tr|/li|/h\d)\b[^>]*>#i', "\n", $html);
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Busca el texto del correo recorriendo las partes MIME: prefiere text/plain; si no hay, usa text/html.
 * @return array{0: ?string, 1: ?string} [texto plano, html convertido]
 */
function mime_texto(array $h, string $cuerpo, int $profundidad = 0): array
{
    $tipo = strtolower($h['content-type'] ?? 'text/plain');
    $disposicion = strtolower($h['content-disposition'] ?? '');
    if ($profundidad > 6 || strpos($disposicion, 'attachment') === 0) {
        return [null, null];
    }
    if (strpos($tipo, 'multipart/') === 0) {
        $limite = mime_parametro($h['content-type'], 'boundary');
        if (!$limite) {
            return [null, null];
        }
        $plano = null;
        $html = null;
        $partes = explode('--' . $limite, $cuerpo);
        array_shift($partes); // preámbulo
        foreach ($partes as $parte) {
            if (strpos($parte, '--') === 0) {
                break; // cierre del multipart
            }
            [$hp, $cp] = mime_partir(ltrim($parte, "\r\n"));
            [$p, $x] = mime_texto($hp, $cp, $profundidad + 1);
            $plano = $plano ?? $p;
            $html = $html ?? $x;
            if ($plano !== null) {
                break;
            }
        }
        return [$plano, $html];
    }
    if (strpos($tipo, 'text/plain') === 0 || strpos($tipo, 'text/html') === 0) {
        $texto = mime_utf8(mime_decodificar_cuerpo($cuerpo, $h['content-transfer-encoding'] ?? null), mime_parametro($tipo, 'charset'));
        return strpos($tipo, 'text/html') === 0 ? [null, html_a_texto($texto)] : [$texto, null];
    }
    return [null, null];
}

/** Quita la conversación citada, firmas largas y espacios: deja lo nuevo del correo. */
function correo_limpiar_texto(string $texto): string
{
    $texto = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $texto);
    $cortes = [
        '/^\s*(El|On)\b.{0,160}\b(escribió|wrote)\s*:\s*$/mu',
        '/^\s*-{2,}\s*(Original Message|Mensaje original|Forwarded message|Mensaje reenviado)\s*-{2,}/mi',
        '/^\s*(De|From)\s*:.*\n\s*(Enviado|Sent|Fecha|Date)\s*:/mi',
        '/^_{10,}\s*$/m',
    ];
    foreach ($cortes as $patron) {
        if (preg_match($patron, $texto, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
            $texto = substr($texto, 0, $m[0][1]);
        }
    }
    $lineas = array_filter(explode("\n", $texto), static fn($l) => strpos(ltrim($l), '>') !== 0);
    $texto = preg_replace("/[ \t]+/", ' ', implode("\n", $lineas));
    $texto = trim(preg_replace("/\n\s*\n+/", "\n\n", $texto));
    return mb_substr($texto, 0, CORREOS_MAX_CUERPO);
}

/** "Nombre <correo@x.cl>" → [nombre, correo] */
function correo_direccion(string $v): array
{
    $v = trim($v);
    if (preg_match('/^(.*)<([^>]+)>/', $v, $m)) {
        return [trim($m[1], " \"'"), strtolower(trim($m[2]))];
    }
    return ['', strtolower(trim($v, ' <>'))];
}

/** Boletines, avisos automáticos y similares: se guardan sin cuerpo y la rutina los ignora. */
function correo_es_automatico(array $h, string $email): bool
{
    $auto = strtolower($h['auto-submitted'] ?? 'no');
    $precedencia = strtolower($h['precedence'] ?? '');
    return ($auto !== '' && $auto !== 'no')
        || in_array($precedencia, ['bulk', 'list', 'junk'], true)
        || isset($h['list-unsubscribe']) || isset($h['list-id'])
        || preg_match('/^(no-?reply|noreply|mailer-daemon|postmaster|notificaciones?|notifications?|alertas?|boletin|newsletter|info)@/i', $email);
}

/** Convierte un correo crudo en la fila a guardar. */
function correo_desde_crudo(string $crudo, ?string $llegada): array
{
    [$h, $cuerpo] = mime_partir($crudo);
    [$nombre, $email] = correo_direccion(mime_decodificar_encabezado($h['from'] ?? ''));
    $automatico = correo_es_automatico($h, $email);
    $texto = '';
    if (!$automatico) {
        [$plano, $html] = mime_texto($h, $cuerpo);
        $texto = correo_limpiar_texto($plano ?? $html ?? '');
    }
    $fecha = strtotime($h['date'] ?? '') ?: ($llegada ? strtotime($llegada) : false) ?: time();
    $messageId = trim($h['message-id'] ?? '', " <>");
    return [
        'message_id'      => mb_substr($messageId !== '' ? $messageId : 'sin-id-' . sha1($crudo), 0, 250),
        'remitente'       => mb_substr(trim($nombre !== '' ? "$nombre <$email>" : $email), 0, 200),
        'remitente_email' => mb_substr($email, 0, 150),
        'para'            => mb_substr(trim(mime_decodificar_encabezado(($h['to'] ?? '') . (isset($h['cc']) ? ', ' . $h['cc'] : ''))), 0, 1000),
        'asunto'          => mb_substr(trim(mime_decodificar_encabezado($h['subject'] ?? '')), 0, 250),
        'recibido_en'     => date('Y-m-d H:i:s', $fecha),
        'cuerpo'          => $texto === '' ? null : $texto,
        'automatico'      => $automatico ? 1 : 0,
    ];
}

/* ================================================================
 * Lectura de los buzones (la llama el cron o el botón «Leer ahora»)
 * ================================================================ */

/** Guarda un correo; si ya llegó por otro buzón, solo suma ese buzón. @return bool true si es nuevo */
function correo_guardar(array $c, string $buzon, int $uid): bool
{
    $hash = bandeja_hash_correo($c['message_id']);
    $existente = q_uno('SELECT id, buzones FROM correos_entrantes WHERE correo_hash = ?', [$hash]);
    if ($existente) {
        $buzones = array_filter(array_map('trim', explode(',', (string)$existente['buzones'])));
        if (!in_array($buzon, $buzones, true)) {
            $buzones[] = $buzon;
            q('UPDATE correos_entrantes SET buzones = ? WHERE id = ?', [mb_substr(implode(', ', $buzones), 0, 250), $existente['id']]);
        }
        return false;
    }
    insertar('correos_entrantes', $c + [
        'correo_hash' => $hash, 'buzones' => $buzon, 'uid' => $uid,
        'estado' => $c['automatico'] ? 'ignorado' : 'nuevo', 'creado_en' => ahora(),
    ]);
    return true;
}

/** Lee todos los buzones activos. @return array<int, string> una línea de resumen por buzón */
function correos_leer_buzones(?int $soloBuzon = null): array
{
    $srv = correos_servidor();
    $resumen = [];
    $buzones = q_todos('SELECT * FROM correo_buzones WHERE activo = 1' . ($soloBuzon ? ' AND id = ' . $soloBuzon : '') . ' ORDER BY id');
    foreach ($buzones as $b) {
        $imap = new ImapCliente($srv['host'], $srv['puerto']);
        $nuevos = 0;
        $leidos = 0;
        try {
            $imap->conectar();
            $imap->ingresar($b['usuario'], descifrar($b['clave_cifrada']));
            $validez = $imap->seleccionar('INBOX');
            $ultimo = (int)$b['ultimo_uid'];
            if (!$ultimo || (int)$b['uidvalidity'] !== $validez) {
                // Primera lectura (o el servidor renumeró la carpeta): solo los últimos días
                $uids = $imap->buscarUids('SINCE ' . date('d-M-Y', strtotime('-' . CORREOS_DIAS_PRIMERA . ' days')));
            } else {
                $uids = array_values(array_filter($imap->buscarUids('UID ' . ($ultimo + 1) . ':*'), static fn($u) => $u > $ultimo));
            }
            $uids = array_slice($uids, 0, CORREOS_MAX_POR_LECTURA);
            foreach ($uids as $uid) {
                [$crudo, $llegada] = $imap->traer($uid);
                if ($crudo !== '') {
                    $nuevos += correo_guardar(correo_desde_crudo($crudo, $llegada), $b['usuario'], $uid) ? 1 : 0;
                }
                $leidos++;
                q('UPDATE correo_buzones SET ultimo_uid = ?, uidvalidity = ? WHERE id = ?', [$uid, $validez, $b['id']]);
            }
            if (!$uids) {
                q('UPDATE correo_buzones SET uidvalidity = ? WHERE id = ?', [$validez, $b['id']]);
            }
            q('UPDATE correo_buzones SET leido_en = ?, error = NULL WHERE id = ?', [ahora(), $b['id']]);
            $resumen[] = "{$b['usuario']}: $leidos leídos, $nuevos nuevos.";
        } catch (Throwable $ex) {
            q('UPDATE correo_buzones SET error = ?, error_en = ? WHERE id = ?', [mb_substr($ex->getMessage(), 0, 500), ahora(), $b['id']]);
            $resumen[] = "{$b['usuario']}: error — " . $ex->getMessage();
        } finally {
            $imap->salir();
        }
    }
    correos_purgar();
    return $resumen;
}

/** Los extractos de correos son datos de clientes: se borran a los CORREOS_DIAS_RETENCION días. */
function correos_purgar(): void
{
    q('DELETE FROM correos_entrantes WHERE creado_en < ?', [date('Y-m-d H:i:s', strtotime('-' . CORREOS_DIAS_RETENCION . ' days'))]);
}
