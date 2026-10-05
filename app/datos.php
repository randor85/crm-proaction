<?php
declare(strict_types=1);

/*
 * Operaciones sobre datos de clientes: convertir en prospecto, fusionar clientes
 * y la reorganización única de lo importado desde la planilla histórica.
 */

/**
 * Convierte un cliente en prospecto: crea una oportunidad en etapa "prospecto" con su empresa,
 * deja sus RUT, tareas, gestiones, credenciales y documentos ligados a la empresa y lo quita de Clientes.
 * No convierte clientes con facturas o planes de cobro.
 * @return string|null motivo si no se pudo convertir
 */
function convertir_en_prospecto(int $clienteId): ?string
{
    $c = q_uno('SELECT * FROM clientes WHERE id = ?', [$clienteId]);
    if (!$c) {
        return 'no existe';
    }
    if (q_valor('SELECT COUNT(*) FROM facturas WHERE cliente_id = ?', [$clienteId]) || q_valor('SELECT COUNT(*) FROM cobros WHERE cliente_id = ?', [$clienteId])) {
        return 'tiene facturación o planes de cobro';
    }
    $principal = empresa_principal($c);
    $origen = implode(' · ', array_filter([
        'Convertido desde Clientes el ' . date('d/m/Y'),
        $c['categoria'] ? 'categoría ' . $c['categoria'] : null,
        $c['situacion'] ? 'situación ' . $c['situacion'] : null,
    ]));
    $oportunidad = insertar('oportunidades', [
        'titulo'         => mb_substr($c['nombre'], 0, 150),
        'empresa_id'     => $principal,
        'contacto_id'    => q_valor('SELECT MIN(contacto_id) FROM contacto_empresas WHERE empresa_id = ?', [$principal]),
        'monto'          => 0,
        'etapa'          => 'prospecto',
        'probabilidad'   => 10,
        'responsable_id' => $c['ejecutivo_id'],
        'notas'          => trim($origen . ".\n" . ($c['notas'] ?? '')),
        'creado_en'      => ahora(),
        'actualizado_en' => ahora(),
    ]);
    // Todo lo que colgaba del cliente pasa a su empresa (si no tenía una propia)
    foreach (['tareas', 'credenciales', 'documentos'] as $tabla) {
        q("UPDATE $tabla SET empresa_id = COALESCE(empresa_id, ?), cliente_id = NULL WHERE cliente_id = ?", [$principal, $clienteId]);
    }
    q('UPDATE actividades SET empresa_id = COALESCE(empresa_id, ?), oportunidad_id = COALESCE(oportunidad_id, ?), cliente_id = NULL WHERE cliente_id = ?',
        [$principal, $oportunidad, $clienteId]);
    q('UPDATE empresas SET cliente_id = NULL, actualizado_en = ? WHERE cliente_id = ?', [ahora(), $clienteId]);
    q('DELETE FROM clientes WHERE id = ?', [$clienteId]);
    return null;
}

/** Primera ficha de RUT del cliente; si no tiene, la crea con sus datos. */
function empresa_principal(array $c): int
{
    $id = q_valor('SELECT MIN(id) FROM empresas WHERE cliente_id = ?', [$c['id']]);
    if ($id) {
        return (int)$id;
    }
    $rutLibre = $c['rut'] && !q_valor('SELECT id FROM empresas WHERE identificacion = ?', [$c['rut']]) ? $c['rut'] : null;
    return insertar('empresas', [
        'nombre' => $c['nombre'], 'identificacion' => $rutLibre, 'cliente_id' => $c['id'], 'email' => $c['email'],
        'telefono' => $c['telefono'], 'direccion' => $c['direccion'], 'responsable_id' => $c['ejecutivo_id'],
        'creado_en' => ahora(), 'actualizado_en' => ahora(),
    ]);
}

/**
 * Integra el cliente $origen dentro de $destino: sus RUT, tareas, gestiones, credenciales, documentos,
 * planes de cobro, facturas y equipo pasan al destino; sus notas quedan en su propia ficha de RUT.
 */
