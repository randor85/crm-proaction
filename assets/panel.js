/* CRM ProAction - vista dividida estilo correo: la ficha se abre en una columna junto a la lista.
 * Solo en pantallas anchas; en el teléfono los enlaces funcionan como siempre. */
(function () {
    'use strict';

    var ANCHO_MINIMO = 1100;
    var principal = document.querySelector('main.contenido');
    var barra = document.querySelector('header.barra');
    if (!principal || !barra || !window.fetch || !window.DOMParser) { return; }

    var aqui = new URL(location.href);
    // Las listas (sin acción, o el buscador) abren fichas en el panel; una ficha abierta a pantalla completa se comporta normal.
    var esLista = !aqui.searchParams.get('a');
    var panel, cuerpo, titulo, botonAtras, enlaceCompleto;
    var historial = [];
    var actual = null;

    function anchoSuficiente() { return window.innerWidth >= ANCHO_MINIMO; }

    /** URL interna que se puede mostrar en el panel: fichas y formularios de edición. */
    function paraPanel(href) {
        if (!href) { return null; }
        var u;
        try { u = new URL(href, location.href); } catch (e) { return null; }
        if (u.origin !== location.origin || !/\/index\.php$/.test(u.pathname)) { return null; }
        var a = u.searchParams.get('a');
        if (a !== 'ver' && a !== 'form') { return null; }
        return u;
    }
    function relativa(u) { return 'index.php' + u.search; }
    function clave(u) { return u.searchParams.get('r') + ':' + u.searchParams.get('id'); }

    function crearPanel() {
        if (panel) { return; }
        panel = document.createElement('aside');
        panel.id = 'panel-detalle';
        panel.setAttribute('aria-label', 'Ficha');
        panel.innerHTML =
            '<div class="panel-detalle-divisor" title="Arrastre para cambiar el ancho"></div>' +
            '<div class="panel-detalle-barra">' +
            '  <button type="button" class="chico secundario" data-panel="atras" title="Volver a la ficha anterior">←</button>' +
            '  <span class="panel-detalle-titulo"></span>' +
            '  <a class="boton chico secundario" data-panel="completo" title="Abrir a pantalla completa">⤢ Abrir</a>' +
            '  <button type="button" class="chico secundario" data-panel="cerrar" title="Cerrar (Esc)">✕</button>' +
            '</div>' +
            '<div class="panel-detalle-cuerpo"></div>';
        document.body.appendChild(panel);
        cuerpo = panel.querySelector('.panel-detalle-cuerpo');
        titulo = panel.querySelector('.panel-detalle-titulo');
        botonAtras = panel.querySelector('[data-panel="atras"]');
        enlaceCompleto = panel.querySelector('[data-panel="completo"]');
        botonAtras.addEventListener('click', function () {
            if (historial.length) { cargar(historial.pop(), { sinHistorial: true }); }
        });
        panel.querySelector('[data-panel="cerrar"]').addEventListener('click', cerrar);
        panel.addEventListener('click', clicEnPanel);
        panel.addEventListener('submit', envioEnPanel);
        iniciarDivisor(panel.querySelector('.panel-detalle-divisor'));
    }

    function ajustarAltoBarra() {
        document.documentElement.style.setProperty('--alto-barra', barra.offsetHeight + 'px');
    }

    function cerrar() {
        document.body.classList.remove('con-panel');
        historial = [];
        actual = null;
        marcarSeleccion();
        history.replaceState(null, '', location.pathname + location.search);
    }

    /** Carga una ficha en el panel. opciones: { datos: FormData (POST), metodo, sinHistorial } */
    function cargar(u, opciones) {
        opciones = opciones || {};
        crearPanel();
        if (actual && !opciones.sinHistorial && !opciones.datos && relativa(actual) !== relativa(u)) {
            historial.push(actual);
            if (historial.length > 30) { historial.shift(); }
        }
        document.body.classList.add('con-panel');
        cuerpo.classList.add('cargando');
        return fetch(relativa(u), {
            method: opciones.datos ? 'POST' : 'GET',
            body: opciones.datos || undefined,
            credentials: 'same-origin'
        }).then(function (r) {
            var tipo = r.headers.get('Content-Type') || '';
            var final = new URL(r.url);
            if (/login\.php$/.test(final.pathname) || tipo.indexOf('text/html') === -1) {
                location.href = r.url; // sesión vencida o archivo: navegación normal
                return null;
            }
            return r.text().then(function (html) { return { html: html, final: final }; });
        }).then(function (res) {
            if (!res) { return; }
            var doc = new DOMParser().parseFromString(res.html, 'text/html');
            var contenido = doc.querySelector('main.contenido');
            var destino = paraPanel(res.final.href);
            // Lo que no es una ficha (p. ej. una lista tras eliminar) o trae scripts propios se abre normal.
            if (!contenido || contenido.querySelector('script') || !destino) {
                location.href = res.final.href;
                return;
            }
            actual = destino;
            cuerpo.innerHTML = contenido.innerHTML;
            cuerpo.classList.remove('cargando');
            titulo.textContent = (doc.title || '').replace(/\s·\s[^·]*$/, '');
            enlaceCompleto.href = relativa(destino);
            botonAtras.hidden = !historial.length;
            panel.scrollTop = 0;
            if (window.CRM) { window.CRM.iniciar(cuerpo); }
            history.replaceState(null, '', location.pathname + location.search + '#ver=' + encodeURIComponent(destino.search));
            marcarSeleccion();
            if (opciones.datos) { refrescarLista(); }
        }).catch(function () {
            cuerpo.classList.remove('cargando');
            cuerpo.innerHTML = '<div class="alerta alerta-error">No se pudo abrir la ficha. <a href="' + relativa(u) + '">Abrirla a pantalla completa</a>.</div>';
        });
    }

    /** Tras guardar algo en el panel, la lista de la izquierda se actualiza sin perder la posición. */
    function refrescarLista() {
        var y = window.scrollY;
        fetch(location.pathname + location.search, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var nuevo = new DOMParser().parseFromString(html, 'text/html').querySelector('main.contenido');
                if (!nuevo || nuevo.querySelector('script')) { return; }
                // Los avisos ya se mostraron en el panel
                nuevo.querySelectorAll(':scope > .alerta').forEach(function (a) { a.remove(); });
                principal.innerHTML = nuevo.innerHTML;
                if (window.CRM) { window.CRM.iniciar(principal); }
                prepararFilas();
                marcarSeleccion();
                window.scrollTo(0, y);
            });
    }

    /* ---------- Lista de la izquierda ---------- */

    /** Cada fila con una ficha queda clicable completa, como en un cliente de correo. */
    function prepararFilas() {
        principal.querySelectorAll('tbody tr').forEach(function (fila) {
            var enlace = Array.prototype.find.call(fila.querySelectorAll('a[href]'), function (a) {
                var u = paraPanel(a.getAttribute('href'));
                return u && u.searchParams.get('a') === 'ver';
            });
            if (enlace) { fila.setAttribute('data-ver', enlace.getAttribute('href')); }
        });
    }

    function filas() { return Array.prototype.slice.call(principal.querySelectorAll('tbody tr[data-ver]')); }

    function marcarSeleccion() {
        var c = actual ? clave(actual) : null;
        filas().forEach(function (f) {
            f.classList.toggle('seleccionada', !!c && clave(paraPanel(f.getAttribute('data-ver'))) === c);
        });
        principal.querySelectorAll('a.seleccionada').forEach(function (a) { a.classList.remove('seleccionada'); });
        if (c) {
            principal.querySelectorAll('a[href]').forEach(function (a) {
                var u = paraPanel(a.getAttribute('href'));
                if (u && !a.closest('tr[data-ver]') && clave(u) === c && u.searchParams.get('a') === 'ver') { a.classList.add('seleccionada'); }
            });
        }
    }

    function abrirDesdeLista(href) {
        historial = [];
        actual = null;
        cargar(paraPanel(href));
    }

    principal.addEventListener('click', function (ev) {
        if (!esLista || !anchoSuficiente() || ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }
        var a = ev.target.closest('a[href]');
        if (a) {
            var u = paraPanel(a.getAttribute('href'));
            if (u && u.searchParams.get('a') === 'ver' && !a.target) {
                ev.preventDefault();
                abrirDesdeLista(a.getAttribute('href'));
            }
            return;
        }
        // Clic en cualquier parte de la fila (salvo botones, casillas y campos)
        if (ev.target.closest('button, input, select, textarea, label, form.en-linea')) { return; }
        var fila = ev.target.closest('tr[data-ver]');
        if (fila && !(window.getSelection && String(window.getSelection()).length)) {
            abrirDesdeLista(fila.getAttribute('data-ver'));
        }
    });

    /* Botones rápidos de la lista (p. ej. "✓ Hecho") con el panel abierto: se guardan sin perder la ficha abierta. */
    principal.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (ev.defaultPrevented || !document.body.classList.contains('con-panel') || !form.matches('form.en-linea')
            || (form.getAttribute('method') || '').toLowerCase() !== 'post') { return; }
        ev.preventDefault();
        fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function () {
                refrescarLista();
                if (actual) { cargar(actual, { sinHistorial: true }); }
            })
            .catch(function () { form.submit(); });
    });

    /* ---------- Dentro del panel ---------- */

    function clicEnPanel(ev) {
        if (ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }
        var a = ev.target.closest('a[href]');
        if (!a || !cuerpo.contains(a) || a.target || a.hasAttribute('download')) { return; }
        var href = a.getAttribute('href');
        if (href.charAt(0) === '#') {
            // Anclas internas: desplazarse dentro del panel
            ev.preventDefault();
            var objetivo = href.length > 1 && cuerpo.querySelector(href);
            if (objetivo) { objetivo.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            return;
        }
        var u = paraPanel(href);
        if (u) {
            ev.preventDefault();
            cargar(u);
        }
    }

    function envioEnPanel(ev) {
        var form = ev.target;
        if (ev.defaultPrevented || !cuerpo.contains(form)) { return; }
        var accion = new URL(form.getAttribute('action') || relativa(actual), location.href);
        var datos;
        try { datos = new FormData(form, ev.submitter || undefined); } catch (e) { datos = new FormData(form); }
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') {
            datos.forEach(function (v, k) { accion.searchParams.set(k, v); });
            if (paraPanel(accion.href)) { ev.preventDefault(); cargar(accion); }
            return;
        }
        ev.preventDefault();
        if (ev.submitter && ev.submitter.disabled !== undefined) { ev.submitter.disabled = true; }
        cargar(accion, { datos: datos, sinHistorial: true });
    }

    /* ---------- Teclado: ↑/↓ o j/k recorren la lista, Esc cierra ---------- */
    document.addEventListener('keydown', function (ev) {
        if (!document.body.classList.contains('con-panel')) { return; }
        var escribiendo = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) || document.activeElement.isContentEditable;
        if (ev.key === 'Escape' && !escribiendo) { cerrar(); return; }
        if (escribiendo || ev.ctrlKey || ev.metaKey || ev.altKey) { return; }
        var paso = (ev.key === 'ArrowDown' || ev.key === 'j') ? 1 : (ev.key === 'ArrowUp' || ev.key === 'k') ? -1 : 0;
        if (!paso || !esLista) { return; }
        var lista = filas();
        if (!lista.length) { return; }
        ev.preventDefault();
        var i = lista.findIndex(function (f) { return f.classList.contains('seleccionada'); });
        var siguiente = lista[Math.max(0, Math.min(lista.length - 1, i + paso))];
        if (siguiente && siguiente !== lista[i]) {
            siguiente.scrollIntoView({ block: 'nearest' });
            abrirDesdeLista(siguiente.getAttribute('data-ver'));
        }
    });

    /* ---------- Divisor arrastrable (el ancho se recuerda) ---------- */
    function iniciarDivisor(divisor) {
        try {
            var guardado = parseFloat(localStorage.getItem('crm_ancho_panel'));
            if (guardado >= 30 && guardado <= 75) { document.documentElement.style.setProperty('--ancho-panel', guardado + 'vw'); }
        } catch (e) { /* sin almacenamiento */ }
        divisor.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            document.body.classList.add('arrastrando');
            var mover = function (e) {
                var pct = Math.max(30, Math.min(75, (window.innerWidth - e.clientX) / window.innerWidth * 100));
                document.documentElement.style.setProperty('--ancho-panel', pct.toFixed(1) + 'vw');
                try { localStorage.setItem('crm_ancho_panel', pct.toFixed(1)); } catch (err) { /* sin almacenamiento */ }
            };
            var soltar = function () {
                document.body.classList.remove('arrastrando');
                document.removeEventListener('mousemove', mover);
                document.removeEventListener('mouseup', soltar);
            };
            document.addEventListener('mousemove', mover);
            document.addEventListener('mouseup', soltar);
        });
    }

    window.addEventListener('resize', function () {
        ajustarAltoBarra();
        if (!anchoSuficiente() && document.body.classList.contains('con-panel')) { cerrar(); }
    });

    ajustarAltoBarra();
    if (esLista) {
        document.body.classList.add('modo-lista');
        prepararFilas();
        // Al recargar la página se reabre la ficha que estaba abierta
        var m = location.hash.match(/^#ver=(.+)$/);
        if (m && anchoSuficiente()) {
            var u = paraPanel('index.php' + decodeURIComponent(m[1]));
            if (u) { cargar(u); }
        }
    }
})();
