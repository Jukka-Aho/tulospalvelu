(function () {
    'use strict';

    if (typeof TILA === 'undefined' || (TILA !== 'nappi' && TILA !== 'numerot')) {
        return;
    }

    const kilpailuId = document.getElementById('kilpailu_id').value;
    const tokenEl = document.getElementById('api_token');
    const apiToken = tokenEl ? tokenEl.value : '';
    const laskuriEl = document.getElementById('laskuri');
    const yhteenvetoEl = document.getElementById('jonoYhteenveto');
    const listaEl = document.getElementById('jonoLista');
    const nappiViimeiset = document.getElementById('nappiViimeiset');
    const btnMaali = document.getElementById('btnMaaliNappi');
    const jonoInput = document.getElementById('jonoInput');
    const nimet = {};

    (window.kilpailijat || []).forEach(function (k) {
        nimet[String(k.numero)] = k.nimi;
    });

    let tila = { rivit: [], leimat_lkm: 0, numerot_lkm: 0, yhdistetty_lkm: 0 };
    let sending = false;
    /** @type {list} IndexedDB-jonosta tämän kilpailun odottavat rivit */
    let offlinePending = [];

    function kello(maaliaika) {
        if (!maaliaika) return '—';
        const m = String(maaliaika).match(/(\d{2}:\d{2}:\d{2})/);
        return m ? m[1] : maaliaika;
    }

    function aikaleimaTeksti(n) {
        return n + (n === 1 ? ' aikaleima' : ' aikaleimaa');
    }

    function synkkaMerkki(synkka) {
        if (synkka === 'pending') {
            return '<span class="synkka-merkki synkka-merkki--pending" title="Kirjattu laitteelle — ei vielä palvelimella">○ Ei palvelimella</span>';
        }
        return '<span class="synkka-merkki synkka-merkki--ok" title="Tallennettu palvelimelle">✓ Palvelimella</span>';
    }

    function tilaLabel(t) {
        return {
            yhdistetty: 'Yhdistetty',
            odottaa_numeroa: '⏳ Odottaa numeroa',
            odottaa_aikaa: '⏳ Odottaa aikaleimaa',
            ei_loydu: '⚠ Numeroa ei listalla',
            duplikaatti: '⚠ Duplikaatti',
            konflikti: '⚠ Konflikti',
            odottaa: 'Odottaa'
        }[t] || t;
    }

    function onVerkkovirhe(e) {
        if (typeof navigator !== 'undefined' && navigator.onLine === false) return true;
        if (!e) return false;
        if (e.name === 'TypeError') return true;
        const m = String(e.message || e);
        return /Failed to fetch|NetworkError|Load failed|network/i.test(m);
    }

    function offlineApiTyyppi(rivi) {
        const api = String(rivi.offline_api || '');
        if (api.indexOf('api_maali_leima') !== -1 || rivi.toiminto === 'leima') return 'leima';
        if (api.indexOf('api_maali_jono') !== -1 || rivi.toiminto === 'lisaa') return 'numero';
        if (api.indexOf('api_maali.php') !== -1) return 'ruudukko';
        return '';
    }

    function normalisoiMaaliaika(v) {
        return String(v || '').replace('T', ' ').trim();
    }

    function paivitaOfflinePending(items) {
        const kid = String(kilpailuId);
        offlinePending = (items || []).filter(function (r) {
            return String(r.kilpailu_id) === kid;
        });
        paivitaNakyma();
    }

    function lataaOfflinePending() {
        if (typeof haeOfflineJono !== 'function') {
            offlinePending = [];
            return Promise.resolve([]);
        }
        return haeOfflineJono().then(function (items) {
            paivitaOfflinePending(items);
            return items;
        }).catch(function () {
            offlinePending = [];
            return [];
        });
    }

    /**
     * Palvelimen rivit + paikalliset odottavat (ei vielä palvelimella).
     * Pollaus ei saa pyyhkiä offline-kirjauksia näkyvistä.
     */
    function yhdistaRivitNayttoon() {
        const server = (tila.rivit || []).map(function (r) {
            const x = Object.assign({}, r);
            x._synkka = 'ok';
            return x;
        });
        const serverAjat = {};
        const serverNrot = {};
        server.forEach(function (r) {
            if (r.maaliaika) {
                serverAjat[normalisoiMaaliaika(r.maaliaika)] = true;
            }
            if (r.numero) {
                serverNrot[String(r.numero)] = true;
            }
        });

        let maxSija = 0;
        server.forEach(function (r) {
            const s = parseInt(r.sija, 10) || 0;
            if (s > maxSija) maxSija = s;
        });

        offlinePending.forEach(function (p) {
            const tyyppi = offlineApiTyyppi(p);
            if (tyyppi === 'leima') {
                const aika = normalisoiMaaliaika(p.maaliaika);
                if (!aika || serverAjat[aika]) return;
                maxSija += 1;
                server.push({
                    sija: maxSija,
                    leima_id: 'pending-' + (p.client_id || p.id || maxSija),
                    numero_id: null,
                    numero: null,
                    nimi: null,
                    maaliaika: aika,
                    tila: 'odottaa_numeroa',
                    viesti: 'Ei vielä palvelimella',
                    _synkka: 'pending',
                    _pending: true
                });
                serverAjat[aika] = true;
            } else if (tyyppi === 'numero') {
                const nro = p.numero != null ? String(p.numero) : '';
                if (!nro || serverNrot[nro]) return;
                maxSija += 1;
                server.push({
                    sija: maxSija,
                    leima_id: null,
                    numero_id: 'offline-' + (p.client_id || p.id || nro),
                    numero: parseInt(nro, 10),
                    nimi: nimet[nro] || '',
                    maaliaika: null,
                    tila: 'odottaa_aikaa',
                    viesti: 'Ei vielä palvelimella',
                    _synkka: 'pending',
                    _pending: true
                });
                serverNrot[nro] = true;
            }
        });

        return server;
    }

    function laskeYhteenveto(rivit) {
        let leimat = 0;
        let numerot = 0;
        let yhd = 0;
        rivit.forEach(function (r) {
            if (r.leima_id && r.maaliaika) leimat += 1;
            if (r.numero_id && r.numero) numerot += 1;
            if (r.tila === 'yhdistetty') yhd += 1;
        });
        return { leimat: leimat, numerot: numerot, yhd: yhd };
    }

    function soita(tyyppi) {
        if (typeof ajanottoSoita === 'function') {
            ajanottoSoita(tyyppi);
            return;
        }
        if (tyyppi === 'konflikti') soitaKonfliktiFallback();
    }

    function soitaKonfliktiFallback() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.type = 'triangle';
            o.frequency.value = 220;
            g.gain.value = 0.08;
            o.connect(g);
            g.connect(ctx.destination);
            o.start();
            setTimeout(function () {
                o.frequency.value = 160;
            }, 120);
            setTimeout(function () {
                o.stop();
                ctx.close();
            }, 280);
        } catch (e) { /* ignore */ }
    }

    function soitaKonflikti() {
        soita('konflikti');
    }

    function synkkaaMaalissaTila(lista) {
        if (!Array.isArray(lista)) return;
        tila.maalissa_numerot = lista.map(function (n) { return parseInt(n, 10); });
        if (typeof ajanottoSynkkaaMaalissa === 'function' && window.kilpailijat) {
            ajanottoSynkkaaMaalissa(window.kilpailijat, lista);
        }
    }

    window.onMaalissaSynkka = function (uudet, kaikki) {
        synkkaaMaalissaTila(kaikki || []);
    };

    let edellisetKonfliktit = 0;
    let edellinenEro = false;

    function paivitaNakyma() {
        const rivitKaikki = yhdistaRivitNayttoon();
        const sum = laskeYhteenveto(rivitKaikki);
        const leimat = sum.leimat;
        const numerot = sum.numerot;
        const yhd = sum.yhd;
        if (laskuriEl) {
            laskuriEl.textContent = aikaleimaTeksti(leimat) + ' · ' + numerot + ' nroa · ' + yhd + ' yhdistetty';
        }
        if (yhteenvetoEl) {
            const jalki = Math.max(0, leimat - numerot);
            const odottaaAikaa = Math.max(0, numerot - leimat);
            const ero = jalki > 0 || odottaaAikaa > 0;
            if (ero) {
                yhteenvetoEl.classList.add('is-ero');
                yhteenvetoEl.innerHTML =
                    '<div class="jono-ero-luku">' + leimat + ' leimaa · ' + numerot + ' nroa</div>'
                    + (jalki ? '<div>' + jalki + ' ilman numeroa</div>' : '')
                    + (odottaaAikaa ? '<div>' + odottaaAikaa + ' ilman aikaa</div>' : '');
                if (!edellinenEro) soitaKonflikti();
            } else {
                yhteenvetoEl.classList.remove('is-ero');
                yhteenvetoEl.textContent = yhd + ' yhdistetty';
            }
            edellinenEro = ero;
        }

        if (nappiViimeiset) {
            const rivit = rivitKaikki.filter(function (r) {
                return !!r.leima_id && !!r.maaliaika;
            }).slice().reverse();
            if (!rivit.length) {
                nappiViimeiset.innerHTML = '<p class="muted">Ei vielä aikaleimoja</p>';
            } else {
                nappiViimeiset.innerHTML = '<ul class="kouru-lista">' + rivit.map(function (r) {
                    const nro = r.numero ? ('#' + r.numero + ' ' + escapeHtml(r.nimi || '')) : '—';
                    const pending = r._synkka === 'pending';
                    return '<li class="nappi-leima-rivi' + (pending ? ' is-synkka-pending' : ' is-synkka-ok') + '">'
                        + '<span class="jono-sija">' + r.sija + '</span> '
                        + '<strong class="nappi-leima-aika">' + kello(r.maaliaika) + '</strong> '
                        + '<span class="nappi-leima-nro">' + escapeHtml(nro) + '</span> '
                        + '<em class="jono-tila jono-tila-' + r.tila + '">' + tilaLabel(r.tila) + '</em>'
                        + synkkaMerkki(r._synkka || 'ok')
                        + (pending
                            ? ''
                            : ('<button type="button" class="jono-icon jono-icon-del nappi-leima-poista"'
                                + ' data-act="poista-leima" data-id="' + r.leima_id + '"'
                                + ' data-aika="' + escapeHtml(kello(r.maaliaika)) + '"'
                                + ' aria-label="Poista aikaleima">×</button>'))
                        + '</li>';
                }).join('') + '</ul>';
            }
        }

        if (!listaEl) return;
        const rivitAsc = rivitKaikki;
        if (!rivitAsc.length) {
            listaEl.innerHTML = '<p class="muted">Ei vielä rivejä. Syötä ensimmäinen numero.</p>';
            return;
        }

        // Uusimmat ekana (käänteinen sija-järjestys)
        const rivit = rivitAsc.slice().reverse();

        listaEl.innerHTML = '<ul class="jono-lista">' + rivit.map(function (r, i) {
            const pending = r._synkka === 'pending' || !!r._pending;
            const voiSiirtaa = !!r.numero_id && !pending;
            const ascIdx = rivitAsc.length - 1 - i;
            const nimi = r.nimi || (r.numero ? (nimet[String(r.numero)] || '') : '');
            // Näytössä ↑ = myöhemmäksi (alas), ↓ = aiemmaksi (ylos)
            const voiMyohemmin = !pending && ascIdx < rivitAsc.length - 1 && !!rivitAsc[ascIdx + 1].numero_id;
            const voiAiemmin = !pending && ascIdx > 0;
            return '<li class="jono-rivi jono-tila-' + escapeHtml(r.tila)
                + (pending ? ' is-synkka-pending' : ' is-synkka-ok')
                + '" data-id="' + (r.numero_id || '') + '">'
                + '<span class="jono-sija">' + r.sija + '</span>'
                + '<span class="jono-nro">' + (r.numero ? ('#' + r.numero) : '—') + '</span>'
                + '<span class="jono-nimi">' + escapeHtml(nimi) + '</span>'
                + '<span class="jono-aika">' + kello(r.maaliaika) + '</span>'
                + '<span class="jono-status">' + escapeHtml(r.viesti || tilaLabel(r.tila))
                + ' ' + synkkaMerkki(r._synkka || 'ok') + '</span>'
                + (voiSiirtaa ? (
                    '<span class="jono-toiminnot">'
                    + '<button type="button" class="jono-icon" data-act="alas" data-id="' + r.numero_id + '"'
                    + (voiMyohemmin ? '' : ' disabled') + ' aria-label="Siirrä ylös">↑</button>'
                    + '<button type="button" class="jono-icon" data-act="ylos" data-id="' + r.numero_id + '"'
                    + (voiAiemmin ? '' : ' disabled') + ' aria-label="Siirrä alas">↓</button>'
                    + '<button type="button" class="jono-icon" data-act="muokkaa" data-id="' + r.numero_id + '" data-numero="' + r.numero + '" aria-label="Muokkaa">✎</button>'
                    + '<button type="button" class="jono-icon jono-icon-del" data-act="poista" data-id="' + r.numero_id + '" aria-label="Poista">×</button>'
                    + '</span>'
                ) : '<span class="jono-toiminnot"></span>')
                + '</li>';
        }).join('') + '</ul>';
    }

    function otaTila(data) {
        if (!data || data.status === 'error') {
            if (data && data.message) naytaViesti(data.message, 'virhe');
            return;
        }
        const konfliktit = (data.rivit || []).filter(function (r) {
            return r.tila === 'konflikti' || r.tila === 'duplikaatti' || r.tila === 'ei_loydu';
        }).length;
        if (konfliktit > edellisetKonfliktit) {
            soitaKonflikti();
            naytaViesti('Tarkista konflikti / duplikaatti jonossa', 'virhe');
        }
        edellisetKonfliktit = konfliktit;
        tila = data;
        if (Array.isArray(data.maalissa_numerot)) {
            synkkaaMaalissaTila(data.maalissa_numerot);
        } else if (Array.isArray(window.__maalissaNumerot)) {
            synkkaaMaalissaTila(window.__maalissaNumerot);
        }
        // Näytä heti palvelimen data + nykyinen offline-jono; IDB päivittyy perässä
        paivitaNakyma();
        lataaOfflinePending();
        paivitaPeruutus(data.peruutukset || []);
    }

    function paivitaPeruutus(lista) {
        let wrap = document.getElementById('jonoPeruutus');
        if (!wrap) {
            const host = document.querySelector('.nappi-paneeli, .numerot-paneeli');
            if (!host) return;
            wrap = document.createElement('div');
            wrap.id = 'jonoPeruutus';
            wrap.className = 'jono-peruutus';
            host.appendChild(wrap);
        }
        if (!lista.length) {
            wrap.innerHTML = '';
            wrap.hidden = true;
            return;
        }
        wrap.hidden = false;
        wrap.innerHTML = '<button type="button" class="btn-secondary jono-peruutus-btn" data-act="peruutus">'
            + '↩ Peru viimeisin poisto'
            + '</button>'
            + '<span class="muted jono-peruutus-meta">' + lista.length + ' palautettavissa</span>';
    }

    async function haeTila() {
        try {
            const data = await postJson('../api/api_maali_jono.php', jonoRunko({
                toiminto: 'tila',
                laite: typeof laiteId === 'function' ? laiteId() : 'nro'
            }));
            otaTila(data);
        } catch (e) { /* ignore — pidä viimeisin tila + offline-rivit */ }
    }

    function jonoRunko(extra) {
        const body = Object.assign({
            kilpailu_id: kilpailuId
        }, extra || {});
        if (apiToken) body.api_token = apiToken;
        return body;
    }

    async function postJson(url, body) {
        const r = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        let data = {};
        try {
            data = await r.json();
        } catch (parseErr) {
            if (!r.ok) throw new TypeError('Failed to fetch');
            throw parseErr;
        }
        if (!r.ok && data.status !== 'ok') {
            const err = new Error(data.message || 'Virhe');
            err.httpStatus = r.status;
            throw err;
        }
        return data;
    }

    async function leimaa() {
        const maaliaika = typeof paikallinenMaaliaika === 'function'
            ? paikallinenMaaliaika()
            : new Date().toISOString();
        if (btnMaali) {
            btnMaali.classList.add('is-flash');
            setTimeout(function () { btnMaali.classList.remove('is-flash'); }, 180);
        }
        soita('leima');
        naytaViesti('Aikaleima ' + kello(maaliaika.replace('T', ' ')), 'ok');
        const payload = jonoRunko({
            toiminto: 'leima',
            maaliaika: maaliaika,
            laite: (typeof laiteId === 'function' ? laiteId() : 'nappi') + '-nappi',
            offline_api: '../api/api_maali_leima.php'
        });
        try {
            otaTila(await postJson('../api/api_maali_leima.php', payload));
            await lataaOfflinePending();
        } catch (e) {
            if (onVerkkovirhe(e) && typeof tallennaOffline === 'function') {
                try {
                    await tallennaOffline(payload);
                    await lataaOfflinePending();
                    soita('ok');
                    naytaViesti('Aikaleima kirjattu (ei vielä palvelimella): ' + kello(maaliaika.replace('T', ' ')), 'ok');
                    return;
                } catch (err) { /* fall through */ }
            }
            soita('varoitus');
            naytaViesti(e.message || 'Aikaleima epäonnistui', 'virhe');
        }
    }

    async function poistaLeima(id, aikaTeksti) {
        if (sending) return;
        const viesti = aikaTeksti
            ? ('Haluatko varmasti poistaa aikaleiman ' + aikaTeksti + '?')
            : 'Haluatko varmasti poistaa tämän aikaleiman?';
        if (!window.confirm(viesti)) {
            return;
        }
        sending = true;
        try {
            const data = await postJson('../api/api_maali_leima.php', jonoRunko({
                toiminto: 'poista',
                id: id,
                laite: (typeof laiteId === 'function' ? laiteId() : 'nappi') + '-nappi'
            }));
            otaTila(data);
            if (data.peruttu) {
                soita('poisto');
                naytaViesti('Poistettu ' + kello(data.peruttu.maaliaika), 'varoitus');
            }
        } catch (e) {
            naytaViesti(e.message || 'Poisto epäonnistui', 'virhe');
        } finally {
            sending = false;
        }
    }

    async function jonoToiminto(toiminto, extra) {
        if (sending) return;
        sending = true;
        const body = jonoRunko(Object.assign({
            toiminto: toiminto,
            laite: (typeof laiteId === 'function' ? laiteId() : 'nro') + '-numerot',
            offline_api: '../api/api_maali_jono.php'
        }, extra || {}));
        try {
            otaTila(await postJson('../api/api_maali_jono.php', body));
            return true;
        } catch (e) {
            if (toiminto === 'lisaa' && onVerkkovirhe(e) && typeof tallennaOffline === 'function') {
                try {
                    await tallennaOffline(body);
                    await lataaOfflinePending();
                    const nro = extra && extra.numero ? extra.numero : '';
                    soita('ok');
                    naytaViesti('Numero kirjattu (ei vielä palvelimella): #' + nro, 'ok');
                    return 'offline';
                } catch (err) { /* fall through */ }
            }
            soita('varoitus');
            naytaViesti(e.message || 'Toiminto epäonnistui', 'virhe');
            return false;
        } finally {
            sending = false;
        }
    }

    function lisaaNumero() {
        if (!jonoInput) return;
        const nro = String(jonoInput.value || '').replace(/\D/g, '');
        if (!nro) {
            naytaViesti('Syötä numero', 'virhe');
            jonoInput.focus();
            return;
        }
        const n = parseInt(nro, 10);
        if (!nimet[String(n)]) {
            naytaViesti('Numeroa #' + nro + ' ei ole kilpailijalistassa', 'virhe');
            soitaKonflikti();
            jonoInput.value = '';
            jonoInput.focus();
            return;
        }
        const liveMaalissa = Array.isArray(window.__maalissaNumerot)
            ? window.__maalissaNumerot.map(String)
            : [];
        const maalissa = (tila.maalissa_numerot || []).map(String)
            .concat(liveMaalissa);
        if (maalissa.indexOf(String(n)) !== -1) {
            naytaViesti('#' + nro + ' on jo maalissa', 'virhe');
            soitaKonflikti();
            jonoInput.value = '';
            jonoInput.focus();
            return;
        }
        const k = (window.kilpailijat || []).find(function (x) {
            return String(x.numero) === String(n);
        });
        if (k && parseInt(k.maalissa, 10) === 1) {
            naytaViesti('#' + nro + ' on jo maalissa', 'virhe');
            soitaKonflikti();
            jonoInput.value = '';
            jonoInput.focus();
            return;
        }
        const joJonossa = yhdistaRivitNayttoon().some(function (r) {
            return r.numero_id && String(r.numero) === String(n);
        });
        if (joJonossa) {
            naytaViesti('#' + nro + ' on jo jonossa', 'virhe');
            soitaKonflikti();
            jonoInput.value = '';
            jonoInput.focus();
            return;
        }
        jonoInput.value = '';
        jonoInput.focus();
        jonoToiminto('lisaa', { numero: n }).then(function (ok) {
            if (ok === true) {
                soita('ok');
                naytaViesti('Lisätty #' + nro, 'ok');
            }
        });
    }

    if (btnMaali) {
        btnMaali.addEventListener('click', function (ev) {
            ev.preventDefault();
            leimaa();
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.repeat) return;
            if (ev.key === ' ' || ev.key === 'Enter') {
                if (ev.target && (ev.target.tagName === 'INPUT' || ev.target.tagName === 'TEXTAREA')) return;
                ev.preventDefault();
                leimaa();
            }
        });
    }
    if (nappiViimeiset) {
        nappiViimeiset.addEventListener('click', function (ev) {
            const btn = ev.target.closest('[data-act="poista-leima"]');
            if (!btn) return;
            const id = parseInt(btn.getAttribute('data-id'), 10);
            if (!id) return;
            poistaLeima(id, btn.getAttribute('data-aika') || '');
        });
    }
    if (jonoInput) {
        document.getElementById('btnJonoClear').addEventListener('click', function () {
            jonoInput.value = '';
            jonoInput.focus();
        });
        document.getElementById('btnJonoSave').addEventListener('click', lisaaNumero);
        jonoInput.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                lisaaNumero();
            }
        });
        jonoInput.addEventListener('input', function () {
            jonoInput.value = jonoInput.value.replace(/\D/g, '').slice(0, 6);
        });
        jonoInput.focus();
    }
    if (listaEl) {
        listaEl.addEventListener('click', function (ev) {
            const btn = ev.target.closest('[data-act]');
            if (!btn) return;
            const act = btn.getAttribute('data-act');
            const id = parseInt(btn.getAttribute('data-id'), 10);
            if (!id) return;
            if (act === 'ylos' || act === 'alas') {
                jonoToiminto('siirra', { id: id, suunta: act });
            } else if (act === 'poista') {
                const nro = btn.closest('.jono-rivi')
                    ? (btn.closest('.jono-rivi').querySelector('.jono-nro') || {}).textContent
                    : '';
                const viesti = nro && nro !== '—'
                    ? ('Haluatko varmasti poistaa tämän numeron (' + nro + ')?')
                    : 'Haluatko varmasti poistaa tämän numeron?';
                if (!window.confirm(viesti)) {
                    return;
                }
                jonoToiminto('poista', { id: id }).then(function (ok) {
                    if (ok) soita('poisto');
                });
            } else if (act === 'muokkaa') {
                const nyk = btn.getAttribute('data-numero') || '';
                const neu = window.prompt('Korjaa numero', nyk);
                if (neu === null) return;
                const nro = String(neu).replace(/\D/g, '');
                if (!nro) return;
                const n = parseInt(nro, 10);
                if (String(n) !== String(nyk)) {
                    const maalissa = (tila.maalissa_numerot || []).map(String);
                    if (maalissa.indexOf(String(n)) !== -1) {
                        naytaViesti('#' + nro + ' on jo maalissa — ei voi vaihtaa', 'virhe');
                        soitaKonflikti();
                        return;
                    }
                }
                jonoToiminto('muokkaa', { id: id, numero: n });
            }
        });
    }

    lataaOfflinePending().then(function () {
        haeTila();
    });
    setInterval(haeTila, 1500);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            lataaOfflinePending();
            haeTila();
        }
    });
    window.addEventListener('jah-offline-queue', function (ev) {
        paivitaOfflinePending((ev.detail && ev.detail.items) || []);
    });
    document.addEventListener('click', function (ev) {
        const btn = ev.target.closest('[data-act="peruutus"]');
        if (!btn) return;
        if (sending) return;
        jonoToiminto('peruutus', {}).then(function (ok) {
            if (ok) {
                soita('ok');
                naytaViesti('Poisto palautettu', 'ok');
            }
        });
    });
})();