function fusionar_cliente(int $origen, int $destino, string $motivo): void
{
    $c = q_uno('SELECT * FROM clientes WHERE id = ?', [$origen]);
    if (!$c || $origen === $destino) {
        return;
    }
    $empresa = empresa_principal($c);
    $notas = sumar_lineas(q_valor('SELECT notas FROM empresas WHERE id = ?', [$empresa]), array_merge(
        [$motivo],
        $c['categoria'] ? ['Categoría anterior: ' . $c['categoria']] : [],
        $c['situacion'] ? ['Situación anterior: ' . $c['situacion']] : [],
        lineas_sin_origen($c['notas'])
    ));
    q('UPDATE empresas SET notas = ?, actualizado_en = ? WHERE id = ?', [$notas, ahora(), $empresa]);
    foreach (['tareas', 'credenciales', 'documentos', 'actividades'] as $tabla) {
        q("UPDATE $tabla SET empresa_id = COALESCE(empresa_id, ?), cliente_id = ? WHERE cliente_id = ?", [$empresa, $destino, $origen]);
    }
    q('UPDATE cobros SET empresa_id = COALESCE(empresa_id, ?), cliente_id = ? WHERE cliente_id = ?', [$empresa, $destino, $origen]);
    q('UPDATE facturas SET empresa_id = COALESCE(empresa_id, ?), cliente_id = ? WHERE cliente_id = ?', [$empresa, $destino, $origen]);
    foreach (q_todos('SELECT usuario_id, area FROM cliente_ejecutivos WHERE cliente_id = ?', [$origen]) as $m) {
        if (!q_valor('SELECT 1 FROM cliente_ejecutivos WHERE cliente_id = ? AND usuario_id = ?', [$destino, $m['usuario_id']])) {
            insertar('cliente_ejecutivos', ['cliente_id' => $destino, 'usuario_id' => $m['usuario_id'], 'area' => $m['area']]);
        }
    }
    q('UPDATE empresas SET cliente_id = ?, actualizado_en = ? WHERE cliente_id = ?', [$destino, ahora(), $origen]);
    q('DELETE FROM clientes WHERE id = ?', [$origen]);
}

/** Líneas de un texto sin las que empiezan con "Origen:" (rastro de la importación). */
function lineas_sin_origen(?string $texto): array
{
    return array_values(array_filter(array_map('trim', explode("\n", (string)$texto)),
        static fn($l) => $l !== '' && !preg_match('/^Origen:/u', $l)));
}

function sumar_lineas(?string $texto, array $lineas): ?string
{
    $actual = array_values(array_filter(array_map('trim', explode("\n", (string)$texto)), 'strlen'));
    foreach ($lineas as $l) {
        if ($l !== '' && !in_array($l, $actual, true)) {
            $actual[] = $l;
        }
    }
    return $actual ? implode("\n", $actual) : null;
}

/** Vincula un contacto a un RUT con un rol (no duplica). @return bool true si se creó el vínculo */
function vincular_contacto(int $contactoId, int $empresaId, ?string $rol): bool
{
    if (q_valor('SELECT 1 FROM contacto_empresas WHERE contacto_id = ? AND empresa_id = ?', [$contactoId, $empresaId])) {
        return false;
    }
    insertar('contacto_empresas', ['contacto_id' => $contactoId, 'empresa_id' => $empresaId, 'rol' => nulo_si_vacio(trim((string)$rol))]);
    return true;
}

/** Busca (o crea) el contacto por nombre y lo vincula al RUT. @return bool true si se creó un vínculo nuevo */
function contacto_en(int $empresaId, string $nombre, string $rol = 'Contacto'): bool
{
    $nombre = trim($nombre);
    if ($nombre === '') {
        return false;
    }
    $id = q_valor('SELECT MIN(id) FROM contactos WHERE LOWER(nombre) = ?', [mb_strtolower($nombre)]);
    if (!$id) {
        $id = insertar('contactos', ['empresa_id' => $empresaId, 'nombre' => mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE),
            'cargo' => $rol, 'creado_en' => ahora(), 'actualizado_en' => ahora()]);
    }
    return vincular_contacto((int)$id, $empresaId, $rol);
}

