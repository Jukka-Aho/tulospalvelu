(function () {
    var wrap = document.querySelector('[data-home-nakyma]');
    var karttaEl = document.getElementById('home-kartta');
    var dataEl = document.getElementById('home-kartta-data');
    var tyhjaEl = document.querySelector('[data-home-kartta-tyhja]');
    if (!wrap || !karttaEl || typeof L === 'undefined') {
        return;
    }

    var merkit = [];
    try {
        merkit = dataEl ? JSON.parse(dataEl.textContent || '[]') : [];
    } catch (e) {
        merkit = [];
    }
    if (!Array.isArray(merkit)) {
        merkit = [];
    }

    var suomi = [64.8, 26.0];
    var map = L.map(karttaEl, { scrollWheelZoom: true }).setView(suomi, 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    var layer = L.layerGroup().addTo(map);
    var merkitKartalla = [];

    function popupHtml(m) {
        var nimi = String(m.nimi || '');
        var pvm = String(m.pvm || '');
        var tila = m.live ? 'LIVE' : String(m.tila || '');
        var href = String(m.href || '#');
        var osat = [];
        osat.push('<strong>' + escapeHtml(nimi) + '</strong>');
        if (pvm || tila) {
            osat.push('<span>' + escapeHtml([pvm, tila].filter(Boolean).join(' · ')) + '</span>');
        }
        osat.push('<a href="' + escapeHtml(href) + '">Avaa tapahtuma</a>');
        return '<div class="home-kartta-popup">' + osat.join('<br>') + '</div>';
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function suodataMerkit(q) {
        layer.clearLayers();
        merkitKartalla = [];
        var nq = String(q || '').toLocaleLowerCase('fi-FI').trim();
        merkit.forEach(function (m) {
            var hay = String(m.haku || m.nimi || '').toLocaleLowerCase('fi-FI');
            if (nq && hay.indexOf(nq) === -1) {
                return;
            }
            if (typeof m.lat !== 'number' || typeof m.lng !== 'number') {
                return;
            }
            var mk = L.marker([m.lat, m.lng]);
            mk.bindPopup(popupHtml(m));
            mk.addTo(layer);
            merkitKartalla.push(mk);
        });
        if (tyhjaEl) {
            tyhjaEl.hidden = merkitKartalla.length > 0;
        }
        sovi();
    }

    function sovi() {
        if (merkitKartalla.length === 0) {
            map.setView(suomi, 5);
            return;
        }
        if (merkitKartalla.length === 1) {
            map.setView(merkitKartalla[0].getLatLng(), 12);
            return;
        }
        var g = L.featureGroup(merkitKartalla);
        map.fitBounds(g.getBounds().pad(0.18));
    }

    function desktop() {
        return window.matchMedia('(min-width: 901px)').matches;
    }

    function paivitaKoko() {
        map.invalidateSize();
        sovi();
    }

    function asetaNakyma(tila) {
        wrap.classList.toggle('is-lista', tila === 'lista');
        wrap.classList.toggle('is-kartta', tila === 'kartta');
        document.querySelectorAll('.home-nakyma-valinta [data-nakyma]').forEach(function (btn) {
            var on = btn.getAttribute('data-nakyma') === tila;
            btn.classList.toggle('is-valittu', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        try {
            sessionStorage.setItem('jah-etusivu-nakyma', tila);
        } catch (e) {}
        if (tila === 'kartta' || desktop()) {
            setTimeout(paivitaKoko, 60);
        }
    }

    document.querySelectorAll('.home-nakyma-valinta [data-nakyma]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            asetaNakyma(btn.getAttribute('data-nakyma') === 'kartta' ? 'kartta' : 'lista');
        });
    });

    var alku = 'lista';
    try {
        var t = sessionStorage.getItem('jah-etusivu-nakyma');
        if (t === 'kartta' || t === 'lista') {
            alku = t;
        }
    } catch (e) {}
    asetaNakyma(desktop() ? 'lista' : alku);

    window.jahKarttaSuodata = suodataMerkit;
    suodataMerkit('');

    window.addEventListener('resize', function () {
        if (desktop() || wrap.classList.contains('is-kartta')) {
            paivitaKoko();
        }
    });
})();
