/* CRM ProAction - comportamiento compartido (sin dependencias) */
(function () {
    'use strict';

    /* Filtra los selectores de empresa (RUT) según el cliente elegido. */
    var cliente = document.getElementById('f_cliente_id');
    var empresas = document.querySelectorAll('select[data-filtrar-cliente]');
    function filtrarEmpresas() {
        var c = cliente ? cliente.value : '';
        empresas.forEach(function (sel) {
            Array.prototype.forEach.call(sel.options, function (op) {
                if (!op.value) { return; }
                var visible = !c || op.getAttribute('data-cliente') === c;
                op.hidden = !visible;
                op.disabled = !visible;
                if (!visible && op.selected) { sel.value = ''; }
            });
        });
    }
    if (cliente && empresas.length) {
        cliente.addEventListener('change', filtrarEmpresas);
        filtrarEmpresas();
    }

    /* Botones "Copiar": data-copiar="id del elemento con el texto". */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-copiar]');
        if (!btn) { return; }
        var origen = document.getElementById(btn.getAttribute('data-copiar'));
        if (!origen) { return; }
        var texto = origen.value !== undefined ? origen.value : origen.textContent;
        navigator.clipboard.writeText(texto).then(function () {
            var antes = btn.textContent;
            btn.textContent = '✓ Copiado';
            setTimeout(function () { btn.textContent = antes; }, 1500);
        });
    });

    /* Mostrar u ocultar un campo de contraseña: data-alternar="id del input". */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-alternar]');
        if (!btn) { return; }
        var input = document.getElementById(btn.getAttribute('data-alternar'));
        if (input) { input.type = input.type === 'password' ? 'text' : 'password'; }
    });

    /* Selector de etiqueta con opción "Otra…": muestra el campo para escribir una nueva. */
    document.querySelectorAll('select[data-etiqueta]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var campo = sel.parentNode.querySelector('input[type=text]');
            if (!campo) { return; }
            campo.hidden = sel.value !== '__otra__';
            campo.required = !campo.hidden;
            if (!campo.hidden) { campo.focus(); }
        });
    });

    /* Plan de cobro: mostrar en qué meses se factura según periodicidad y mes de inicio. */
    var formCobro = document.getElementById('form-cobro');
    if (formCobro) {
        var nombresMes = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        var cadaMeses = { mensual: 1, trimestral: 3, semestral: 6, anual: 12 };
        var mostrarCalendario = function () {
            var cada = cadaMeses[formCobro.periodicidad.value] || 1;
            var inicio = parseInt(formCobro.mes_inicio.value, 10) || 1;
            var meses = [];
            for (var i = 0; i < 12 / cada; i++) { meses.push(((inicio - 1 + i * cada) % 12)); }
            meses.sort(function (a, b) { return a - b; });
            document.getElementById('calendario-cobro').textContent = cada === 1
                ? 'Se factura todos los meses.'
                : 'Se factura en: ' + meses.map(function (m) { return nombresMes[m]; }).join(', ') + '.';
            formCobro.mes_inicio.disabled = cada === 1;
        };
        formCobro.addEventListener('change', mostrarCalendario);
        mostrarCalendario();
    }

    /* Casilla "marcar todos" de las listas con acciones masivas. */
    document.addEventListener('change', function (ev) {
        if (!ev.target.matches('[data-marcar-todos]')) { return; }
        ev.target.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(function (c) { c.checked = ev.target.checked; });
    });

    /* Atajo: "/" enfoca el buscador general (salvo que se esté escribiendo). */
    document.addEventListener('keydown', function (ev) {
        var buscador = document.getElementById('buscar-global');
        if (ev.key !== '/' || !buscador || /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) { return; }
        ev.preventDefault();
        buscador.focus();
    });

    /* Mostrar una clave en la misma página (resultados del buscador). */
    var csrf = (document.querySelector('meta[name="csrf"]') || {}).content;
    function revelar(celda, claveUsuario) {
        var datos = new FormData();
        datos.append('csrf', csrf);
        if (claveUsuario) { datos.append('clave_usuario', claveUsuario); }
        fetch('index.php?r=credenciales&a=revelar_json&id=' + celda.getAttribute('data-credencial'), {
            method: 'POST', body: datos, credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.pedir_clave) { pedirClave(celda, d.error); return; }
            if (d.error) { celda.innerHTML = '<span class="error"></span>'; celda.firstChild.textContent = d.error; return; }
            var id = 'c' + celda.getAttribute('data-credencial');
            celda.innerHTML = '<input type="text" readonly class="clave-visible" id="' + id + '"> <button type="button" class="chico secundario" data-copiar="' + id + '">Copiar</button>';
            celda.querySelector('input').value = d.clave;
            // Se oculta sola al minuto
            setTimeout(function () {
                celda.innerHTML = '<button type="button" class="chico" data-revelar>Mostrar clave</button>';
            }, 60000);
        }).catch(function () {
            celda.innerHTML = '<span class="error">No se pudo consultar la clave.</span>';
        });
    }
    function pedirClave(celda, error) {
        var plantilla = document.getElementById('plantilla-pedir-clave');
        celda.innerHTML = '';
        celda.appendChild(plantilla.content.cloneNode(true));
        if (error) {
            var e = document.createElement('span');
            e.className = 'error';
            e.textContent = error;
            celda.appendChild(e);
        }
        var form = celda.querySelector('form');
        form.querySelector('input').focus();
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            revelar(celda, form.querySelector('input').value);
        });
    }
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-revelar]');
        if (btn) { revelar(btn.closest('[data-credencial]')); }
    });

    /* Formulario de facturación: cálculo en vivo y consulta de la UF. */
    var form = document.getElementById('form-factura');
    if (form) {
        var $ = function (n) { return form.querySelector('[name="' + n + '"]'); };
        // Formato chileno: "1.234.567,89"; también acepta "1234567.89" y "1.000.000".
        var num = function (v) {
            v = String(v || '').replace(/[\s$]/g, '');
            if (v.indexOf(',') !== -1 || /^\d{1,3}(\.\d{3})+$/.test(v)) { v = v.replace(/\./g, '').replace(',', '.'); }
            return parseFloat(v) || 0;
        };
        var pesos = function (n) { return '$ ' + Math.round(n).toLocaleString('es-CL'); };
        var retencion = parseFloat(form.getAttribute('data-retencion')) / 100;

        var calcular = function () {
            var uf = $('moneda').value === 'UF';
            form.querySelectorAll('.solo-uf').forEach(function (el) { el.hidden = !uf; });
            form.querySelectorAll('.solo-clp').forEach(function (el) { el.hidden = uf; });
            var neto = uf ? Math.round(num($('monto_uf').value) * num($('valor_uf').value)) : Math.round(num($('neto').value));
            var tipo = $('tipo_documento').value;
            var imp = 0, total = neto, etiqueta = 'IVA (19%)';
            if (tipo === 'factura_afecta') { imp = Math.round(neto * 0.19); total = neto + imp; }
            if (tipo === 'factura_exenta') { etiqueta = 'Exento'; }
            if (tipo === 'boleta_honorarios') { imp = Math.round(neto * retencion); total = neto - imp; etiqueta = 'Retención (' + (retencion * 100).toLocaleString('es-CL') + '%)'; }
            document.getElementById('calc-neto').textContent = pesos(neto);
            document.getElementById('calc-imp-etiqueta').textContent = etiqueta;
            document.getElementById('calc-imp').textContent = pesos(imp);
            document.getElementById('calc-total').textContent = pesos(total);
            document.getElementById('calc-total-etiqueta').textContent = tipo === 'boleta_honorarios' ? 'Líquido a pagar' : 'Total';
        };
        form.addEventListener('input', calcular);
        form.addEventListener('change', calcular);
        calcular();

        var botonUf = document.getElementById('obtener-uf');
        if (botonUf) {
            botonUf.addEventListener('click', function () {
                var f = $('fecha_emision').value;
                var aviso = document.getElementById('uf-aviso');
                aviso.textContent = 'Consultando…';
                botonUf.disabled = true;
                fetch('index.php?r=facturas&a=uf&fecha=' + encodeURIComponent(f), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.valor) {
                            $('valor_uf').value = Number(d.valor).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            aviso.textContent = 'UF del ' + f.split('-').reverse().join('/');
                            calcular();
                        } else {
                            aviso.textContent = 'No se encontró la UF de esa fecha; ingrésela manualmente.';
                        }
                    })
                    .catch(function () { aviso.textContent = 'No se pudo consultar la UF; ingrésela manualmente.'; })
                    .then(function () { botonUf.disabled = false; });
            });
        }
    }
})();