/** Clave para reconocer el mismo contacto escrito distinto: nombre sin tildes ni mayúsculas, o el correo. */
function clave_contacto(array $c): string
{
    $nombre = trim(trim((string)$c['nombre']) . ' ' . trim((string)($c['apellido'] ?? '')));
    if (filter_var($nombre, FILTER_VALIDATE_EMAIL)) {
        return 'correo:' . mb_strtolower($nombre);
    }
    $plano = strtr(mb_strtolower($nombre), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return 'nombre:' . trim(preg_replace('/[^a-z0-9]+/', ' ', $plano));
}

function es_rut_persona(?string $rut): bool
{
    $cuerpo = (int)substr(rut_limpiar((string)$rut), 0, -1);
    return $cuerpo > 0 && $cuerpo < 50000000;
}

/* ================================================================
 * Reorganización única de la planilla histórica (decisiones del 02-10-2026)
 * ================================================================ */

const REORGANIZACION_ID = 'datos_2026_10_02_reorganizacion';
const REORGANIZACION2_ID = 'datos_2026_10_02_etapa2';

function reorganizacion_aplicada(): ?string
{
    return q_valor('SELECT aplicada_en FROM migraciones WHERE id = ?', [REORGANIZACION_ID]);
}

/**
 * Ejecuta la reorganización. Con $aplicar = false todo se deshace al final (vista previa).
 * @return array<string, array<int, string>> informe: paso => líneas
 */
function reorganizar_datos(bool $aplicar): array
{
    $inf = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $admin = (int)q_valor("SELECT MIN(id) FROM usuarios WHERE rol = 'admin' AND activo = 1");

        /* 1. Grupos empresariales */
        foreach (['EMPRESAS LEFIMIL' => 'Grupo Lefimil', 'GRUPO ANGEL VILLAR' => 'Grupo Angel Villar'] as $categoria => $nombreGrupo) {
            $miembros = q_todos('SELECT * FROM clientes WHERE categoria = ? ORDER BY nombre', [$categoria]);
            if (!$miembros) {
                $inf['1. Grupos empresariales'][] = "$categoria: sin clientes, nada que hacer.";
                continue;
            }
            $grupo = q_valor("SELECT id FROM clientes WHERE nombre = ? AND tipo = 'grupo'", [$nombreGrupo]) ?: insertar('clientes', [
                'nombre' => $nombreGrupo, 'tipo' => 'grupo', 'ejecutivo_id' => $miembros[0]['ejecutivo_id'] ?: $admin,
                'activo' => 1, 'honorario_monto' => 0, 'honorario_moneda' => 'UF', 'documento_tipo' => 'factura_afecta',
                'notas' => 'Grupo empresarial creado al reorganizar la planilla histórica (antes categoría ' . $categoria . ').',
                'creado_en' => ahora(), 'actualizado_en' => ahora(),
            ]);
            $grupo = (int)$grupo;
            foreach ($miembros as $m) {
                fusionar_cliente((int)$m['id'], $grupo, 'Integrado al ' . $nombreGrupo . ' el ' . date('d/m/Y') . '.');
            }
            $n = (int)q_valor('SELECT COUNT(*) FROM empresas WHERE cliente_id = ?', [$grupo]);
            $inf['1. Grupos empresariales'][] = "$nombreGrupo: " . count($miembros) . " clientes integrados; queda un solo cliente con $n RUT.";
        }
        $n = q('UPDATE clientes SET categoria = NULL WHERE categoria = ?', ['JORGE JAÑA (BOMBERO)'])->rowCount();
        $inf['1. Grupos empresariales'][] = "Categoría «JORGE JAÑA (BOMBERO)» quitada a $n clientes.";

        /* 2–3. Prospectos */
        $categoriasProspecto = ['RUT POR CONSULTAR', 'SEGUIMIENTO SII', 'EMPRESAS A FUTURO', 'OTRAS EMPRESAS PARA PROBAR'];
        foreach ($categoriasProspecto as $cat) {
            $ok = 0;
            $omitidos = [];
            foreach (q_todos('SELECT id, nombre FROM clientes WHERE categoria = ?', [$cat]) as $c) {
                $motivo = convertir_en_prospecto((int)$c['id']);
                $motivo === null ? $ok++ : $omitidos[] = $c['nombre'] . " ($motivo)";
            }
            $inf['2. Prospectos'][] = "«{$cat}»: $ok convertidos en prospectos" . ($omitidos ? '; no convertidos: ' . implode(', ', $omitidos) : '') . '.';
        }

        /* 2. Ex-clientes */
        $exPorCategoria = q("UPDATE clientes SET activo = 0, categoria = NULL WHERE categoria = 'EMPRESAS DEL PASADO'")->rowCount();
        $exPorSituacion = q("UPDATE clientes SET activo = 0 WHERE situacion = 'EMPRESAS DEL PASADO' AND categoria IS NULL")->rowCount();
        $inf['3. Ex-clientes'][] = "$exPorCategoria clientes de «Empresas del pasado» quedan como ex-clientes (inactivos).";
        $inf['3. Ex-clientes'][] = "$exPorSituacion clientes más, sin categoría y con situación «del pasado», también.";

        /* Situación: solo etiquetas útiles; personas → contactos */
        $pedro = 0;
        foreach (q_todos("SELECT * FROM clientes WHERE situacion = 'PEDRO ALVARES'") as $c) {
            $pedro += contacto_en(empresa_principal($c), 'Pedro Alvares') ? 1 : 0;
        }
        $mapa = ['EMPRESAS EN COBRANZA' => 'EN COBRANZA', 'EMPRESAS EN VENTA' => 'EN VENTA', 'EMPRESAS POR VER Y ATENDER' => 'POR VER Y ATENDER'];
        foreach ($mapa as $de => $a) {
            q('UPDATE clientes SET situacion = ? WHERE situacion = ?', [$a, $de]);
        }
        $limpias = q('UPDATE clientes SET situacion = NULL WHERE situacion IS NOT NULL AND situacion NOT IN (?, ?, ?)', array_values($mapa))->rowCount();
        $inf['4. Situación → etiqueta'][] = 'Se conservan como etiqueta: En cobranza, En venta, Por ver y atender.';
        $inf['4. Situación → etiqueta'][] = "$limpias situaciones sin significado propio quitadas (vigentes, del pasado, personas, otras empresas, Pedro Alvares).";
        $inf['5. Contactos'][] = "Pedro Alvares agregado como contacto en $pedro RUT.";

        /* 4. Responsables externos de las tareas → contactos */
        $creados = 0;
        $tareasEditadas = 0;
        foreach (q_todos("SELECT id, empresa_id, descripcion FROM tareas WHERE descripcion LIKE '%Responsable externo:%' OR descripcion LIKE '%Grupo:%' OR descripcion LIKE '%Origen:%'") as $t) {
            $lineas = [];
            foreach (explode("\n", (string)$t['descripcion']) as $l) {
                $l = trim($l);
                if (preg_match('/^Responsable externo:\s*(.+)$/u', $l, $m)) {
                    if ($t['empresa_id'] && contacto_en((int)$t['empresa_id'], $m[1])) {
                        $creados++;
                    }
                    $lineas[] = 'Contacto: ' . mb_convert_case(mb_strtolower(trim($m[1])), MB_CASE_TITLE);
                } elseif ($l !== '' && !preg_match('/^(Grupo|Origen):/u', $l)) {
                    $lineas[] = $l;
                }
            }
            q('UPDATE tareas SET descripcion = ? WHERE id = ?', [$lineas ? implode("\n", $lineas) : null, $t['id']]);
            $tareasEditadas++;
        }
        $inf['5. Contactos'][] = "$creados contactos creados desde las tareas (Juan Milenao, Pamela González, Petra, Ninoska Leiva…); $tareasEditadas tareas limpiadas.";

        /* 5. Socios persona: RUT propio dentro del cliente de su empresa */
        $tipo = ['persona' => 0, 'empresa' => 0];
        $nuevas = $asignadas = $integrados = $propios = 0;
        foreach (q_todos('SELECT s.*, e.nombre AS empresa FROM socios s JOIN empresas e ON e.id = s.empresa_id ORDER BY s.id') as $s) {
            // El cliente de la empresa se lee en cada vuelta: una fusión anterior puede haberlo cambiado
            $s['cliente_id'] = q_valor('SELECT cliente_id FROM empresas WHERE id = ?', [$s['empresa_id']]);
            $persona = $s['rut'] ? es_rut_persona($s['rut']) : $s['tipo'] === 'persona';
            if ($s['rut']) {
                $tipo[$persona ? 'persona' : 'empresa']++;
            }
            $vinculo = $s['socio_empresa_id'];
            if ($persona && $s['rut'] && $s['cliente_id']) {
                $r = q_uno('SELECT id, cliente_id FROM empresas WHERE identificacion = ?', [$s['rut']]);
                if (!$r) {
                    $vinculo = insertar('empresas', ['nombre' => $s['nombre'], 'identificacion' => $s['rut'], 'cliente_id' => $s['cliente_id'],
                        'email' => $s['email'], 'telefono' => $s['telefono'], 'responsable_id' => $admin,
                        'notas' => 'Socio/representante de ' . $s['empresa'] . '. Para sus gestiones personales.',
                        'creado_en' => ahora(), 'actualizado_en' => ahora()]);
                    $nuevas++;
                } else {
                    $vinculo = $r['id'];
                    if (!$r['cliente_id']) {
                        q('UPDATE empresas SET cliente_id = ? WHERE id = ?', [$s['cliente_id'], $r['id']]);
                        $asignadas++;
                    } elseif ((int)$r['cliente_id'] !== (int)$s['cliente_id']) {
                        $cat = q_valor('SELECT categoria FROM clientes WHERE id = ?', [$r['cliente_id']]);
                        if ($cat === 'SOCIOS DE EMPRESA') {
                            fusionar_cliente((int)$r['cliente_id'], (int)$s['cliente_id'], 'Integrado como socio de ' . $s['empresa'] . ' el ' . date('d/m/Y') . '.');
                            $integrados++;
                        } else {
                            $propios++;
                        }
                    }
                }
            }
            q('UPDATE socios SET tipo = ?, socio_empresa_id = ?, notas = ? WHERE id = ?',
                [$persona ? 'persona' : 'empresa', $vinculo, sumar_lineas(null, lineas_sin_origen($s['notas'])), $s['id']]);
        }
        $quedanSocios = (int)q_valor("SELECT COUNT(*) FROM clientes WHERE categoria = 'SOCIOS DE EMPRESA'");
        q("UPDATE clientes SET categoria = NULL WHERE categoria = 'SOCIOS DE EMPRESA'");
        $inf['6. Socios'][] = "Tipo corregido según el RUT: {$tipo['persona']} personas y {$tipo['empresa']} empresas.";
        $inf['6. Socios'][] = "$nuevas socios persona sin ficha reciben su RUT dentro del cliente; $asignadas fichas sueltas se asignan al cliente.";
        $inf['6. Socios'][] = "$integrados clientes «Socios de empresa» integrados al cliente de su empresa; $propios socios ya eran clientes propios y se mantienen así.";
        $inf['6. Socios'][] = "$quedanSocios clientes «Socios de empresa» sin empresa asociada quedan como clientes (se quita la categoría).";

        /* 6. Credenciales */
        $gmail = 0;
        foreach (q_todos("SELECT id, usuario FROM credenciales WHERE UPPER(institucion) = 'GMAIL' AND usuario IS NOT NULL") as $k) {
            if (rut_valido((string)$k['usuario']) || preg_match('/^\d{1,2}\.\d{3}\.\d{3}-[\dkK]$/', (string)$k['usuario'])) {
                q('UPDATE credenciales SET usuario = NULL WHERE id = ?', [$k['id']]);
                $gmail++;
            }
        }
        $instituciones = ['SII' => 'SII', 'GMAIL' => 'Gmail', 'CLAVE UNICA' => 'Clave Única', 'CLAVE ÚNICA' => 'Clave Única',
            'CERTIFICADO' => 'Certificado digital', 'CERTIFICADO DIGITAL SII' => 'Certificado digital',
            'SIN IDENTIFICAR (REVISAR)' => 'Sin identificar (revisar)', 'CUPRUM' => 'AFP Cuprum', 'PREVIRED' => 'Previred'];
        $renombradas = 0;
        foreach (q_todos('SELECT id, institucion FROM credenciales') as $k) {
            $nuevo = $instituciones[mb_strtoupper(trim($k['institucion']))] ?? null;
            if ($nuevo && $nuevo !== $k['institucion']) {
                q('UPDATE credenciales SET institucion = ? WHERE id = ?', [$nuevo, $k['id']]);
                $renombradas++;
            }
        }
        $inf['7. Credenciales'][] = "$gmail credenciales de Gmail tenían el RUT como usuario: quedan sin usuario para completar con el correo.";
        $inf['7. Credenciales'][] = "$renombradas nombres de institución unificados (Gmail, Clave Única, Certificado digital, Sin identificar…).";

        /* 6. Notas sin rastro de la importación */
        $notas = 0;
        foreach (['clientes', 'empresas'] as $tabla) {
            foreach (q_todos("SELECT id, notas FROM $tabla WHERE notas LIKE '%Origen:%'") as $f) {
                q("UPDATE $tabla SET notas = ? WHERE id = ?", [sumar_lineas(null, lineas_sin_origen($f['notas'])), $f['id']]);
                $notas++;
            }
        }
        $inf['8. Notas'][] = "Texto «Origen: …» quitado de $notas notas de clientes y RUT.";

        /* Resumen final */
        $inf['Resultado'][] = 'Clientes activos: ' . q_valor('SELECT COUNT(*) FROM clientes WHERE activo = 1')
            . ' · ex-clientes: ' . q_valor('SELECT COUNT(*) FROM clientes WHERE activo = 0')
            . ' · prospectos: ' . q_valor("SELECT COUNT(*) FROM oportunidades WHERE etapa = 'prospecto'")
            . ' · RUT: ' . q_valor('SELECT COUNT(*) FROM empresas')
            . ' · contactos: ' . q_valor('SELECT COUNT(*) FROM contactos') . '.';
        $inf['Resultado'][] = 'Categorías que quedan: ' . (implode(', ', array_column(q_todos(
            'SELECT categoria, COUNT(*) n FROM clientes WHERE categoria IS NOT NULL GROUP BY categoria ORDER BY n DESC'), 'categoria')) ?: 'ninguna') . '.';

        if ($aplicar) {
            q('INSERT INTO migraciones (id, aplicada_en) VALUES (?, ?)', [REORGANIZACION_ID, ahora()]);
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
    return $inf;
}

/* ================================================================
 * Segunda etapa (decisiones del 02-10-2026): categorías de servicio a tareas,
 * categorías sueltas fuera y contactos únicos vinculados a varios RUT.
 * ================================================================ */

function reorganizacion2_aplicada(): ?string
{
    return q_valor('SELECT aplicada_en FROM migraciones WHERE id = ?', [REORGANIZACION2_ID]);
}

/** @return array<string, array<int, string>> informe */
function reorganizar_etapa2(bool $aplicar): array
{
    $inf = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        /* 1. Categorías de servicio → tareas sin plazo */
        foreach (['EMPRESAS PARA DECLARACION' => 'Declaración pendiente', 'EMPRESAS CON IMPUESTOS POR REVISAR' => 'Revisar impuestos'] as $cat => $titulo) {
            $creadas = 0;
            $clientes = q_todos('SELECT * FROM clientes WHERE categoria = ?', [$cat]);
            foreach ($clientes as $c) {
                if (!q_valor("SELECT id FROM tareas WHERE cliente_id = ? AND titulo = ? AND estado <> 'completada'", [$c['id'], $titulo])) {
                    insertar('tareas', [
                        'titulo' => $titulo, 'descripcion' => 'Desde la categoría «' . $cat . '» de la planilla histórica.',
                        'cliente_id' => $c['id'], 'empresa_id' => q_valor('SELECT MIN(id) FROM empresas WHERE cliente_id = ?', [$c['id']]),
                        'responsable_id' => $c['ejecutivo_id'], 'estado' => 'pendiente', 'prioridad' => 'normal', 'recurrencia' => 'ninguna',
                        'creado_por' => (int)usuario_actual()['id'], 'creado_en' => ahora(), 'actualizado_en' => ahora(),
                    ]);
                    $creadas++;
                }
            }
            q('UPDATE clientes SET categoria = NULL WHERE categoria = ?', [$cat]);
            $inf['1. Categorías de servicio → tareas'][] = "«{$titulo}»: $creadas tareas creadas (" . count($clientes) . " clientes con «{$cat}»); se quita la categoría.";
        }

        /* 2. Categorías sueltas → nota del cliente */
        foreach (['OTRAS EMPRESAS', 'ALE', 'PERSONALES', 'PRESTAMOS SOLIDARIOS'] as $cat) {
            $n = 0;
            foreach (q_todos('SELECT id, notas FROM clientes WHERE categoria = ?', [$cat]) as $c) {
                q('UPDATE clientes SET notas = ?, categoria = NULL WHERE id = ?', [sumar_lineas($c['notas'], ['Categoría anterior: ' . $cat]), $c['id']]);
                $n++;
            }
            $inf['2. Categorías sueltas'][] = "«{$cat}»: quitada a $n clientes (queda anotada en su nota).";
        }

        /* 3. Contactos únicos */
        $grupos = [];
        foreach (q_todos('SELECT * FROM contactos ORDER BY id') as $c) {
            $grupos[clave_contacto($c)][] = $c;
        }
        $fusionados = 0;
        $personas = 0;
        foreach ($grupos as $miembros) {
            $personas++;
            if (count($miembros) < 2) {
                continue;
            }
            $principal = array_shift($miembros);
            $datos = [];
            foreach (['apellido', 'email', 'telefono', 'movil', 'cargo'] as $campo) {
                if (!$principal[$campo]) {
                    foreach ($miembros as $m) {
                        if ($m[$campo]) {
                            $datos[$campo] = $m[$campo];
                            break;
                        }
                    }
                }
            }
            $notas = sumar_lineas($principal['notas'], array_merge(...array_map(static fn($m) => lineas_sin_origen($m['notas']), $miembros)) ?: []);
            $datos['notas'] = $notas;
            $datos['actualizado_en'] = ahora();
            actualizar('contactos', (int)$principal['id'], $datos);
            foreach ($miembros as $m) {
                foreach (q_todos('SELECT empresa_id, rol FROM contacto_empresas WHERE contacto_id = ?', [$m['id']]) as $v) {
                    vincular_contacto((int)$principal['id'], (int)$v['empresa_id'], $v['rol']);
                }
                q('UPDATE oportunidades SET contacto_id = ? WHERE contacto_id = ?', [$principal['id'], $m['id']]);
                q('UPDATE actividades SET contacto_id = ? WHERE contacto_id = ?', [$principal['id'], $m['id']]);
                q('DELETE FROM contactos WHERE id = ?', [$m['id']]);
                $fusionados++;
            }
        }
        $inf['3. Contactos'][] = "$fusionados fichas duplicadas fusionadas; quedan $personas contactos únicos.";
        foreach (q_todos('SELECT c.nombre, COUNT(v.empresa_id) n FROM contactos c JOIN contacto_empresas v ON v.contacto_id = c.id
            GROUP BY c.id, c.nombre HAVING COUNT(v.empresa_id) > 1 ORDER BY n DESC LIMIT 8') as $c) {
            $inf['3. Contactos'][] = $c['nombre'] . ': vinculado a ' . $c['n'] . ' RUT.';
        }

        $inf['Resultado'][] = 'Categorías que quedan: ' . (implode(', ', array_column(q_todos(
            'SELECT categoria, COUNT(*) n FROM clientes WHERE categoria IS NOT NULL GROUP BY categoria ORDER BY n DESC'), 'categoria')) ?: 'ninguna')
            . ' · tareas abiertas: ' . q_valor("SELECT COUNT(*) FROM tareas WHERE estado <> 'completada'")
            . ' · contactos: ' . q_valor('SELECT COUNT(*) FROM contactos') . '.';

        if ($aplicar) {
            q('INSERT INTO migraciones (id, aplicada_en) VALUES (?, ?)', [REORGANIZACION2_ID, ahora()]);
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
    return $inf;
}

/* ================================================================
 * Prospecto ganado → cliente
 * ================================================================ */

/**
 * Convierte una oportunidad en cliente: usa el cliente del RUT si ya existe o crea uno, le pasa lo que
 * colgaba del prospecto (tareas, gestiones, credenciales, documentos) y crea el plan de cobro de la tarifa.
 * @return array{0: ?int, 1: ?string} [id del cliente, error]
 */
function convertir_en_cliente(int $oportunidadId): array
{
    $o = q_uno('SELECT o.*, e.nombre AS empresa, e.identificacion, e.cliente_id AS cliente_rut
        FROM oportunidades o LEFT JOIN empresas e ON e.id = o.empresa_id WHERE o.id = ?', [$oportunidadId]);
    if (!$o) {
        return [null, 'La oportunidad no existe.'];
    }
    if ($o['cliente_id'] && q_valor('SELECT id FROM clientes WHERE id = ?', [$o['cliente_id']])) {
        return [(int)$o['cliente_id'], 'Ya se había convertido en cliente.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $clienteId = $o['cliente_rut'] ? (int)$o['cliente_rut'] : null;
        $nuevo = !$clienteId;
        if ($nuevo) {
            $clienteId = insertar('clientes', [
                'nombre' => mb_substr($o['empresa'] ?: $o['titulo'], 0, 150),
                'tipo' => $o['identificacion'] && es_rut_persona($o['identificacion']) ? 'persona' : 'empresa',
                'rut' => $o['identificacion'], 'ejecutivo_id' => $o['responsable_id'], 'activo' => 1,
                'honorario_monto' => 0, 'honorario_moneda' => 'UF', 'documento_tipo' => 'factura_afecta',
                'notas' => 'Cliente desde el prospecto «' . $o['titulo'] . '» (' . date('d/m/Y') . ').'
                    . ($o['tarifa_tipo'] === 'unico' && $o['monto'] > 0 ? "\nServicio único acordado: " . tarifa_texto($o) . ($o['servicio'] ? ' · ' . $o['servicio'] : '') . '.' : ''),
                'creado_en' => ahora(), 'actualizado_en' => ahora(),
            ]);
        }
        if ($o['empresa_id']) {
            q('UPDATE empresas SET cliente_id = ?, actualizado_en = ? WHERE id = ?', [$clienteId, ahora(), $o['empresa_id']]);
            foreach (['tareas', 'credenciales', 'documentos', 'actividades'] as $tabla) {
                q("UPDATE $tabla SET cliente_id = ? WHERE empresa_id = ? AND cliente_id IS NULL", [$clienteId, $o['empresa_id']]);
            }
        }
        q('UPDATE tareas SET cliente_id = ? WHERE oportunidad_id = ? AND cliente_id IS NULL', [$clienteId, $oportunidadId]);
        q('UPDATE actividades SET cliente_id = ? WHERE oportunidad_id = ? AND cliente_id IS NULL', [$clienteId, $oportunidadId]);
        if (isset(PERIODICIDADES[$o['tarifa_tipo']]) && (float)$o['monto'] > 0) {
            insertar('cobros', [
                'cliente_id' => $clienteId, 'empresa_id' => $o['empresa_id'],
                'concepto' => mb_substr($o['servicio'] ?: 'Honorarios asesoría tributaria', 0, 200),
                'monto' => $o['monto'], 'moneda' => $o['tarifa_moneda'], 'documento_tipo' => 'factura_afecta',
                'periodicidad' => $o['tarifa_tipo'], 'mes_inicio' => (int)($o['tarifa_mes'] ?: 1), 'activo' => 1,
                'creado_en' => ahora(), 'actualizado_en' => ahora(),
            ]);
        }
        q("UPDATE oportunidades SET cliente_id = ?, etapa = 'ganada', actualizado_en = ? WHERE id = ?", [$clienteId, ahora(), $oportunidadId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return [$clienteId, null];
}
