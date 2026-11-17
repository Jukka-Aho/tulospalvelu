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

    var suomiRajat = L.latLngBounds([[59.6, 19.0], [70.1, 31.6]]);
    var map = L.map(karttaEl, { scrollWheelZoom: true }).fitBounds(suomiRajat);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    var layer = L.layerGroup().addTo(map);
    var merkitKartalla = [];
    var merkkiIkoni = L.divIcon({
        className: 'home-kartta-merkki',
        html: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 28 40" width="28" height="40" aria-hidden="true">'
            + '<path d="M14 1.6C7.4 1.6 2 7 2 13.6c0 9.2 12 24 12 24s12-14.8 12-24C26 7 20.6 1.6 14 1.6z" fill="#c49a22" stroke="#111" stroke-width="2.2"/>'
            + '<circle cx="14" cy="13.6" r="4.4" fill="#111"/>'
            + '</svg>',
        iconSize: [28, 40],
        iconAnchor: [14, 38],
        popupAnchor: [0, -34]
    });

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
            var mk = L.marker([m.lat, m.lng], { icon: merkkiIkoni });
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
        if (merkitKartalla.length <= 1) {
            map.fitBounds(suomiRajat);
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
