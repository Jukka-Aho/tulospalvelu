(function () {
    'use strict';
    window.JAH_EMIT_VERSIO = 'automaatti25';

    var proto = window.JahEmitProto;
    var siProto = window.JahSiProto;
    var cfg = window.JAH_EMIT || { kilpailuId: 0, sarjoja: 0 };
    var lukijaTyyppi = 'emit';
    try {
        if (localStorage.getItem('jah-lukija-tyyppi') === 'si') {
            lukijaTyyppi = 'si';
        }
    } catch (eTyyppi) { /* */ }
    var siKone = null;
    var kioski = !!cfg.kioski;
    var kioskiAjastin = null;
    var moodi = 'lahto';
    var portti = null;
    var auki = false;
    var lukijaKatkesi = false;
    var yhdistaKaynnissa = false;
    var yhdistaVahti = null;
    var eiUsbPolyfill = false;
    var puskuri = proto.luoPuskuri();
    var viimeBadge = 0;
    var viimeAika = 0;
    var kesken = false;
    var poistutaan = false;
    var viimeVastaus = null;
    var odottaaEmit = 0;
    var odottaaLeimat = [];
    var lomakeMuokattu = false;
    var kirjoitusJono = Promise.resolve();
    var koodiKorjattu = false;
    var moodiKorjattu = false;
    var saatiinTila = false;
    var nayttiKortin = false;
    var nollaTavuja = 0;
    var loytyiMerkkeja = false;
    var nykyStop = 2;
    var aaniCtx = null;
    var jonoKaynnissa = false;
    var radatPaikallisetOdottavat = false;
    var automaattiAjastin = null;
    var automaattiHaku = 0;
    var automaattiPuhu = true;
    var puheAjastin = null;
    var puheLause = null;
    var puheAvattu = false;
    var korttiOdotus = null;
    var lahetysNro = 0;
    var lahetysOhjain = null;
    try {
        automaattiPuhu = localStorage.getItem('jah-emit-puhe') !== '0';
    } catch (ePuhu) { /* */ }
    var AUTOMAATTI_TYHJENNYS_MS = 16000;

    var tulosEl = document.getElementById('tulos');
    var leimaEl = document.getElementById('leima-lista');
    var viimeisetEl = document.getElementById('viimeiset');
    var yhteysEl = document.getElementById('yhteys-tila');
    var laiteEl = document.getElementById('laite-rivi');
    var ohjeEl = document.getElementById('moodi-ohje');
    var lisaaEl = document.getElementById('lisaa-kentat');
    var kasinEl = document.getElementById('kasin-form');
    var kasinNappi = kasinEl ? kasinEl.querySelector('button') : null;
    var maaliHuom = document.getElementById('maali-huom');
    var yhdistaBtn = document.getElementById('yhdista');
    var lukijaIkoni = document.getElementById('lukija-yhteys');
    var kysyEl = document.getElementById('emit-kysy');
    var ehdotuksetEl = document.getElementById('emit-ehdotukset');
    var ehdotuksetListaEl = document.getElementById('emit-ehdotukset-lista');
    var viimeEhdotukset = [];
    var tarkoitus = '';
    var tallennaNappi = document.getElementById('lisaa-tallenna');

    var rataSana = cfg.iltarasti ? 'rata' : 'sarja';
    var OHJE = {
        lahto: cfg.viesti
            ? 'Lue 1. osuuden kortti lähdössä. Vaihtoon ja maaliin lue kortti Vaihto / maali -tilassa.'
            : ('Lue kortti lähdössä. Uusi kortti kysyy, luetaanko lähteväksi vai ' + (cfg.iltarasti ? 'pois maastosta' : 'maaliin') + '. Jo lähtenyt kortti menee leimantarkastukseen, jos kortilla on rastit.'),
        lisaa: 'Täytä ' + rataSana + ' ja nimi. Tallenna lähtevänä tai maaliin.',
        maali: cfg.viesti
            ? 'Lue vaihtoon tai maaliin tuleva osuus. Rastit tarkistetaan ja aika kirjataan. Seuraavan osuuden lähtö on tämä vaihto.'
            : 'Lue kortti maalissa. Rastit tarkistetaan ja aika otetaan nollaleimauksesta. Uusi kortti kysyy tiedot.',
        automaatti: cfg.viesti
            ? 'Maalinäyttö. Kortin luku näyttää joukkueen, osuuden, ajan ja rastitarkistuksen.'
            : 'Maalinäyttö. Kortin luku näyttää nimen, ajan ja sijoituksen tai hylkäysesityksen. Tyhjenee itsestään.'
    };

    function escapeHtml(text) {
        var d = document.createElement('div');
        d.textContent = text == null ? '' : String(text);
        return d.innerHTML;
    }

    function kioskiSekunnit() {
        var el = document.getElementById('nayttoaika');
        var s = el ? parseInt(el.value, 10) : 5;
        if (!s || s < 1) {
            s = 1;
        }
        if (s > 60) {
            s = 60;
        }
        return s;
    }

    function kioskiTyhja() {
        var el = document.getElementById('emit-yhteenveto');
        if (el) {
            el.className = 'kioski-lue';
            el.textContent = 'Lue kortti';
        }
        if (tulosEl) {
            tulosEl.className = 'kioski-naytto';
        }
    }

    function kioskiAjastaPaluu() {
        clearTimeout(kioskiAjastin);
        kioskiAjastin = setTimeout(kioskiTyhja, kioskiSekunnit() * 1000);
    }

    function kioskiNimi(data) {
        var suku = data.sukunimi ? String(data.sukunimi).trim() : '';
        var etu = data.etunimi ? String(data.etunimi).trim() : '';
        var nimi = (suku + ' ' + etu).trim();
        if (!nimi && data.nimi) {
            nimi = String(data.nimi).trim();
        }
        return nimi;
    }

    function viestiOtsikko(data) {
        if (!data) {
            return '';
        }
        if (data.viesti_otsikko) {
            return String(data.viesti_otsikko).trim();
        }
        var nro = data.joukkue_numero != null ? data.joukkue_numero : data.numero;
        var joukkue = String(data.joukkue_nimi || '').trim();
        var juoksija = kioskiNimi(data);
        var ots = '';
        if (nro != null && nro !== '') {
            ots = String(nro) + ' ';
        }
        ots += joukkue || juoksija || '';
        return ots.trim();
    }

    function viestiOsuusTxt(data) {
        if (!data) {
            return '';
        }
        if (data.osuus_txt) {
            return String(data.osuus_txt);
        }
        var os = parseInt(data.osuus, 10) || 0;
        if (!os) {
            return '';
        }
        var txt = os + '. osuus';
        var juoksija = kioskiNimi(data);
        var joukkue = String(data.joukkue_nimi || '').trim();
        if (juoksija && juoksija !== joukkue) {
            txt += ' · ' + juoksija;
        }
        return txt;
    }

    function viestiHenkiloHtml(data) {
        var html = '<strong>' + escapeHtml(viestiOtsikko(data) || kioskiNimi(data) || '') + '</strong>';
        var osuus = viestiOsuusTxt(data);
        if (osuus) {
            html += '<br><span class="emit-osuus">' + escapeHtml(osuus) + '</span>';
        }
        if (data.sarja) {
            html += '<br><span class="muted">' + escapeHtml(data.sarja) + '</span>';
        }
        if (data.vaihto && data.seuraava_nimi) {
            html += '<br><span class="muted">Seuraava: '
                + escapeHtml(String(data.seuraava_osuus || '')) + '. osuus '
                + escapeHtml(data.seuraava_nimi) + '</span>';
        } else if (data.joukkue_maali) {
            html += '<br><span class="muted">Joukkueen maali</span>';
        }
        if (data.emit) {
            html += '<br><span class="muted">Emit ' + escapeHtml(String(data.emit)) + '</span>';
        }
        return html;
    }

    function tarkistusHtml(data) {
        var t = data && data.tarkistus;
        if (!t) {
            return '';
        }
        var html = '';
        if (t.ok) {
            html += '<br><span class="emit-ok">Rastit OK</span>';
        } else if (t.puuttuvat && t.puuttuvat.length) {
            html += '<br><span class="emit-puute">Puuttuu: ' + escapeHtml(t.puuttuvat.join(', ')) + '</span>';
        }
        if (t.aika_txt) {
            html += '<br><strong class="emit-aika">' + escapeHtml(t.aika_txt) + '</strong>';
            html += t.lahto_malli === 'nollaus'
                ? ' <span class="muted">nollauksesta</span>'
                : ' <span class="muted">lähtöajasta</span>';
            if (t.lahto_txt) {
                html += ' <span class="muted">lähtö ' + escapeHtml(t.lahto_txt) + '</span>';
            }
        } else if (t.lahto_viesti) {
            html += '<br><span class="muted">' + escapeHtml(t.lahto_viesti) + '</span>';
        }
        if (t.keskhyl) {
            html += ' <span class="muted">(' + escapeHtml(String(t.keskhyl)) + ')</span>';
        }
        return html;
    }

    function kioskiHenkilo(data) {
        var el = document.getElementById('emit-yhteenveto');
        if (!el) {
            return;
        }
        var numero = data.numero != null ? String(data.numero) : '';
        var nimi = kioskiNimi(data);
        var kortti = data.emit ? String(data.emit) : '';
        var html = '';
        if (numero) {
            html += '<div class="kioski-numero">' + escapeHtml(numero) + '</div>';
        }
        if (nimi) {
            html += '<div class="kioski-nimi">' + escapeHtml(nimi) + '</div>';
        }
        if (kortti) {
            html += '<div class="kioski-kortti-nro">Kortti ' + escapeHtml(kortti) + '</div>';
        }
        if (data.taso === 'virhe') {
            html += '<div class="kioski-lasna kioski-ei">' + escapeHtml(data.message || 'Ei löydy') + '</div>';
        } else if (data.kilpailija_status && /DNF|DSQ|MP/.test(String(data.kilpailija_status))) {
            html += '<div class="kioski-lasna kioski-ei">' + escapeHtml(data.message || 'Läsnäoloa ei muutettu') + '</div>';
        } else if (data.tarkistus) {
            if (data.tarkistus.ok) {
                html += '<div class="kioski-lasna">Rastit OK'
                    + (data.tarkistus.aika_txt ? ' · ' + escapeHtml(data.tarkistus.aika_txt) : '')
                    + '</div>';
            } else {
                html += '<div class="kioski-lasna kioski-ei">'
                    + escapeHtml(data.tarkistus_viesti || data.message || 'Rasteja puuttuu')
                    + '</div>';
            }
        } else {
            html += '<div class="kioski-lasna">Läsnä</div>';
        }
        el.className = 'kioski-tulos';
        el.innerHTML = html;
        if (tulosEl) {
            tulosEl.className = 'kioski-naytto' + (data.taso === 'virhe' || (data.kilpailija_status && /DNF|DSQ|MP/.test(String(data.kilpailija_status))) ? ' is-virhe' : ' is-lasna');
        }
        kioskiAjastaPaluu();
    }

    function naytaTulos(luokka, html) {
        if (onkoAutomaatti()) {
            if (luokka === 'virhe') {
                naytaAutomaattiVirhe(html);
            }
            return;
        }
        if (cfg.iltarasti && tulosEl) {
            tulosEl.hidden = false;
        }
        if (kioski) {
            if (luokka === 'virhe') {
                var kohde = document.getElementById('emit-yhteenveto');
                if (kohde) {
                    kohde.className = 'kioski-tulos';
                    kohde.innerHTML = '<div class="kioski-lasna kioski-ei">' + html + '</div>';
                }
                if (tulosEl) {
                    tulosEl.className = 'kioski-naytto is-virhe';
                }
                kioskiAjastaPaluu();
            }
            return;
        }
        if (!tulosEl) {
            return;
        }
        tulosEl.className = 'timing-viesti ' + luokka;
        var el = document.getElementById('emit-yhteenveto');
        if (el) {
            el.innerHTML = html;
        }
    }

    function lokiin(rivi) {
        var el = document.getElementById('emit-loki');
        if (!el) {
            return;
        }
        el.textContent += rivi + '\n';
        var rivit = el.textContent.split('\n');
        if (rivit.length > 120) {
            el.textContent = rivit.slice(rivit.length - 90).join('\n');
        }
        el.scrollTop = el.scrollHeight;
    }

    function kuvaaTavut(bytes) {
        var hex = [];
        var teksti = '';
        var n = Math.min(bytes.length, 160);
        var i;
        for (i = 0; i < n; i++) {
            var b = bytes[i];
            hex.push((b < 16 ? '0' : '') + b.toString(16));
            teksti += (b >= 32 && b < 127) ? String.fromCharCode(b) : '.';
        }
        lokiin('VASTAAN ' + bytes.length + ' tavua  ' + hex.join(' ') + (bytes.length > n ? ' …' : ''));
        lokiin('teksti: ' + teksti);
    }

    function onkoSportIdent() {
        return lukijaTyyppi === 'si';
    }

    function asetaLukijaTyyppi(tyyppi) {
        lukijaTyyppi = tyyppi === 'si' ? 'si' : 'emit';
        try {
            localStorage.setItem('jah-lukija-tyyppi', lukijaTyyppi);
        } catch (e) { /* */ }
        paivitaTyyppiNapit();
        if (typeof paivitaAutomaattiYhteys === 'function') {
            paivitaAutomaattiYhteys();
        }
    }

    function paivitaTyyppiNapit() {
        document.querySelectorAll('.emit-lukija-tyyppi').forEach(function (btn) {
            btn.classList.toggle('is-valittu', btn.getAttribute('data-tyyppi') === lukijaTyyppi);
        });
    }

    function usbLaskuri() {
        if (!portti) {
            return 0;
        }
        if (portti.usbTilat) {
            return portti.usbTilat;
        }
        if (portti.device_ && portti.device_.usbTilat) {
            return portti.device_.usbTilat;
        }
        return 0;
    }

    function paivitaUsb() {
        var el = document.getElementById('emit-usb');
        if (!el || cfg.iltarasti) {
            return;
        }
        if (!auki) {
            el.textContent = '';
            return;
        }
        var datat = puskuri && puskuri.tavut ? puskuri.tavut : 0;
        var rivi = 'USB-paketteja ' + usbLaskuri() + ', datatavuja ' + datat + ', nopeus ' + baudArvo();
        if (nollaTavuja) {
            rivi += ', nollia ' + nollaTavuja;
        }
        el.textContent = rivi;
        if (laiteEl && auki && !nayttiKortin) {
            laiteEl.textContent = 'Tavuja ' + datat + ' · ' + baudArvo() + ', ' + nykyStop + ' stop';
        }
    }

    function laiteRivit() {
        var dev = portti && portti.device_;
        var rivit = [];
        rivit.push('Ajuri ' + (portti && portti.constructor ? portti.constructor.name : '?'));
        if (!dev) {
            rivit.push('Selaimen sarjaportti, nopeus ' + baudArvo());
            return rivit;
        }
        function hex4(n) {
            return (n || 0).toString(16).padStart(4, '0');
        }
        rivit.push('USB ' + hex4(dev.vendorId) + ':' + hex4(dev.productId)
            + (dev.productName ? ' ' + dev.productName : '')
            + (dev.manufacturerName ? ' / ' + dev.manufacturerName : ''));
        var conf = dev.configuration;
        if (conf && conf.interfaces) {
            conf.interfaces.forEach(function (iface) {
                var alt = iface.alternates && iface.alternates[0];
                if (!alt) {
                    return;
                }
                var eps = (alt.endpoints || []).map(function (e) {
                    return e.direction + ' ' + e.endpointNumber + ' ' + e.type + ' ' + e.packetSize;
                }).join(', ');
                rivit.push('Liitäntä ' + iface.interfaceNumber + ' luokka ' + alt.interfaceClass + ' · ' + eps);
            });
        }
        return rivit;
    }

    async function vaihdaNopeus(baud) {
        if (!portti) {
            return;
        }
        if (typeof portti.setBaud === 'function') {
            await portti.setBaud(baud);
            return;
        }
        if (typeof portti.reconfigure === 'function') {
            await portti.reconfigure({ baudRate: baud });
        }
    }

    function avaaAani() {
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) {
                return null;
            }
            if (!aaniCtx || aaniCtx.state === 'closed') {
                aaniCtx = new AC();
            }
            if (aaniCtx.state === 'suspended' && aaniCtx.resume) {
                aaniCtx.resume();
            }
            return aaniCtx;
        } catch (e) {
            return null;
        }
    }

    function soitaTaajuus(ctx, hz, kesto, alku) {
        var o = ctx.createOscillator();
        var g = ctx.createGain();
        o.type = 'sine';
        o.frequency.value = hz;
        g.gain.setValueAtTime(0.0001, alku);
        g.gain.exponentialRampToValueAtTime(0.12, alku + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, alku + Math.max(0.03, kesto));
        o.connect(g);
        g.connect(ctx.destination);
        o.start(alku);
        o.stop(alku + kesto + 0.02);
    }

    function soitaAani(tyyppi) {
        if (!automaattiPuhu) {
            return;
        }
        var ctx = avaaAani();
        if (!ctx) {
            return;
        }
        try {
            var now = ctx.currentTime;
            if (tyyppi === 'ok') {
                soitaTaajuus(ctx, 880, 0.12, now);
            } else if (tyyppi === 'esitys') {
                soitaTaajuus(ctx, 523, 0.14, now);
                soitaTaajuus(ctx, 392, 0.18, now + 0.16);
            } else if (tyyppi === 'varoitus') {
                soitaTaajuus(ctx, 440, 0.22, now);
            } else {
                soitaTaajuus(ctx, 220, 0.28, now);
            }
        } catch (e) { /* ei ääntä */ }
    }

    function aaniVastauksesta(data, luokka) {
        var k = String((data && data.keskhyl) || '').toUpperCase().charAt(0);
        if (data && (data.esitys || k === 'E')) {
            return 'esitys';
        }
        if (data && data.tarkistus && data.tarkistus.ok === false && luokka !== 'ok') {
            return 'esitys';
        }
        return luokka === 'ok' ? 'ok' : luokka;
    }

    function jonoAvain() {
        return 'jah_emit_jono_' + (cfg.kilpailuId || 0);
    }

    function idbAvaa() {
        return new Promise(function (ok, ei) {
            if (!window.indexedDB) {
                ei(new Error('ei idb'));
                return;
            }
            var req = indexedDB.open('jah-emit-offline', 1);
            req.onupgradeneeded = function () {
                if (!req.result.objectStoreNames.contains('meta')) {
                    req.result.createObjectStore('meta');
                }
            };
            req.onsuccess = function () { ok(req.result); };
            req.onerror = function () { ei(req.error); };
        });
    }

    function idbLue(avain) {
        return idbAvaa().then(function (db) {
            return new Promise(function (ok, ei) {
                var q = db.transaction('meta').objectStore('meta').get(avain);
                q.onsuccess = function () { ok(q.result); };
                q.onerror = function () { ei(q.error); };
            });
        }).catch(function () { return undefined; });
    }

    function idbKirjoita(avain, arvo) {
        return idbAvaa().then(function (db) {
            return new Promise(function (ok, ei) {
                var q = db.transaction('meta', 'readwrite').objectStore('meta').put(arvo, avain);
                q.onsuccess = function () { ok(); };
                q.onerror = function () { ei(q.error); };
            });
        }).catch(function () {});
    }

    function pyydaPysyvaTallennus() {
        if (navigator.storage && navigator.storage.persist) {
            navigator.storage.persist().catch(function () {});
        }
    }

    function lueJono() {
        try {
            var raw = localStorage.getItem(jonoAvain());
            var arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    }

    function tallennaJono(arr) {
        try {
            localStorage.setItem(jonoAvain(), JSON.stringify(arr));
        } catch (e) { /* private mode */ }
        idbKirjoita(jonoAvain(), arr);
        talletaIltaSnapshot();
        paivitaJonoBanneri();
    }

    function talletaIltaSnapshot() {
        idbKirjoita('ilta-' + (cfg.kilpailuId || 0), {
            radat: cfg.radat || [],
            osanottajat: cfg.osanottajat || [],
            nimet: cfg.nimet || [],
            at: Date.now()
        });
    }

    function palautaIltaSnapshot() {
        var alku = Promise.resolve();
        if (window.jahRataOffline && cfg.iltarasti && cfg.kilpailuId) {
            alku = window.jahRataOffline.siemen(cfg.kilpailuId, cfg.radat || []).then(function () {
                return window.jahRataOffline.lue(cfg.kilpailuId);
            }).then(function (tila) {
                if (tila && tila.lista && tila.lista.length) {
                    cfg.radat = tila.lista;
                }
                radatPaikallisetOdottavat = !!(tila && tila.dirty && tila.pending && tila.pending.length);
            });
        }
        return alku.then(function () {
            return idbLue('ilta-' + (cfg.kilpailuId || 0));
        }).then(function (s) {
            if (!s || typeof s !== 'object') {
                return;
            }
            if ((!cfg.radat || !cfg.radat.length) && s.radat && s.radat.length) {
                cfg.radat = s.radat;
            }
            if ((!cfg.osanottajat || !cfg.osanottajat.length) && s.osanottajat && s.osanottajat.length) {
                cfg.osanottajat = s.osanottajat;
            }
            if ((!cfg.nimet || !cfg.nimet.length) && s.nimet && s.nimet.length) {
                cfg.nimet = s.nimet;
            }
        });
    }

    function jonoEl() {
        var el = document.getElementById('emit-jono');
        if (el) {
            return el;
        }
        el = document.createElement('p');
        el.id = 'emit-jono';
        el.className = 'emit-jono';
        el.hidden = true;
        var kohde = document.querySelector('.emit-yla');
        if (kohde && kohde.parentNode) {
            kohde.parentNode.insertBefore(el, kohde.nextSibling);
        } else if (document.body) {
            document.body.insertBefore(el, document.body.firstChild);
        }
        return el;
    }

    function paivitaJonoBanneri() {
        var n = lueJono().length;
        var el = jonoEl();
        if (!el) {
            return;
        }
        if (n < 1) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.hidden = false;
        el.textContent = 'Jonossa ' + n + ' lukua. Lähetetään kun verkko palaa.';
    }

    function jonoon(body) {
        var arr = lueJono();
        arr.push({ t: Date.now(), body: body });
        if (arr.length > 200) {
            arr = arr.slice(arr.length - 200);
        }
        tallennaJono(arr);
    }

    function emitOnMaastossa(h) {
        var k = String((h && h.keskhyl) || '').trim().charAt(0).toUpperCase();
        return k === '' || k === '-';
    }

    function haeOsanottajaEmitilla(emit) {
        var n = parseInt(emit, 10);
        var lista = cfg.osanottajat || [];
        var i;
        if (!n) {
            return null;
        }
        for (i = 0; i < lista.length; i++) {
            if (parseInt(lista[i].emit, 10) === n) {
                return lista[i];
            }
        }
        return null;
    }

    function paivitaPaikallinenOsanottaja(h) {
        if (!h) {
            return;
        }
        cfg.osanottajat = cfg.osanottajat || [];
        var i;
        if (h.id) {
            for (i = 0; i < cfg.osanottajat.length; i++) {
                if (cfg.osanottajat[i].id === h.id) {
                    cfg.osanottajat[i] = h;
                    talletaIltaSnapshot();
                    return;
                }
            }
        }
        if (h.emit) {
            for (i = 0; i < cfg.osanottajat.length; i++) {
                if (parseInt(cfg.osanottajat[i].emit, 10) === parseInt(h.emit, 10)) {
                    cfg.osanottajat[i] = h;
                    talletaIltaSnapshot();
                    return;
                }
            }
        }
        cfg.osanottajat.push(h);
        talletaIltaSnapshot();
    }

    function paikallinenVastaus(body) {
        var emit = parseInt(body.emit, 10) || 0;
        var leimat = body.leimat || [];
        var h = haeOsanottajaEmitilla(emit);
        var sarjaId = parseInt(body.sarja_id, 10) || (h ? h.sarja_id : 0);
        var rata = valitunRadan(sarjaId);
        var vert = rata && rata.koodit && rata.koodit.length
            ? vertaaLeimatRataan(rata.koodit, leimat, rata.sammunut)
            : null;
        var ok = !!(vert && vert.ok);
        var kayta = body.moodi || 'lue';
        var hMaastossa = !!(h && emitOnMaastossa(h));
        if ((!h || !hMaastossa) && (kayta === 'lue' || kayta === 'maali' || kayta === 'lahto')) {
            if (!(onkoAutomaatti() && h)) {
                return {
                    taso: 'ok',
                    kysy: true,
                    jono: true,
                    emit: emit,
                    leimat: leimat,
                    ehdotukset: (cfg.nimet || []).filter(function (n) {
                        return parseInt(n.emit, 10) === emit;
                    }),
                    message: h
                        ? 'Tämä kortti on jo luettu pois maastosta. Luetaanko lähteväksi vai pois maastosta?'
                        : 'Tuntematon kortti. Tallennettu jonoon.'
                };
            }
        }
        if (kayta === 'tallenna' || kayta === 'maastoon' || kayta === 'lisaa') {
            h = {
                id: h && h.id ? h.id : 0,
                emit: emit,
                etunimi: body.etunimi || (h && h.etunimi) || '',
                sukunimi: body.sukunimi || (h && h.sukunimi) || '',
                nimi: ((body.etunimi || '') + ' ' + (body.sukunimi || '')).trim() || (h && h.nimi) || '',
                seura: body.seura || (h && h.seura) || '',
                sarja_id: sarjaId,
                rata: (rata && rata.nimi) || (h && h.rata) || '',
                keskhyl: '-'
            };
            paivitaPaikallinenOsanottaja(h);
        }
        var keskhyl = ok ? 'T' : 'E';
        if (body.paatos) {
            keskhyl = String(body.paatos).toUpperCase().charAt(0);
        }
        if (h) {
            h.keskhyl = keskhyl;
            paivitaPaikallinenOsanottaja(h);
        }
        return {
            taso: 'ok',
            jono: true,
            emit: emit,
            kilpailija_id: h ? h.id : 0,
            etunimi: h ? h.etunimi : (body.etunimi || ''),
            sukunimi: h ? h.sukunimi : (body.sukunimi || ''),
            nimi: h ? h.nimi : '',
            seura: h ? h.seura : '',
            sarja: h ? (h.rata || h.sarja) : ((rata && rata.nimi) || ''),
            sarja_id: sarjaId,
            keskhyl: keskhyl,
            esitys: keskhyl === 'E',
            leimat: leimat,
            radat: cfg.radat || [],
            tarkistus: (function () {
                if (!vert) {
                    return null;
                }
                var aikaSec = laskeAikaSecLeimoista(leimat);
                return {
                    ok: ok,
                    puuttuvat: (vert.puuttuvat || []).map(function (p) { return p.koodi; }),
                    koodit: vert.koodit,
                    oikeat: vert.oikeat,
                    sammunut: rata.sammunut || [],
                    leimat: vert.leimat,
                    sarja_id: sarjaId,
                    aika_sec: aikaSec,
                    aika_txt: muotoileAikaSec(aikaSec)
                };
            })(),
            message: 'Ei verkkoa. Tulos puhelimessa, lähtee kun yhteys palaa.'
        };
    }

    function onkoVerkko() {
        return navigator.onLine !== false;
    }

    function onkoVerkkovirhe(err) {
        if (!onkoVerkko()) {
            return true;
        }
        var s = String((err && err.message) || err || '');
        return /Failed to fetch|NetworkError|Load failed|verkkovirhe/i.test(s);
    }

    async function lahetaFetch(body) {
        var ohjain = typeof AbortController === 'function' ? new AbortController() : null;
        var t = setTimeout(function () {
            if (ohjain) {
                try { ohjain.abort(); } catch (eTo) { /* */ }
            }
        }, 8000);
        try {
            var resp = await fetch('/api/api_emit_luku.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body),
                signal: ohjain ? ohjain.signal : undefined
            });
            var teksti = await resp.text();
            var data = null;
            try {
                data = teksti ? JSON.parse(teksti) : null;
            } catch (e) {
                throw new Error(resp.ok ? 'Virheellinen vastaus.' : 'Palvelinvirhe.');
            }
            if (!data) {
                throw new Error('Palvelinvirhe.');
            }
            return data;
        } finally {
            clearTimeout(t);
        }
    }

    async function tyhjennaJono() {
        if (jonoKaynnissa || kesken || !onkoVerkko()) {
            return;
        }
        jonoKaynnissa = true;
        try {
            if (window.jahRataOffline && cfg.iltarasti && cfg.kilpailuId) {
                try {
                    var rataTila = await window.jahRataOffline.lahetaJonossa(cfg.kilpailuId);
                    if (rataTila && rataTila.lista && rataTila.lista.length) {
                        cfg.radat = rataTila.lista;
                    }
                    radatPaikallisetOdottavat = false;
                } catch (rataVirhe) {
                    return;
                }
            }
            var arr = lueJono();
            while (arr.length) {
                var item = arr[0];
                var data;
                try {
                    data = await lahetaFetch(item.body);
                } catch (e) {
                    break;
                }
                arr.shift();
                tallennaJono(arr);
                kasitteleVastaus(data, item.body);
                if (data && data.kysy) {
                    break;
                }
            }
        } finally {
            jonoKaynnissa = false;
            paivitaJonoBanneri();
        }
    }

    function piilotaKysely() {
        if (kysyEl) {
            kysyEl.hidden = true;
        }
    }

    function piilotaEhdotukset() {
        if (ehdotuksetEl) {
            ehdotuksetEl.hidden = true;
        }
        if (ehdotuksetListaEl) {
            ehdotuksetListaEl.innerHTML = '';
        }
        naytaMaksu(null);
    }

    function asetaEhdotukset(lista) {
        viimeEhdotukset = Array.isArray(lista) ? lista.slice() : [];
    }

    function taytaHenkiloEhdotuksesta(h) {
        var etu = document.getElementById('etunimi');
        var suku = document.getElementById('sukunimi');
        var seura = document.getElementById('seura');
        if (etu) {
            etu.value = (h && h.etunimi) || '';
        }
        if (suku) {
            suku.value = (h && h.sukunimi) || '';
        }
        if (seura) {
            seura.value = (h && h.seura) || '';
        }
        var lainaEl = document.getElementById('lainakortti');
        if (lainaEl && h && typeof h.laina !== 'undefined') {
            lainaEl.checked = !!h.laina;
        }
        naytaMaksu(h);
        lomakeMuokattu = false;
        merkitseEhdotusValituksi(h);
    }

    function naytaMaksu(h) {
        var el = document.getElementById('emit-maksu');
        if (!el) {
            return;
        }
        var teksti = (h && h.maksu) || '';
        if (!teksti && h && h.lahde === 'rastilippu') {
            if (h.maksu_ok === true || h.maksu_ok === 1) {
                teksti = 'Maksettu';
            } else if (h.maksu_ok === false || h.maksu_ok === 0) {
                teksti = 'Ei maksettu';
            }
        }
        if (!teksti) {
            el.hidden = true;
            el.textContent = '';
            el.classList.remove('is-ok', 'is-ei');
            return;
        }
        el.hidden = false;
        el.textContent = teksti;
        el.classList.toggle('is-ok', h && (h.maksu_ok === true || h.maksu_ok === 1));
        el.classList.toggle('is-ei', h && (h.maksu_ok === false || h.maksu_ok === 0));
    }

    function merkitseEhdotusValituksi(h) {
        if (!ehdotuksetListaEl) {
            return;
        }
        var avain = ((h && h.sukunimi) || '') + '|' + ((h && h.etunimi) || '');
        ehdotuksetListaEl.querySelectorAll('.emit-ehdotus').forEach(function (btn) {
            btn.classList.toggle('is-valittu', btn.getAttribute('data-avain') === avain);
        });
    }

    function naytaEhdotukset(emit) {
        if (!cfg.iltarasti || !ehdotuksetEl || !ehdotuksetListaEl) {
            return;
        }
        if (!viimeEhdotukset.length) {
            piilotaEhdotukset();
            return;
        }
        ehdotuksetListaEl.innerHTML = '';
        var ots = ehdotuksetEl.querySelector('.emit-ehdotukset-otsikko');
        var rl = viimeEhdotukset.some(function (h) {
            return h && h.lahde === 'rastilippu';
        });
        if (ots) {
            ots.textContent = rl ? 'Rastilipun ilmoittautuneet' : 'Tämän kortin tallennetut nimet';
        }
        viimeEhdotukset.forEach(function (h) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'emit-ehdotus';
            btn.setAttribute('data-avain', (h.sukunimi || '') + '|' + (h.etunimi || ''));
            btn.textContent = h.label || h.nimi || ((h.etunimi || '') + ' ' + (h.sukunimi || '')).trim();
            btn.addEventListener('click', function () {
                taytaHenkiloEhdotuksesta(h);
                if (lisaaEl) {
                    lisaaEl.hidden = false;
                }
            });
            ehdotuksetListaEl.appendChild(btn);
        });
        ehdotuksetEl.hidden = false;
        var sukuEl = document.getElementById('sukunimi');
        if (!sukuEl || !sukuEl.value) {
            taytaHenkiloEhdotuksesta(viimeEhdotukset[0]);
        } else {
            merkitseEhdotusValituksi({
                etunimi: document.getElementById('etunimi') ? document.getElementById('etunimi').value : '',
                sukunimi: sukuEl.value
            });
        }
    }

    function naytaKysely(emit, leimat) {
        odottaaEmit = emit;
        odottaaLeimat = leimat || [];
        tarkoitus = '';
        piilotaEhdotukset();
        if (lisaaEl) {
            lisaaEl.hidden = true;
        }
        var paatosEl = document.getElementById('emit-paatos');
        if (paatosEl) {
            paatosEl.hidden = true;
        }
        if (kysyEl) {
            kysyEl.hidden = false;
        }
        var idEl = document.getElementById('kilpailija-id');
        if (idEl) {
            idEl.value = '';
        }
        var emitEl = document.getElementById('lisaa-emit');
        if (emitEl) {
            emitEl.textContent = 'Emit ' + emit;
        }
    }

    function avaaLisays(suunta) {
        tarkoitus = suunta === 'maali' ? 'maali' : 'lahto';
        piilotaKysely();
        if (cfg.iltarasti) {
            asetaMoodi(tarkoitus === 'maali' ? 'maali' : 'lahto', { sailo: true, lomake: true });
        }
        lomakeMuokattu = false;
        ['etunimi', 'sukunimi', 'seura', 'kilpailija-id'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.value = '';
            }
        });
        if (tallennaNappi && !cfg.iltarasti) {
            tallennaNappi.textContent = tarkoitus === 'maali' ? 'Tallenna maaliin' : 'Tallenna lähtöön';
        }
        if (lisaaEl) {
            lisaaEl.hidden = false;
        }
        naytaEhdotukset(odottaaEmit);
        if (cfg.iltarasti && suunta === 'maali' && odottaaLeimat && odottaaLeimat.length) {
            naytaLeimat(odottaaLeimat, null);
        } else if (cfg.iltarasti && leimaEl) {
            leimaEl.hidden = true;
            leimaEl.innerHTML = '';
        }
        var suku = document.getElementById('sukunimi');
        if (suku && !suku.value) {
            suku.focus();
        }
    }

    function asetaMoodi(uusi, opts) {
        if (cfg.automaattiSivu) {
            uusi = 'automaatti';
        }
        if (cfg.vainMaali && uusi !== 'automaatti') {
            uusi = 'maali';
        }
        if (uusi === 'lisaa') {
            uusi = 'lahto';
        }
        if (uusi === 'automaatti' && !cfg.automaattiSivu) {
            uusi = 'maali';
        }
        moodi = uusi;
        if (kioski) {
            return;
        }
        var sailo = !!(opts && opts.sailo);
        document.querySelectorAll('.emit-moodit .emit-moodi').forEach(function (btn) {
            btn.classList.toggle('is-valittu', btn.getAttribute('data-moodi') === uusi);
        });
        if (ohjeEl) {
            ohjeEl.textContent = OHJE[uusi] || '';
        }
        if (!sailo) {
            piilotaKysely();
        }
        if (lisaaEl && !cfg.iltarasti) {
            lisaaEl.hidden = true;
            lomakeMuokattu = false;
            odottaaEmit = 0;
            odottaaLeimat = [];
            tarkoitus = '';
        }
        if (cfg.iltarasti) {
            tarkoitus = (uusi === 'maali' || uusi === 'automaatti') ? 'maali' : 'lahto';
            document.body.classList.toggle('is-leimantarkistus', uusi === 'maali' || uusi === 'automaatti');
            asetaAutomaattiNakyma(uusi === 'automaatti');
            if (!sailo) {
                paivitaIltarastiNakyma();
                piilotaEhdotukset();
            } else if (lisaaEl && !opts.lomake && (uusi === 'maali' || uusi === 'automaatti')) {
                lisaaEl.hidden = true;
            }
        }
        if (kasinEl) {
            kasinEl.hidden = cfg.iltarasti || uusi === 'maali';
        }
        if (maaliHuom) {
            maaliHuom.hidden = uusi !== 'maali';
        }
        if (kasinNappi) {
            kasinNappi.textContent = 'Kirjaa';
        }
        try {
            localStorage.setItem('jah-emit-moodi', uusi);
        } catch (e) { /* private mode */ }
    }

    function baudArvo() {
        var el = document.getElementById('baud');
        return el ? (parseInt(el.value, 10) || 9600) : 9600;
    }

    function asetaYhteys(teksti) {
        if (cfg.iltarasti) {
            return;
        }
        var tekstiEl = document.getElementById('yhteys-teksti');
        if (tekstiEl) {
            tekstiEl.textContent = teksti;
            return;
        }
        if (yhteysEl) {
            yhteysEl.textContent = teksti;
        }
    }

    function paivitaNapit() {
        var ok = auki;
        ['kysy-tila', 'synkkaa-kello', 'koodi-250', 'koodi-248'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.disabled = !ok;
            }
        });
        if (cfg.iltarasti) {
            if (yhdistaBtn) {
                yhdistaBtn.hidden = auki;
                yhdistaBtn.classList.toggle('is-irti', !auki && lukijaKatkesi);
                yhdistaBtn.textContent = (!auki && lukijaKatkesi)
                    ? 'Yhdistä lukija uudelleen'
                    : 'Yhdistä lukija';
            }
            if (lukijaIkoni) {
                lukijaIkoni.hidden = !auki;
                lukijaIkoni.textContent = auki ? 'Lukija yhdistetty' : 'Lukija irti';
            }
        } else if (yhdistaBtn) {
            yhdistaBtn.textContent = auki
                ? (kioski ? 'Katkaise lukija yhteys' : 'Katkaise USB')
                : 'Yhdistä USB-lukija';
            yhdistaBtn.classList.toggle('is-auki', auki);
        }
        if (yhteysEl) {
            yhteysEl.className = 'lukija-status' + (auki ? ' active' : '');
        }
        if (typeof paivitaAutomaattiYhteys === 'function') {
            paivitaAutomaattiYhteys();
        }
        ilmoitaKuoriUsb();
    }

    function ilmoitaKuoriUsb() {
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'jah-lukija', usb: !!auki }, location.origin);
            }
        } catch (eKuoriUsb) { /* */ }
    }

    function leimaRiviHtml(l, nroPakko) {
        var tila = l.tila || 'ok';
        var virhe = tila === 'puuttuu' || tila === 'vaara';
        var luokka = virhe ? 'emit-leima-virhe' : (tila === 'ylimaarainen' ? 'emit-leima-extra' : (tila === 'sammunut' ? 'emit-leima-sammunut' : 'emit-leima-ok'));
        var koodi = l.koodi === 248 ? 'Maali' : String(l.koodi);
        var nro = l.nro != null ? l.nro : nroPakko;
        var html = '<li class="' + luokka + '"'
            + (virhe ? ' aria-label="' + escapeHtml(koodi + ' ' + (l.teksti || tila)) + '"' : '')
            + '>';
        if (nro) {
            html += '<span class="emit-leima-nro">' + escapeHtml(String(nro)) + '.</span> ';
        }
        html += '<strong>' + escapeHtml(koodi) + '</strong> ';
        if (l.teksti) {
            html += '<span class="emit-leima-tila">' + escapeHtml(l.teksti) + '</span>';
        } else if (l.aika_txt || l.kello) {
            html += '<span class="emit-leima-aika">' + escapeHtml(l.aika_txt || l.kello || '') + '</span>';
        }
        if (l.aika_sec && !l.aika_txt) {
            html += ' <span class="muted">' + l.aika_sec + ' s</span>';
        }
        html += '</li>';
        return html;
    }

    function valitunRadan(sarjaId) {
        var id = sarjaId;
        if (id == null || id === '') {
            var sel = document.getElementById('sarja');
            id = sel ? sel.value : 0;
        }
        var radat = (cfg.radat || []).length ? cfg.radat : [];
        var i;
        for (i = 0; i < radat.length; i++) {
            if (String(radat[i].id) === String(id) && radat[i].koodit && radat[i].koodit.length) {
                return radat[i];
            }
        }
        if (radat.length === 1 && radat[0].koodit) {
            return radat[0];
        }
        return { koodit: [], sammunut: [] };
    }

    function valitunRadanKoodit(sarjaId) {
        return valitunRadan(sarjaId).koodit || [];
    }

    function valitunRadanSammunut(sarjaId) {
        return valitunRadan(sarjaId).sammunut || [];
    }

    function leimaOnLukija(koodi) {
        return koodi === 240 || koodi === 243 || koodi === 244 || koodi === 250 || koodi === 253 || koodi === 254;
    }

    function vertaaLeimatRataan(rataKoodit, leimat, sammunutLiput) {
        var koodit = [];
        var sammunut = [];
        (rataKoodit || []).forEach(function (k, ix) {
            var n = parseInt(k, 10);
            if (n > 0) {
                koodit.push(n);
                var lippu = (sammunutLiput || [])[ix];
                sammunut.push(lippu === true || lippu === 1 || lippu === '1');
            }
        });
        var puhd = [];
        (leimat || []).forEach(function (l) {
            var k = parseInt(l && l.koodi, 10);
            if (!k || leimaOnLukija(k) || (k > 239 && k !== 248)) {
                return;
            }
            puhd.push(l);
        });
        var n = koodit.length;
        var oikeat = [];
        var osumaIx = [];
        var kaytetty = [];
        var tulkinta = [];
        var nroKartta = [];
        var vapaajarj = false;
        var j = 0;
        var i;
        var p;
        var nro = 0;
        for (i = 0; i < n; i++) {
            oikeat[i] = false;
            osumaIx[i] = -1;
            if (koodit[i] !== 10000 && koodit[i] !== 10001) {
                nro += 1;
                nroKartta[i] = nro;
            }
        }
        for (p = 0; p < puhd.length; p++) {
            kaytetty[p] = false;
            tulkinta[p] = 0;
        }
        for (i = 0; i < n; i++) {
            var koodi = koodit[i];
            if (koodi === 10000 || koodi === 10001) {
                vapaajarj = (10001 - koodi) !== 0;
                oikeat[i] = true;
                continue;
            }
            var osuma = -1;
            if (vapaajarj) {
                for (p = 0; p < puhd.length; p++) {
                    if (!kaytetty[p] && parseInt(puhd[p].koodi, 10) === koodi) {
                        kaytetty[p] = true;
                        osuma = p;
                        break;
                    }
                }
            } else {
                for (p = j; p < puhd.length; p++) {
                    if (parseInt(puhd[p].koodi, 10) === koodi) {
                        kaytetty[p] = true;
                        j = p + 1;
                        osuma = p;
                        break;
                    }
                }
            }
            oikeat[i] = osuma >= 0;
            osumaIx[i] = osuma;
            if (osuma >= 0) {
                tulkinta[osuma] = nroKartta[i] || 0;
            }
        }
        var kurssilla = {};
        for (i = 0; i < n; i++) {
            if (koodit[i] !== 10000 && koodit[i] !== 10001) {
                kurssilla[koodit[i]] = true;
            }
        }
        var puuttuvat = [];
        var sammuneet = [];
        for (i = 0; i < n; i++) {
            if (oikeat[i]) {
                continue;
            }
            var rivi = { koodi: koodit[i], nro: nroKartta[i] || null };
            if (sammunut[i]) {
                sammuneet.push(rivi);
            } else {
                puuttuvat.push(rivi);
            }
        }
        return {
            koodit: koodit,
            sammunut: sammunut,
            oikeat: oikeat,
            osumaIx: osumaIx,
            tulkinta: tulkinta,
            kaytetty: kaytetty,
            leimat: puhd,
            puuttuvat: puuttuvat,
            sammuneet: sammuneet,
            kurssilla: kurssilla,
            ok: puuttuvat.length === 0 && n > 0
        };
    }

    function leimaKelloTeksti(l) {
        if (l.kello || l.aika_txt) {
            return l.kello || l.aika_txt;
        }
        var sec = l.kello_sec != null && l.kello_sec !== '' ? parseInt(l.kello_sec, 10) : NaN;
        if (isNaN(sec) || sec < 0) {
            return '';
        }
        var h = Math.floor(sec / 3600) % 24;
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return pad(h) + ':' + pad(m) + ':' + pad(s);
    }

    function naytaLeimat(leimat, tarkistus) {
        if (!leimaEl) {
            return;
        }
        var lahde = (leimat && leimat.length) ? leimat : (odottaaLeimat || []);
        var sarjaId = (tarkistus && tarkistus.sarja_id) || (viimeVastaus && viimeVastaus.sarja_id);
        var rataKoodit = (tarkistus && tarkistus.koodit && tarkistus.koodit.length)
            ? tarkistus.koodit
            : valitunRadanKoodit(sarjaId);
        var sammunutLiput = (tarkistus && tarkistus.sammunut && tarkistus.sammunut.length)
            ? tarkistus.sammunut
            : valitunRadanSammunut(sarjaId);
        var vert = rataKoodit.length ? vertaaLeimatRataan(rataKoodit, lahde, sammunutLiput) : null;
        var html = '';
        if (lahde.length) {
            html += '<p class="emit-leimat-otsikko">Kortin leimat</p><ol>';
            lahde.forEach(function (l) {
                var koodi = parseInt(l && l.koodi, 10);
                if (!koodi) {
                    return;
                }
                if (leimaOnLukija(koodi)) {
                    return;
                }
                var tila = 'ok';
                var teksti = '';
                var rastiNro = null;
                if (vert) {
                    var idx = -1;
                    var p;
                    for (p = 0; p < vert.leimat.length; p++) {
                        if (vert.leimat[p] === l || (vert.leimat[p].koodi == l.koodi && vert.leimat[p].kello_sec == l.kello_sec && !vert.leimat[p]._naytetty)) {
                            idx = p;
                            vert.leimat[p]._naytetty = true;
                            break;
                        }
                    }
                    if (idx >= 0 && vert.tulkinta[idx] > 0) {
                        tila = 'ok';
                        rastiNro = vert.tulkinta[idx];
                    } else if (vert.kurssilla[koodi]) {
                        tila = 'ylimaarainen';
                        teksti = 'väärässä paikassa';
                    } else {
                        tila = 'vaara';
                        teksti = 'väärä';
                    }
                }
                html += leimaRiviHtml({
                    nro: rastiNro,
                    koodi: koodi,
                    kello: leimaKelloTeksti(l),
                    aika_txt: l.aika_txt || '',
                    aika_sec: l.aika_sec,
                    tila: tila,
                    teksti: teksti
                });
            });
            html += '</ol>';
        }
        if (vert && vert.puuttuvat.length) {
            html += '<p class="emit-leimat-otsikko">Puuttuu radalta</p><ol class="emit-rata">';
            vert.puuttuvat.forEach(function (puuttuu) {
                var koodi = typeof puuttuu === 'object' ? puuttuu.koodi : puuttuu;
                var nro = typeof puuttuu === 'object' ? puuttuu.nro : null;
                html += leimaRiviHtml({
                    nro: nro,
                    koodi: koodi,
                    tila: 'puuttuu',
                    teksti: 'puuttuu'
                });
            });
            html += '</ol>';
        }
        if (vert && vert.sammuneet && vert.sammuneet.length) {
            html += '<p class="emit-leimat-otsikko">Sammunut</p><ol class="emit-rata">';
            vert.sammuneet.forEach(function (s) {
                html += leimaRiviHtml({
                    nro: s.nro,
                    koodi: s.koodi,
                    tila: 'sammunut',
                    teksti: 'sammunut'
                });
            });
            html += '</ol>';
        }
        if (!html) {
            leimaEl.hidden = true;
            leimaEl.innerHTML = '';
            return;
        }
        leimaEl.innerHTML = html;
        leimaEl.hidden = false;
    }

    function taytaPaatosRadat(radat) {
        var sel = document.getElementById('paatos-rata');
        var lahde = document.getElementById('sarja');
        if (!sel) {
            return;
        }
        var optiot = radat && radat.length ? radat : [];
        if (!optiot.length && lahde) {
            optiot = Array.prototype.map.call(lahde.options, function (o) {
                return { id: o.value, nimi: o.textContent };
            });
        }
        var vanha = sel.value;
        sel.innerHTML = '';
        optiot.forEach(function (r) {
            var o = document.createElement('option');
            o.value = String(r.id);
            o.textContent = r.nimi || String(r.id);
            sel.appendChild(o);
        });
        if (vanha) {
            sel.value = vanha;
        }
    }

    function muotoileAikaSec(sec) {
        if (sec == null || sec === '' || isNaN(Number(sec))) {
            return '';
        }
        sec = Math.max(0, parseInt(sec, 10));
        if (sec < 60) {
            return String(sec);
        }
        var s = sec % 60;
        var m = Math.floor(sec / 60) % 60;
        var h = Math.floor(sec / 3600);
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        if (h > 0) {
            return h + ':' + pad(m) + ':' + pad(s);
        }
        return Math.floor(sec / 60) + ':' + pad(s);
    }

    function iltarastiTilaTiedot(data) {
        var k = String((data && data.keskhyl) || '').toUpperCase().charAt(0);
        if (!k && data && data.tarkistus) {
            k = String(data.tarkistus.keskhyl || '').toUpperCase().charAt(0);
        }
        if (k === 'H') {
            return { koodi: 'H', teksti: 'Hylätty' };
        }
        if (k === 'K') {
            return { koodi: 'K', teksti: 'Keskeyttänyt' };
        }
        if (k === 'T') {
            return { koodi: 'T', teksti: 'Hyväksytty' };
        }
        if (data && (data.esitys || k === 'E' || (data.tarkistus && data.tarkistus.ok === false))) {
            return { koodi: 'E', teksti: 'Esitys' };
        }
        if (data && data.tarkistus && data.tarkistus.ok) {
            return { koodi: 'T', teksti: 'Hyväksytty' };
        }
        return { koodi: '', teksti: '' };
    }

    function arvausKysymys(arvaus) {
        if (!arvaus || !arvaus.id) {
            return '';
        }
        if (arvaus.kysymys) {
            return String(arvaus.kysymys);
        }
        return 'Leimat vastaavat rataa ' + (arvaus.nimi || arvaus.id)
            + '. Vaihdetaanko kilpailija tälle radalle?';
    }

    function naytaIltarastiMaali(data, luokka) {
        var t = data && data.tarkistus ? data.tarkistus : {};
        var nimi = kioskiNimi(data || {});
        var aika = t.aika_txt || (data && data.aika_txt) || muotoileAikaSec(
            t.aika_sec != null ? t.aika_sec : (data && data.aika_sec)
        );
        var tila = iltarastiTilaTiedot(data);
        var html = '';
        if (nimi) {
            html += '<div class="emit-maali-nimi">' + escapeHtml(nimi) + '</div>';
        } else if (data && data.emit) {
            html += '<div class="emit-maali-nimi">Emit ' + escapeHtml(String(data.emit)) + '</div>';
        }
        if (aika) {
            html += '<div class="emit-maali-aika">' + escapeHtml(String(aika)) + '</div>';
        }
        if (tila.teksti) {
            html += '<div class="emit-maali-tila is-' + tila.koodi.toLowerCase() + '">'
                + escapeHtml(tila.teksti) + '</div>';
        } else if (data && data.message && !data.kilpailija_id) {
            html += '<div class="emit-maali-tila is-e">' + escapeHtml(data.message) + '</div>';
        }
        if (tila.koodi === 'E' && t.puuttuvat && t.puuttuvat.length) {
            html += '<div class="emit-puute">Puuttuu: ' + escapeHtml(t.puuttuvat.join(', ')) + '</div>';
        }
        if (tila.koodi === 'E' && data && data.arvaus && data.arvaus.id) {
            html += '<div class="emit-maali-arvaus">' + escapeHtml(arvausKysymys(data.arvaus)) + '</div>';
        }
        if (!html) {
            html = escapeHtml((data && data.message) || '');
        }
        naytaTulos(luokka, html);
        var leimat = (data && data.leimat && data.leimat.length)
            ? data.leimat
            : (odottaaLeimat || []);
        if ((!leimat || !leimat.length) && data && data.tarkistus && data.tarkistus.leimat) {
            leimat = data.tarkistus.leimat;
        }
        naytaLeimat(leimat, t);
        naytaPaatos(data);
    }

    function onkoAutomaatti() {
        return !!(cfg.automaattiSivu) || moodi === 'automaatti';
    }

    function urlMoodi() {
        try {
            var q = new URLSearchParams(window.location.search);
            return (q.get('moodi') || '').toLowerCase();
        } catch (e) {
            return '';
        }
    }

    function parseAikaTxt(txt) {
        if (txt == null) {
            return null;
        }
        var s = String(txt).trim().replace(/^\(+|\)+$/g, '').replace(/\s+/g, '');
        if (!s || s === '–' || s === '-' || s === '—') {
            return null;
        }
        var m = s.match(/^(\d+):(\d{2}):(\d{2})$/);
        if (m) {
            return parseInt(m[1], 10) * 3600 + parseInt(m[2], 10) * 60 + parseInt(m[3], 10);
        }
        m = s.match(/^(\d+):(\d{2})$/);
        if (m) {
            return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
        }
        m = s.match(/^(\d+)$/);
        if (m) {
            return parseInt(m[1], 10);
        }
        return null;
    }

    function laskeAikaSecLeimoista(leimat) {
        var nolla = nollaSecLeimoista(leimat);
        if (nolla == null) {
            return null;
        }
        var last = null;
        var i;
        var l;
        var k;
        var sec;
        for (i = 0; (leimat || []).length && i < leimat.length; i++) {
            l = leimat[i];
            k = parseInt(l && l.koodi, 10);
            if (!k || leimaOnLukija(k)) {
                continue;
            }
            sec = l.kello_sec != null ? parseInt(l.kello_sec, 10) : NaN;
            if (!isNaN(sec)) {
                last = sec;
            }
        }
        if (last == null) {
            return null;
        }
        var d = last - nolla;
        if (d < 0) {
            d += 86400;
        }
        return d;
    }

    function automaattiRastiMerkki(k) {
        var n = parseInt(k, 10);
        return n === 10000 || n === 10001;
    }

    function automaattiKoodiTeksti(p) {
        if (p == null) {
            return '';
        }
        if (typeof p === 'object') {
            if (p.koodi != null && p.koodi !== '') {
                return String(p.koodi);
            }
            if (p.nro != null && p.nro !== '') {
                return String(p.nro);
            }
            return '';
        }
        return String(p);
    }

    function automaattiPuuttuvaTeksti(p) {
        if (!p) {
            return '';
        }
        if (p.nro) {
            return String(p.nro) + '. ' + String(p.koodi);
        }
        return String(p.koodi || '');
    }

    function automaattiPuuttuvatKoodit(data) {
        var t = (data && data.tarkistus) || {};
        var raw = t.puuttuvat || (data && data.puuttuvat) || [];
        var koodit = t.koodit || valitunRadanKoodit(data && data.sarja_id) || [];
        var oikeat = t.oikeat || [];
        var sammunut = t.sammunut || [];
        var ulos = [];
        var i;
        var nro = 0;
        if (koodit.length) {
            var jono = [];
            (raw || []).forEach(function (p) {
                var k = parseInt(typeof p === 'object' && p ? p.koodi : p, 10);
                if (k > 0 && !automaattiRastiMerkki(k)) {
                    jono.push(k);
                }
            });
            var ji = 0;
            for (i = 0; i < koodit.length; i++) {
                var koodi = parseInt(koodit[i], 10);
                if (!koodi || automaattiRastiMerkki(koodi)) {
                    continue;
                }
                nro += 1;
                var puuttuu = false;
                if (oikeat.length) {
                    puuttuu = !oikeat[i] && !sammunut[i];
                } else if (ji < jono.length && jono[ji] === koodi) {
                    puuttuu = true;
                    ji += 1;
                }
                if (puuttuu) {
                    ulos.push({ nro: nro, koodi: koodi });
                }
            }
            if (ulos.length) {
                return ulos;
            }
        }
        nro = 0;
        (raw || []).forEach(function (p) {
            if (p && typeof p === 'object') {
                var kObj = parseInt(p.koodi, 10);
                var nObj = parseInt(p.nro, 10);
                if (kObj > 0) {
                    nro += 1;
                    ulos.push({ nro: nObj > 0 ? nObj : nro, koodi: kObj });
                }
                return;
            }
            var k = parseInt(p, 10);
            if (k > 0) {
                nro += 1;
                ulos.push({ nro: nro, koodi: k });
            }
        });
        return ulos;
    }

    function automaattiRastiLkm(data, puuttuvat) {
        var t = (data && data.tarkistus) || {};
        var koodit = t.koodit || [];
        if (!koodit.length) {
            koodit = valitunRadanKoodit(data && data.sarja_id);
        }
        var rastit = koodit.length;
        var leimattu = 0;
        if (rastit) {
            leimattu = Math.max(0, rastit - ((puuttuvat && puuttuvat.length) || 0));
        } else {
            ((data && data.leimat) || []).forEach(function (l) {
                var k = parseInt(l && (l.koodi != null ? l.koodi : l), 10);
                if (k > 0 && !leimaOnLukija(k)) {
                    leimattu += 1;
                }
            });
        }
        return { rastit: rastit, leimattu: leimattu };
    }

    function automaattiAikaSec(data) {
        var t = data && data.tarkistus ? data.tarkistus : {};
        if (t.aika_sec != null && t.aika_sec !== '') {
            var n = parseInt(t.aika_sec, 10);
            if (!isNaN(n)) {
                return n;
            }
        }
        if (data && data.aika_sec != null && data.aika_sec !== '') {
            n = parseInt(data.aika_sec, 10);
            if (!isNaN(n)) {
                return n;
            }
        }
        n = parseAikaTxt(t.aika_txt || (data && data.aika_txt));
        if (n != null) {
            return n;
        }
        return laskeAikaSecLeimoista((data && data.leimat) || odottaaLeimat || []);
    }

    function automaattiNayttoMalli(data) {
        data = data || {};
        var t = data.tarkistus || {};
        var tila = iltarastiTilaTiedot(data);
        var hylkays = tila.koodi === 'E' || tila.koodi === 'H' || !!(data.kysy);
        var aikaSec = automaattiAikaSec(data);
        var aikaTxt = t.aika_txt || data.aika_txt || muotoileAikaSec(aikaSec);
        var sija = data.sijoitus != null ? parseInt(data.sijoitus, 10) : NaN;
        if (isNaN(sija) && t.sijoitus != null) {
            sija = parseInt(t.sijoitus, 10);
        }
        if (isNaN(sija)) {
            sija = null;
        }
        var puuttuvat = automaattiPuuttuvatKoodit(data);
        var rastit = automaattiRastiLkm(data, puuttuvat);
        var nimi = cfg.viesti ? (viestiOtsikko(data) || kioskiNimi(data)) : kioskiNimi(data);
        if (!nimi && data.emit) {
            nimi = 'Emit ' + String(data.emit);
        }
        var seura = (data.seura || '').trim();
        if (cfg.viesti) {
            seura = viestiOsuusTxt(data) || seura;
        }
        return {
            nimi: nimi || '',
            seura: seura,
            rata: data.sarja || data.rata || '',
            emit: data.emit ? String(data.emit) : '',
            aika: aikaTxt || '',
            aika_sec: aikaSec,
            sija: sija,
            tila: tila,
            hylkaysesitys: tila.koodi === 'E',
            hylatty: tila.koodi === 'H',
            tuntematon: !!data.kysy || (!data.kilpailija_id && !nimi && !!data.emit),
            puuttuvat: puuttuvat,
            rastit: rastit.rastit,
            leimattu: rastit.leimattu,
            mitali: (!hylkays && sija >= 1 && sija <= 3) ? sija : 0,
            luokka: hylkays ? 'hylkays' : (tila.koodi === 'K' ? 'kesk' : 'ok')
        };
    }

    function automaattiPuheTeksti(malli) {
        if (!malli) {
            return '';
        }
        if (malli.tuntematon) {
            return 'Tuntematon kortti' + (malli.emit ? ', ' + malli.emit : '');
        }
        var osat = [];
        if (malli.nimi) {
            osat.push(malli.nimi);
        }
        if (cfg.viesti && malli.seura) {
            osat.push(malli.seura);
        }
        if (malli.hylkaysesitys) {
            osat.push('hylkäysesitys');
            if (malli.puuttuvat && malli.puuttuvat.length) {
                osat.push('puuttuu ' + malli.puuttuvat.map(function (p) {
                    return p.nro ? ('rasti ' + p.nro + ' koodi ' + p.koodi) : String(p.koodi);
                }).join(', '));
            }
        } else if (malli.hylatty) {
            osat.push('hylätty');
        } else if (malli.tila && malli.tila.koodi === 'K') {
            osat.push('keskeyttänyt');
        } else {
            if (malli.aika) {
                osat.push('aika ' + String(malli.aika).replace(/:/g, ' '));
            }
            if (malli.sija) {
                osat.push('sija ' + malli.sija);
            }
        }
        return osat.join('. ');
    }

    function paikallinenSijoitus(data) {
        var aika = automaattiAikaSec(data);
        var sarjaId = parseInt(data && data.sarja_id, 10) || 0;
        if (aika == null || !sarjaId) {
            return null;
        }
        var parempi = 0;
        (cfg.osanottajat || []).forEach(function (h) {
            if (parseInt(h.sarja_id, 10) !== sarjaId) {
                return;
            }
            var k = String(h.keskhyl || '').toUpperCase().charAt(0);
            if (k !== 'T') {
                return;
            }
            if (h.id && data.kilpailija_id && parseInt(h.id, 10) === parseInt(data.kilpailija_id, 10)) {
                return;
            }
            var ha = h.aika_sec != null ? parseInt(h.aika_sec, 10) : parseAikaTxt(h.aika_txt);
            if (ha == null || isNaN(ha)) {
                return;
            }
            if (ha < aika) {
                parempi += 1;
            }
        });
        return parempi + 1;
    }

    function sijoitusSeurantaHtmlsta(html, data) {
        var nimi = kioskiNimi(data || {}).toLowerCase().replace(/\s+/g, ' ').trim();
        var emit = data && data.emit ? String(data.emit) : '';
        if (!html || (!nimi && !emit)) {
            return null;
        }
        var doc;
        try {
            doc = new DOMParser().parseFromString(html, 'text/html');
        } catch (e) {
            return null;
        }
        var rivit = doc.querySelectorAll('.lb-rivi');
        var i;
        var rivi;
        var rNimi;
        var rSija;
        var txt;
        for (i = 0; i < rivit.length; i++) {
            rivi = rivit[i];
            if (rivi.classList.contains('lb-head')) {
                continue;
            }
            rNimi = rivi.querySelector('.lb-nimi-paa, .lb-nimi-link, .lb-nimi');
            txt = rNimi ? (rNimi.getAttribute('title') || rNimi.textContent || '') : '';
            txt = txt.toLowerCase().replace(/\s+/g, ' ').trim();
            if (nimi && txt.indexOf(nimi) === -1 && nimi.indexOf(txt) === -1) {
                continue;
            }
            if (!nimi && emit && txt.indexOf(emit) === -1) {
                continue;
            }
            rSija = rivi.querySelector('.lb-sija');
            if (!rSija) {
                return null;
            }
            var n = parseInt(String(rSija.textContent || '').replace(/\D+/g, ''), 10);
            return isNaN(n) ? null : n;
        }
        return null;
    }

    function lisaaAutomaattiNappi() {
        /* Automaatti on oma kioskisivu, ei tulospalvelun välilehti. */
    }

    function paivitaAutomaattiPuheNappi(btn) {
        if (!btn) {
            return;
        }
        btn.setAttribute('aria-pressed', automaattiPuhu ? 'true' : 'false');
        btn.setAttribute('title', automaattiPuhu ? 'Ääni päällä' : 'Ääni pois');
        btn.setAttribute('aria-label', automaattiPuhu ? 'Ääni pois' : 'Ääni päälle');
        btn.textContent = automaattiPuhu ? '🔊' : '🔇';
    }

    function luoAutomaattiPuheNappi() {
        var puheBtn = document.getElementById('emit-automaatti-puhe');
        if (puheBtn) {
            return puheBtn;
        }
        puheBtn = document.createElement('button');
        puheBtn.type = 'button';
        puheBtn.id = 'emit-automaatti-puhe';
        puheBtn.className = 'emit-automaatti-puhe';
        paivitaAutomaattiPuheNappi(puheBtn);
        puheBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            automaattiPuhu = !automaattiPuhu;
            try {
                localStorage.setItem('jah-emit-puhe', automaattiPuhu ? '1' : '0');
            } catch (e2) { /* */ }
            paivitaAutomaattiPuheNappi(puheBtn);
            if (!automaattiPuhu && window.speechSynthesis) {
                clearTimeout(puheAjastin);
                window.speechSynthesis.cancel();
            } else if (automaattiPuhu) {
                avaaPuhe();
                puhuAutomaatti('Puhe päällä');
            }
        });
        return puheBtn;
    }

    function siirraPuheYlapalkkiin() {
        if (!cfg.iltarasti) {
            return;
        }
        var toimet = document.querySelector('.site-topbar-actions')
            || document.querySelector('.site-topbar');
        if (!toimet) {
            return;
        }
        var puhe = luoAutomaattiPuheNappi();
        var teema = document.querySelector('.site-topbar .theme-toggle');
        if (teema && teema.parentNode) {
            if (puhe.nextSibling !== teema) {
                teema.parentNode.insertBefore(puhe, teema);
            }
        } else if (puhe.parentNode !== toimet) {
            toimet.appendChild(puhe);
        }
    }

    function siirraAutomaattiYlapalkkiin() {
        if (!cfg.automaattiSivu) {
            siirraPuheYlapalkkiin();
            return;
        }
        var toimet = document.querySelector('.site-topbar-actions')
            || document.querySelector('.site-topbar');
        if (!toimet) {
            return;
        }
        var net = document.getElementById('iltarasti-yhteys');
        var netRivi = net;
        if (net) {
            net.setAttribute('data-online', 'Palvelin ok');
            net.setAttribute('data-offline', 'Ei verkkoa');
            net.setAttribute('title', navigator.onLine !== false ? 'Palvelin ok' : 'Ei verkkoa');
            net.setAttribute('aria-label', net.getAttribute('title'));
            net.classList.add('emit-automaatti-palvelin');
            net.textContent = '';
            var tila = document.getElementById('iltarasti-tila');
            if (tila) {
                netRivi = tila;
            } else if (net.parentNode && net.parentNode.classList
                && net.parentNode.classList.contains('iltarasti-yhteys-rivi')) {
                netRivi = net.parentNode;
            }
        }
        var puhe = luoAutomaattiPuheNappi();
        if (puhe.parentNode !== toimet) {
            toimet.insertBefore(puhe, toimet.firstChild);
        }
        if (netRivi && netRivi.parentNode === toimet) {
            toimet.insertBefore(netRivi, puhe);
        }
        var yhd = document.getElementById('yhdista');
        if (yhd && yhd.parentNode !== toimet) {
            yhd.classList.add('emit-automaatti-ylapalkki-yhdista');
            yhd.textContent = 'Yhdistä lukija';
            toimet.insertBefore(yhd, puhe.nextSibling);
        }
        var ikoni = document.getElementById('lukija-yhteys');
        if (ikoni && ikoni.parentNode !== toimet) {
            ikoni.classList.add('emit-automaatti-ylapalkki-tila');
            toimet.insertBefore(ikoni, yhd && yhd.nextSibling ? yhd.nextSibling : puhe.nextSibling);
        }
    }

    function varmistaAutomaattiNaytto() {
        var el = document.getElementById('emit-automaatti');
        if (el) {
            return el;
        }
        siirraAutomaattiYlapalkkiin();
        el = document.createElement('div');
        el.id = 'emit-automaatti';
        el.className = 'emit-automaatti is-odottaa';
        el.hidden = true;
        el.innerHTML = '<div class="emit-automaatti-odota" id="emit-automaatti-odota">'
            + '<div class="emit-automaatti-odota-merkki" aria-hidden="true"></div>'
            + '<div class="emit-automaatti-ots" id="emit-automaatti-ots">Yhdistä lukija</div>'
            + '<div class="emit-automaatti-ala" id="emit-automaatti-yhteys"></div>'
            + '<div class="emit-automaatti-virhe" id="emit-automaatti-virhe" hidden></div>'
            + '</div>'
            + '<div class="emit-automaatti-tulos" id="emit-automaatti-tulos" hidden></div>';
        document.body.appendChild(el);
        el.addEventListener('click', function (ev) {
            var tyyppiNappi = ev.target && ev.target.closest
                ? ev.target.closest('.emit-lukija-tyyppi')
                : null;
            if (tyyppiNappi) {
                ev.preventDefault();
                ev.stopPropagation();
                asetaLukijaTyyppi(tyyppiNappi.getAttribute('data-tyyppi'));
                return;
            }
            var nappi = ev.target && ev.target.closest
                ? ev.target.closest('#emit-automaatti-yhdista, #emit-automaatti-katkaise')
                : null;
            if (!nappi) {
                return;
            }
            ev.preventDefault();
            ev.stopPropagation();
            yhdistaNapista();
        });
        el.addEventListener('dblclick', function (ev) {
            if (ev.target && ev.target.closest
                    && ev.target.closest('#emit-automaatti-yhdista, #emit-automaatti-katkaise')) {
                return;
            }
            tyhjennaAutomaatti();
        });
        paivitaAutomaattiYhteys();
        return el;
    }

    function usbLukijaSaatavilla() {
        if (onPuhelin()) {
            return !!(navigator.usb && window.JahUsbSerial);
        }
        return !!(navigator.serial || (window.JahUsbSerial && navigator.usb));
    }

    function paivitaAutomaattiYhteys() {
        var el = document.getElementById('emit-automaatti-yhteys');
        var ots = document.getElementById('emit-automaatti-ots');
        if (!el) {
            return;
        }
        var wrap = document.getElementById('emit-automaatti');
        if (wrap && wrap.classList.contains('is-odottaa')) {
            wrap.classList.toggle('is-auki', !!auki);
            wrap.classList.toggle('is-irti', !auki && lukijaKatkesi);
        }
        if (ots) {
            ots.textContent = auki
                ? 'Näytä kortti'
                : (lukijaKatkesi ? 'Lukija irtosi' : 'Yhdistä lukija');
        }
        if (auki) {
            var virheEl = document.getElementById('emit-automaatti-virhe');
            if (virheEl) {
                virheEl.hidden = true;
                virheEl.textContent = '';
            }
            el.innerHTML = '<div class="emit-automaatti-yhteys-ok">Lukija valmiina</div>'
                + '<div class="emit-automaatti-yhteys-ohje">Vie emit-kortti lukijaan</div>'
                + '<button type="button" class="emit-automaatti-katkaise" id="emit-automaatti-katkaise">'
                + 'Katkaise yhteys</button>';
            return;
        }
        el.innerHTML = '<div class="emit-lukija-tyypit" role="group" aria-label="Lukijan tyyppi">'
            + '<button type="button" class="emit-lukija-tyyppi' + (lukijaTyyppi === 'emit' ? ' is-valittu' : '') + '" data-tyyppi="emit">Emit</button>'
            + '<button type="button" class="emit-lukija-tyyppi' + (lukijaTyyppi === 'si' ? ' is-valittu' : '') + '" data-tyyppi="si">SportIdent</button>'
            + '</div>'
            + '<button type="button" class="emit-automaatti-yhdista" id="emit-automaatti-yhdista">'
            + (lukijaKatkesi ? 'Yhdistä lukija uudelleen' : 'Yhdistä lukija') + '</button>'
            + '<div class="emit-automaatti-yhteys-ohje">'
            + (usbLukijaSaatavilla()
                ? (lukijaKatkesi
                    ? 'Kytke kaapeli ja paina Yhdistä. Lukija yhdistyy ilman listaa, jos lupa on voimassa.'
                    : (onkoSportIdent()
                        ? 'Paina Yhdistä. Ensimmäisellä kerralla valitse SportIdent-lukija; sen jälkeen yhdistyy suoraan.'
                        : 'Paina Yhdistä. Ensimmäisellä kerralla selain voi kysyä USB-lukijaa; sen jälkeen yhdistyy suoraan.'))
                : 'Paina Yhdistä. Jos yhteys ei aukea, avaa sivu Chromessa.')
            + '</div>';
    }

    function naytaAutomaattiVirhe(html) {
        varmistaAutomaattiNaytto();
        paivitaAutomaattiYhteys();
        var virheEl = document.getElementById('emit-automaatti-virhe');
        var odota = document.getElementById('emit-automaatti-odota');
        var tulos = document.getElementById('emit-automaatti-tulos');
        var teksti = String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        if (virheEl) {
            if (teksti) {
                virheEl.hidden = false;
                virheEl.textContent = teksti;
            } else {
                virheEl.hidden = true;
                virheEl.textContent = '';
            }
        }
        if (teksti) {
            var wrap = document.getElementById('emit-automaatti');
            if (wrap) {
                wrap.hidden = false;
            }
            if (odota) {
                odota.hidden = false;
            }
            if (tulos) {
                tulos.hidden = true;
            }
        }
    }

    function tyhjennaAutomaatti() {
        clearTimeout(automaattiAjastin);
        automaattiAjastin = null;
        if (window.speechSynthesis) {
            window.speechSynthesis.cancel();
        }
        var wrap = document.getElementById('emit-automaatti');
        if (!wrap) {
            return;
        }
        wrap.className = 'emit-automaatti is-odottaa';
        var odota = document.getElementById('emit-automaatti-odota');
        var tulos = document.getElementById('emit-automaatti-tulos');
        if (odota) {
            odota.hidden = false;
        }
        if (tulos) {
            tulos.hidden = true;
            tulos.innerHTML = '';
        }
        var virheEl = document.getElementById('emit-automaatti-virhe');
        if (virheEl) {
            virheEl.hidden = true;
            virheEl.textContent = '';
        }
        paivitaAutomaattiYhteys();
    }

    function automaattiAjastaPaluu() {
        clearTimeout(automaattiAjastin);
        automaattiAjastin = setTimeout(tyhjennaAutomaatti, AUTOMAATTI_TYHJENNYS_MS);
    }

    function suomiPuheAani() {
        var w = puheIkkuna();
        if (!w.speechSynthesis || !w.speechSynthesis.getVoices) {
            return null;
        }
        var voices = w.speechSynthesis.getVoices() || [];
        var i;
        var t;
        var lang;
        for (i = 0; i < voices.length; i++) {
            t = ((voices[i].name || '') + ' ' + (voices[i].voiceURI || '')).toLowerCase();
            if (t.indexOf('satu') !== -1) {
                return voices[i];
            }
        }
        for (i = 0; i < voices.length; i++) {
            lang = (voices[i].lang || '').toLowerCase();
            t = ((voices[i].name || '') + ' ' + (voices[i].voiceURI || '')).toLowerCase();
            if (lang.indexOf('fi') === 0 || t.indexOf('finnish') !== -1
                || t.indexOf('suomi') !== -1 || t.indexOf('onni') !== -1) {
                return voices[i];
            }
        }
        return voices[0] || null;
    }

    function puheIkkuna() {
        try {
            if (window.top && window.top.speechSynthesis) {
                return window.top;
            }
        } catch (eTop) { /* */ }
        return window;
    }

    function avaaPuhe() {
        var w = puheIkkuna();
        if (!w.speechSynthesis) {
            return;
        }
        try {
            w.speechSynthesis.getVoices();
            if (w.speechSynthesis.paused && w.speechSynthesis.resume) {
                w.speechSynthesis.resume();
            }
            if (puheAvattu) {
                return;
            }
            puheAvattu = true;
            puheLause = new w.SpeechSynthesisUtterance(' ');
            puheLause.volume = 0;
            puheLause.rate = 2;
            w.__jahPuheLause = puheLause;
            w.speechSynthesis.speak(puheLause);
        } catch (eAv) { /* */ }
        try {
            if (window.parent && window.parent !== window && window.parent.JahKuori
                && typeof window.parent.JahKuori.avaaPuhe === 'function') {
                window.parent.JahKuori.avaaPuhe();
            }
        } catch (eKuoriPuhe) { /* */ }
    }

    function puhuAutomaatti(teksti) {
        if (!automaattiPuhu || !teksti) {
            return;
        }
        var w = puheIkkuna();
        if (!w.speechSynthesis) {
            return;
        }
        clearTimeout(puheAjastin);
        function sano(ilmanAanta) {
            try {
                var syn = w.speechSynthesis;
                if (syn.paused && syn.resume) {
                    syn.resume();
                }
                if (syn.speaking || syn.pending) {
                    syn.cancel();
                    puheAjastin = setTimeout(function () { sano(ilmanAanta); }, 150);
                    return;
                }
                puheLause = new w.SpeechSynthesisUtterance(String(teksti));
                puheLause.rate = 1.02;
                if (!ilmanAanta) {
                    puheLause.lang = 'fi-FI';
                    var v = suomiPuheAani();
                    if (v) {
                        puheLause.voice = v;
                        puheLause.lang = v.lang || 'fi-FI';
                    }
                }
                puheLause.onend = function () { /* pidä viite */ };
                puheLause.onerror = function () {
                    if (!ilmanAanta) {
                        sano(true);
                    }
                };
                w.__jahPuheLause = puheLause;
                syn.speak(puheLause);
            } catch (ePuhu) { /* */ }
        }
        sano(false);
    }

    function automaattiMerkkiHtml(malli) {
        var tila = 'Hyväksytty';
        var ala = 'Rata leimattu oikein';
        var ico = '✓';
        if (malli.tuntematon) {
            tila = 'Tuntematon kortti';
            ala = 'Korttia ei ole kirjattu tähän tapahtumaan';
            ico = '?';
        } else if (malli.hylkaysesitys) {
            tila = 'Hylkäysesitys';
            ala = malli.puuttuvat && malli.puuttuvat.length
                ? 'Radalla puuttuu ' + malli.puuttuvat.length
                    + (malli.puuttuvat.length === 1 ? ' rasti' : ' rastia')
                : 'Rastit eivät täsmää rataan';
            ico = '!';
        } else if (malli.hylatty) {
            tila = 'Hylätty';
            ala = 'Tulos on merkitty hylätyksi';
            ico = '×';
        } else if (malli.tila && malli.tila.koodi === 'K') {
            tila = 'Keskeyttänyt';
            ala = 'Suoritus merkitty keskeytyneeksi';
            ico = '–';
        }
        return '<div class="emit-automaatti-merkki">'
            + '<span class="emit-automaatti-merkki-ico" aria-hidden="true">' + ico + '</span>'
            + '<span class="emit-automaatti-merkki-tekstit">'
            + '<span class="emit-automaatti-tila">' + escapeHtml(tila) + '</span>'
            + '<span class="emit-automaatti-tila-ala">' + escapeHtml(ala) + '</span>'
            + '</span></div>';
    }

    function automaattiAikaHtml(malli) {
        if (malli.tuntematon || !malli.aika) {
            return '';
        }
        var html = '<div class="emit-automaatti-luvut">';
        html += '<div class="emit-automaatti-aika-lohko">'
            + '<div class="emit-automaatti-aika-ots">Aika</div>'
            + '<div class="emit-automaatti-aika">' + escapeHtml(malli.aika) + '</div>'
            + '</div>';
        if (malli.sija && !malli.hylkaysesitys && !malli.hylatty) {
            html += '<div class="emit-automaatti-sija'
                + (malli.mitali ? ' is-mitali is-' + malli.mitali : '') + '">'
                + '<span class="emit-automaatti-sija-nro">' + escapeHtml(String(malli.sija)) + '.</span>'
                + '<span class="emit-automaatti-sija-txt">sija</span></div>';
        }
        html += '</div>';
        return html;
    }

    function automaattiPuuteHtml(malli) {
        if (!malli.puuttuvat || !malli.puuttuvat.length || malli.tuntematon) {
            return '';
        }
        var html = '<div class="emit-automaatti-puute">'
            + '<div class="emit-automaatti-puute-ots">Puuttuvat rastit</div>'
            + '<div class="emit-automaatti-koodit">';
        malli.puuttuvat.forEach(function (p) {
            html += '<div class="emit-automaatti-koodi">'
                + '<span class="emit-automaatti-koodi-nro">'
                + escapeHtml(p.nro ? (String(p.nro) + '.') : '')
                + '</span>'
                + '<span class="emit-automaatti-koodi-koodi">'
                + escapeHtml(String(p.koodi))
                + '</span></div>';
        });
        html += '</div></div>';
        return html;
    }

    function automaattiMetaHtml(malli) {
        var osat = [];
        if (!malli.tuntematon && malli.rastit > 0) {
            osat.push(String(malli.leimattu) + ' / ' + String(malli.rastit) + ' rastia');
        }
        if (malli.emit) {
            osat.push('Emit ' + malli.emit);
        }
        if (!osat.length) {
            return '';
        }
        return '<div class="emit-automaatti-meta">' + escapeHtml(osat.join(' · ')) + '</div>';
    }

    function piirraAutomaatti(malli) {
        var wrap = varmistaAutomaattiNaytto();
        var odota = document.getElementById('emit-automaatti-odota');
        var tulos = document.getElementById('emit-automaatti-tulos');
        if (odota) {
            odota.hidden = true;
        }
        if (!tulos) {
            return;
        }
        var luokka = 'emit-automaatti is-' + malli.luokka;
        if (malli.tuntematon) {
            luokka = 'emit-automaatti is-tuntematon';
        }
        wrap.className = luokka;
        var html = '<div class="emit-automaatti-kortti">';
        if (malli.rata) {
            html += '<div class="emit-automaatti-rata">' + escapeHtml(malli.rata) + '</div>';
        }
        html += '<div class="emit-automaatti-nimi">' + escapeHtml(malli.nimi || '–') + '</div>';
        if (malli.seura && !malli.tuntematon) {
            html += '<div class="emit-automaatti-seura">' + escapeHtml(malli.seura) + '</div>';
        }
        html += automaattiMerkkiHtml(malli);
        html += automaattiAikaHtml(malli);
        html += automaattiPuuteHtml(malli);
        html += automaattiMetaHtml(malli);
        html += '</div>';
        tulos.innerHTML = html;
        tulos.hidden = false;
        wrap.hidden = false;
    }

    function naytaAutomaatti(data) {
        varmistaAutomaattiNaytto();
        var malli = automaattiNayttoMalli(data);
        if (malli.sija == null && malli.luokka === 'ok' && !malli.tuntematon) {
            malli.sija = paikallinenSijoitus(data);
            malli.mitali = (malli.sija >= 1 && malli.sija <= 3) ? malli.sija : 0;
        }
        piirraAutomaatti(malli);
        puhuAutomaatti(automaattiPuheTeksti(malli));
        automaattiAjastaPaluu();
        if (malli.luokka === 'ok' && !malli.tuntematon && data && data.sarja && cfg.kilpailuId) {
            haeVirallinenSijoitus(data, malli);
        }
    }

    function haeVirallinenSijoitus(data, malli) {
        var nro = ++automaattiHaku;
        var sarja = encodeURIComponent(data.sarja);
        var url = '/public/seuranta.php?kilpailu_id=' + encodeURIComponent(cfg.kilpailuId)
            + '&nakyma=tulokset&embed=1&sarja=' + sarja;
        fetch(url, { credentials: 'same-origin' }).then(function (res) {
            return res.ok ? res.text() : '';
        }).then(function (html) {
            if (nro !== automaattiHaku || !html) {
                return;
            }
            var sija = sijoitusSeurantaHtmlsta(html, data);
            if (!sija) {
                return;
            }
            malli.sija = sija;
            malli.mitali = (sija >= 1 && sija <= 3) ? sija : 0;
            piirraAutomaatti(malli);
        }).catch(function () { /* offline */ });
    }

    function asetaAutomaattiNakyma(paalle) {
        document.body.classList.toggle('is-automaatti-moodi', !!paalle);
        var el = varmistaAutomaattiNaytto();
        el.hidden = !paalle;
        if (paalle) {
            tyhjennaAutomaatti();
            paivitaAutomaattiYhteys();
        } else {
            clearTimeout(automaattiAjastin);
            el.hidden = true;
        }
    }

    function naytaPaatos(data) {
        var el = document.getElementById('emit-paatos');
        if (!el) {
            return;
        }
        var tila = iltarastiTilaTiedot(data);
        var nayta = !!(cfg.iltarasti && data && data.kilpailija_id && tila.koodi === 'E');
        var arvausEl = document.getElementById('emit-arvaus');
        var arvausTeksti = document.getElementById('arvaus-teksti');
        if (!nayta) {
            el.hidden = true;
            if (arvausEl) {
                arvausEl.hidden = true;
            }
            return;
        }
        taytaPaatosRadat(data.radat);
        var rataSel = document.getElementById('paatos-rata');
        var arvaus = data.arvaus;
        if (rataSel && data.sarja_id) {
            rataSel.value = String(data.sarja_id);
        }
        if (arvaus && arvaus.id) {
            if (rataSel) {
                rataSel.value = String(arvaus.id);
            }
            if (arvausTeksti) {
                arvausTeksti.textContent = arvausKysymys(arvaus);
            }
            if (arvausEl) {
                arvausEl.hidden = false;
            }
        } else if (arvausEl) {
            arvausEl.hidden = true;
        }
        el.hidden = false;
    }

    function lahetaPaatos(paatos) {
        if (!viimeVastaus || !viimeVastaus.emit) {
            naytaTulos('virhe', 'Lue ensin kortti.');
            return;
        }
        laheta({
            emit: viimeVastaus.emit,
            leimat: viimeVastaus.leimat || odottaaLeimat || []
        }, 'paatos', { paatos: paatos });
    }

    function lahetaRataVaihto(sarjaId) {
        var sel = document.getElementById('paatos-rata');
        if (!viimeVastaus || !viimeVastaus.emit) {
            naytaTulos('virhe', 'Lue ensin kortti.');
            return;
        }
        var id = sarjaId != null ? parseInt(sarjaId, 10) : (sel ? parseInt(sel.value, 10) : 0);
        laheta({
            emit: viimeVastaus.emit,
            leimat: viimeVastaus.leimat || odottaaLeimat || []
        }, 'vaihda_rata', { sarja_id: id });
    }

    function lahetaArvausVaihto() {
        var arvaus = viimeVastaus && viimeVastaus.arvaus;
        lahetaRataVaihto(arvaus && arvaus.id ? arvaus.id : undefined);
    }

    function lisaaViimeisiin(data) {
        if (!viimeisetEl) {
            return;
        }
        var li = document.createElement('li');
        li.className = 'lahto-rivi';
        if (cfg.viesti) {
            var nroV = data.joukkue_numero != null ? data.joukkue_numero : data.numero;
            li.innerHTML = '<strong>' + escapeHtml(String(nroV != null ? nroV : '–')) + '</strong> '
                + escapeHtml(data.joukkue_nimi || data.nimi || '')
                + (data.osuus ? ' <span class="muted">' + escapeHtml(String(data.osuus) + '. osuus') + '</span>' : '')
                + ' <span class="muted">' + escapeHtml(data.sarja || '') + '</span>'
                + ' <span class="muted">emit ' + escapeHtml(String(data.emit || '')) + '</span>';
        } else {
            var nro = data.numero != null ? data.numero : '–';
            li.innerHTML = '<strong>' + escapeHtml(String(nro)) + '</strong> '
                + escapeHtml(data.nimi || '')
                + ' <span class="muted">' + escapeHtml(data.sarja || '') + '</span>'
                + ' <span class="muted">emit ' + escapeHtml(String(data.emit || '')) + '</span>';
        }
        viimeisetEl.prepend(li);
        while (viimeisetEl.children.length > 12) {
            viimeisetEl.removeChild(viimeisetEl.lastChild);
        }
    }

    function nollaSec(leimat) {
        var el = document.getElementById('nolla');
        if (el) {
            var v = (el.value || '').trim();
            var m = v.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
            if (m) {
                return parseInt(m[1], 10) * 3600 + parseInt(m[2], 10) * 60 + parseInt(m[3] || '0', 10);
            }
        }
        return nollaSecLeimoista(leimat || odottaaLeimat || []);
    }

    function nollaSecLeimoista(leimat) {
        var i;
        var l;
        var sec;
        for (i = 0; i < (leimat || []).length; i++) {
            l = leimat[i];
            if (parseInt(l && l.koodi, 10) !== 0) {
                continue;
            }
            sec = l.kello_sec != null ? l.kello_sec : l.aika_sec;
            if (sec != null && sec !== '') {
                return parseInt(sec, 10);
            }
        }
        return null;
    }

    function lomakeKesken() {
        return lisaaEl && !lisaaEl.hidden && lomakeMuokattu;
    }

    function kentta(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
    }

    function emitNumeroLomakkeesta() {
        var el = document.getElementById('emit-koodi');
        if (!el) {
            return odottaaEmit || 0;
        }
        var n = parseInt(String(el.value || '').replace(/\D+/g, ''), 10);
        return n > 0 ? n : 0;
    }

    function asetaEmitKentta(emit) {
        var el = document.getElementById('emit-koodi');
        if (el) {
            el.value = emit ? String(emit) : '';
        }
        var teksti = document.getElementById('lisaa-emit');
        if (teksti && !cfg.iltarasti) {
            teksti.textContent = emit ? ('Emit ' + emit) : '';
        }
        odottaaEmit = emit || 0;
    }

    function tyhjennaLomake() {
        ['etunimi', 'sukunimi', 'seura', 'emit-koodi', 'kilpailija-id'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.value = '';
            }
        });
        var lainaEl = document.getElementById('lainakortti');
        if (lainaEl) {
            lainaEl.checked = false;
        }
        var eiAikaa = document.getElementById('ei-aikaa');
        if (eiAikaa) {
            eiAikaa.checked = false;
        }
        naytaMaksu(null);
    }

    function taytaLomake(data, emitPakko) {
        var emit = emitPakko || (data && data.emit) || 0;
        asetaEmitKentta(emit);
        var sarja = document.getElementById('sarja');
        if (sarja && data && data.sarja_id) {
            sarja.value = String(data.sarja_id);
        }
        var etu = document.getElementById('etunimi');
        var suku = document.getElementById('sukunimi');
        var seura = document.getElementById('seura');
        if (etu) {
            etu.value = (data && data.etunimi) || '';
        }
        if (suku) {
            suku.value = (data && data.sukunimi) || '';
        }
        if (seura) {
            seura.value = (data && data.seura) || '';
        }
        var lainaEl = document.getElementById('lainakortti');
        if (lainaEl) {
            lainaEl.checked = !!(data && data.laina);
        }
        var idEl = document.getElementById('kilpailija-id');
        if (idEl) {
            idEl.value = data && data.kilpailija_id ? String(data.kilpailija_id) : '';
        }
        if (lisaaEl) {
            lisaaEl.hidden = !!(cfg.iltarasti && moodi === 'maali');
        }
        lomakeMuokattu = false;
        naytaMaksu(data);
    }

    function paivitaIltarastiNakyma() {
        if (!cfg.iltarasti) {
            return;
        }
        var maali = moodi === 'maali' || moodi === 'automaatti';
        document.body.classList.toggle('is-leimantarkistus', maali);
        document.body.classList.toggle('is-automaatti-moodi', moodi === 'automaatti');
        if (lisaaEl) {
            lisaaEl.hidden = maali;
        }
        if (viimeisetEl) {
            viimeisetEl.hidden = maali;
        }
        if (moodi === 'automaatti') {
            piilotaKysely();
            piilotaEhdotukset();
            var paatosEl0 = document.getElementById('emit-paatos');
            if (paatosEl0) {
                paatosEl0.hidden = true;
            }
            return;
        }
        piilotaKysely();
        piilotaEhdotukset();
        if (tulosEl) {
            tulosEl.hidden = true;
            tulosEl.className = 'timing-viesti';
        }
        var yh = document.getElementById('emit-yhteenveto');
        if (yh) {
            yh.innerHTML = '';
        }
        if (leimaEl) {
            leimaEl.hidden = true;
            leimaEl.innerHTML = '';
        }
        var paatosEl = document.getElementById('emit-paatos');
        if (paatosEl) {
            paatosEl.hidden = true;
        }
    }

    function rakennaLahetys(kilpailija, moodiOverride, extra) {
        var kayta = moodiOverride || moodi;
        var body = {
            kilpailu_id: cfg.kilpailuId,
            moodi: kayta,
            emit: kilpailija.emit
        };
        if (extra && typeof extra === 'object') {
            Object.keys(extra).forEach(function (k) {
                body[k] = extra[k];
            });
        }
        if (kayta === 'tallenna' || kayta === 'maastoon' || kayta === 'pois_maastosta' || (cfg.iltarasti && kayta === 'maali')) {
            var sarja = document.getElementById('sarja');
            body.sarja_id = body.sarja_id || (sarja ? parseInt(sarja.value, 10) : 0);
            body.etunimi = body.etunimi != null ? body.etunimi : kentta('etunimi');
            body.sukunimi = body.sukunimi != null ? body.sukunimi : kentta('sukunimi');
            body.seura = body.seura != null ? body.seura : kentta('seura');
            var lainaEl = document.getElementById('lainakortti');
            body.laina = !!(lainaEl && lainaEl.checked);
            var eiAikaaEl = document.getElementById('ei-aikaa');
            body.ilman_aikaa = !!(eiAikaaEl && eiAikaaEl.checked);
            var idEl = document.getElementById('kilpailija-id');
            if (idEl && idEl.value) {
                body.kilpailija_id = parseInt(idEl.value, 10) || 0;
            }
            if (kayta === 'tallenna') {
                body.leimat = kilpailija.leimat || odottaaLeimat || [];
                body.nolla_sec = nollaSec(kilpailija.leimat);
                body.tarkoitus = tarkoitus || (moodi === 'maali' ? 'maali' : 'lahto');
            }
        }
        if (kayta === 'maali' || kayta === 'lue' || kayta === 'vaihda_rata' || kayta === 'paatos') {
            body.leimat = kilpailija.leimat || odottaaLeimat || [];
            body.nolla_sec = nollaSec(body.leimat);
        }
        if (kayta === 'lue') {
            body.oletus_moodi = (moodi === 'maali' || moodi === 'automaatti') ? 'maali' : 'lahto';
        }
        if (onkoAutomaatti() || kioski) {
            body.automaatti = 1;
        }
        return body;
    }

    function samaNayttoEmit(emit) {
        return !!(viimeVastaus && !viimeVastaus.kysy && emit
            && String(viimeVastaus.emit) === String(emit));
    }

    function kasitteleVastaus(data, body) {
        var kayta = (body && body.moodi) || moodi;
        var edellinen = viimeVastaus;
        var emitVastaus = (body && body.emit) || (data && data.emit);
        if (data && data.kysy && onkoAutomaatti() && samaNayttoEmit(emitVastaus)) {
            return;
        }
        var vanhatLeimat = (edellinen && edellinen.leimat && edellinen.leimat.length)
            ? edellinen.leimat
            : (odottaaLeimat || []);
        var uudetLeimat = (data && data.leimat) || [];
        if (!data.kysy && samaNayttoEmit(emitVastaus) && vanhatLeimat.length && uudetLeimat.length < vanhatLeimat.length) {
            data.leimat = vanhatLeimat;
            if (!data.tarkistus && edellinen && edellinen.tarkistus) {
                data.tarkistus = edellinen.tarkistus;
            }
            if (!data.kilpailija_id && edellinen) {
                data.kilpailija_id = edellinen.kilpailija_id;
                data.etunimi = data.etunimi || edellinen.etunimi;
                data.sukunimi = data.sukunimi || edellinen.sukunimi;
                data.nimi = data.nimi || edellinen.nimi;
                data.sarja = data.sarja || edellinen.sarja;
                data.keskhyl = data.keskhyl || edellinen.keskhyl;
            }
        }
        if (kayta === 'paatos' && viimeVastaus) {
            if (!data.leimat && viimeVastaus.leimat) {
                data.leimat = viimeVastaus.leimat;
            }
            if (!data.tarkistus && viimeVastaus.tarkistus) {
                data.tarkistus = viimeVastaus.tarkistus;
            }
            if (data.tarkistus) {
                if (data.keskhyl) {
                    data.tarkistus.keskhyl = data.keskhyl;
                }
                if (data.aika_sec != null) {
                    data.tarkistus.aika_sec = data.aika_sec;
                }
                if (data.aika_txt) {
                    data.tarkistus.aika_txt = data.aika_txt;
                }
            }
        }
        viimeVastaus = data;
        if (data.radat && data.radat.length && !radatPaikallisetOdottavat) {
            cfg.radat = data.radat;
            if (window.jahRataOffline && cfg.iltarasti && cfg.kilpailuId) {
                window.jahRataOffline.siemen(cfg.kilpailuId, data.radat);
            }
        }
        if (data.osanottajat && data.osanottajat.length) {
            cfg.osanottajat = data.osanottajat;
        }
        if (!data.kysy && (data.kilpailija_id || data.emit)) {
            paivitaPaikallinenOsanottaja({
                id: data.kilpailija_id || 0,
                emit: data.emit,
                etunimi: data.etunimi || '',
                sukunimi: data.sukunimi || '',
                nimi: data.nimi || '',
                seura: data.seura || '',
                sarja_id: data.sarja_id || 0,
                rata: data.sarja || '',
                keskhyl: data.keskhyl || '-',
                osuus: data.osuus || 0,
                joukkue_id: data.joukkue_id || 0,
                joukkue_numero: data.joukkue_numero,
                joukkue_nimi: data.joukkue_nimi || '',
                osuus_txt: data.osuus_txt || '',
                aika_sec: (data.tarkistus && data.tarkistus.aika_sec != null)
                    ? data.tarkistus.aika_sec
                    : (data.aika_sec != null ? data.aika_sec : null),
                aika_txt: (data.tarkistus && data.tarkistus.aika_txt) || data.aika_txt || ''
            });
        }
        talletaIltaSnapshot();
        asetaEhdotukset(data.ehdotukset);
        if (data.kysy) {
            var kysyLeimat = (body && body.leimat) || odottaaLeimat || [];
            var emitKysy = (body && body.emit) || data.emit;
            if (onkoAutomaatti() && samaNayttoEmit(emitKysy)) {
                return;
            }
            if (onkoAutomaatti()) {
                var paikAuto = haeOsanottajaEmitilla(emitKysy);
                if (paikAuto) {
                    naytaAutomaatti({
                        emit: emitKysy,
                        etunimi: paikAuto.etunimi,
                        sukunimi: paikAuto.sukunimi,
                        nimi: paikAuto.nimi,
                        sarja: paikAuto.rata || paikAuto.sarja,
                        keskhyl: paikAuto.keskhyl,
                        kilpailija_id: paikAuto.id,
                        tarkistus: {
                            ok: String(paikAuto.keskhyl || '').toUpperCase() === 'T',
                            keskhyl: paikAuto.keskhyl,
                            aika_txt: paikAuto.aika_txt || '',
                            aika_sec: paikAuto.aika_sec
                        },
                        leimat: data.leimat || kysyLeimat
                    });
                    soitaAani('varoitus');
                    return;
                }
                naytaAutomaatti({
                    kysy: true,
                    emit: emitKysy,
                    leimat: data.leimat || kysyLeimat,
                    message: data.message
                });
                soitaAani('varoitus');
                return;
            }
            naytaKysely(body && body.emit, kysyLeimat);
            if (cfg.iltarasti) {
                if (leimaEl && !(kysyLeimat && kysyLeimat.length && moodi === 'maali')) {
                    leimaEl.hidden = true;
                    leimaEl.innerHTML = '';
                }
                naytaTulos('varoitus', '<strong>Emit ' + escapeHtml(String(body && body.emit)) + '</strong><br>'
                    + escapeHtml(data.message || 'Uusi kortti. Luetaanko lähteväksi vai pois maastosta?'));
            } else {
                naytaLeimat(data.leimat || kysyLeimat, data.tarkistus);
                naytaTulos('varoitus', '<strong>Emit ' + escapeHtml(String(body && body.emit)) + '</strong><br>'
                    + escapeHtml(data.message || (cfg.viesti
                        ? 'Tuntematon kortti. Luetaanko 1. osuuden lähteväksi vai vaihtoon/maaliin?'
                        : 'Uusi kortti. Luetaanko lähteväksi vai maaliin?')));
            }
            soitaAani('varoitus');
            return;
        }
        var luokka = data.taso === 'ok' ? 'ok' : (data.taso === 'varoitus' ? 'varoitus' : 'virhe');
        if (kioski) {
            kioskiHenkilo(data);
            soitaAani(aaniVastauksesta(data, luokka));
            return;
        }
        if (onkoAutomaatti()) {
            naytaAutomaatti(data);
            soitaAani(aaniVastauksesta(data, luokka));
            return;
        }
        if (cfg.iltarasti && (kayta === 'maastoon' || (kayta === 'tallenna' && tarkoitus !== 'maali'))) {
            asetaMoodi('lahto', { sailo: true });
            piilotaKysely();
            naytaTulos(luokka, '<strong>' + escapeHtml(data.message || 'Maastoon') + '</strong>'
                + (data.nimi ? '<br>' + escapeHtml(data.nimi) : ''));
            if (lisaaEl && data.taso === 'ok') {
                lisaaEl.hidden = true;
            }
            if (leimaEl) {
                leimaEl.hidden = true;
                leimaEl.innerHTML = '';
            }
            if (data.kilpailija_id || data.luotu) {
                lisaaViimeisiin(data);
            }
            soitaAani(aaniVastauksesta(data, luokka));
            if (data.taso === 'ok') {
                lomakeMuokattu = false;
                odottaaEmit = 0;
                odottaaLeimat = [];
                tyhjennaLomake();
                piilotaEhdotukset();
                asetaEhdotukset([]);
            }
            return;
        }
        if (cfg.iltarasti && (moodi === 'maali' || kayta === 'lue' || kayta === 'maali'
            || kayta === 'vaihda_rata' || kayta === 'paatos' || kayta === 'pois_maastosta')) {
            if (moodi !== 'maali') {
                asetaMoodi('maali', { sailo: true });
            }
            piilotaKysely();
            var kaipaaKirjausta = data.taso === 'virhe' && !data.kilpailija_id;
            if (lisaaEl) {
                lisaaEl.hidden = !kaipaaKirjausta;
            }
            if (kaipaaKirjausta && lisaaEl) {
                asetaEmitKentta((body && body.emit) || data.emit || odottaaEmit);
                naytaEhdotukset(odottaaEmit);
            }
            naytaIltarastiMaali(data, luokka);
            if (data.kilpailija_id || data.luotu) {
                lisaaViimeisiin(data);
            }
            soitaAani(aaniVastauksesta(data, luokka));
            return;
        }
        var html = '<strong>' + escapeHtml(data.message || 'Virhe') + '</strong>';
        if (cfg.viesti && (data.joukkue_nimi || data.osuus || data.nimi)) {
            html += '<br>' + viestiHenkiloHtml(data);
        } else {
            if (data.nimi) {
                html += '<br>' + escapeHtml(String(data.numero != null ? data.numero : ''))
                    + ' ' + escapeHtml(data.nimi);
            }
            if (data.sarja) {
                html += '<br><span class="muted">' + escapeHtml(data.sarja) + '</span>';
            }
            if (data.emit) {
                html += '<br><span class="muted">Emit ' + escapeHtml(String(data.emit)) + '</span>';
            }
        }
        html += tarkistusHtml(data);
        naytaTulos(luokka, html);
        naytaLeimat((data.leimat && data.leimat.length) ? data.leimat : (odottaaLeimat || []), data.tarkistus);
        naytaPaatos(data);
        if (cfg.iltarasti && moodi !== 'maali' && data && (data.kilpailija_id || data.etunimi || data.sukunimi || data.emit)) {
            taytaLomake(data, data.emit || (body && body.emit));
        }
        if (data.kilpailija_id || data.luotu) {
            lisaaViimeisiin(data);
        }
        soitaAani(aaniVastauksesta(data, luokka));
        if ((kayta === 'tallenna' || kayta === 'maastoon' || kayta === 'pois_maastosta') && data.taso === 'ok') {
            if (!cfg.iltarasti && lisaaEl) {
                lisaaEl.hidden = true;
            }
            lomakeMuokattu = false;
            odottaaEmit = 0;
            odottaaLeimat = [];
            tyhjennaLomake();
            piilotaEhdotukset();
            asetaEhdotukset([]);
        }
    }

    async function laheta(kilpailija, moodiOverride, extra) {
        var nro = ++lahetysNro;
        var body;
        kesken = true;
        try {
            body = rakennaLahetys(kilpailija, moodiOverride, extra);
            if (!onkoVerkko()) {
                jonoon(body);
                if (nro === lahetysNro) {
                    kasitteleVastaus(paikallinenVastaus(body), body);
                }
                return;
            }
            var data = await lahetaFetch(body);
            if (nro !== lahetysNro) {
                return;
            }
            try {
                kasitteleVastaus(data, body);
            } catch (eKas) {
                naytaTulos('virhe', 'Kortin käsittely keskeytyi. Lue uudelleen.');
                soitaAani('varoitus');
            }
        } catch (e) {
            if (nro !== lahetysNro) {
                return;
            }
            if (e && e.name === 'AbortError') {
                naytaTulos('varoitus', 'Palvelin ei vastannut. Lue kortti uudelleen.');
                soitaAani('varoitus');
                return;
            }
            if (onkoVerkkovirhe(e)) {
                jonoon(body);
                kasitteleVastaus(paikallinenVastaus(body), body);
            } else {
                naytaTulos('virhe', escapeHtml((e && e.message) || 'Tallennus epäonnistui.'));
                naytaLeimat((body && body.leimat) || odottaaLeimat || [], null);
                soitaAani('varoitus');
            }
        } finally {
            if (nro === lahetysNro) {
                kesken = false;
            }
        }
        if (nro === lahetysNro && onkoVerkko() && lueJono().length && !(viimeVastaus && viimeVastaus.kysy)) {
            tyhjennaJono();
        }
    }

    async function avaaKilpailija(emit) {
        if (kesken) {
            return;
        }
        kesken = true;
        try {
            if (!onkoVerkko()) {
                var paik = haeOsanottajaEmitilla(emit);
                if (paik) {
                    taytaLomake({
                        loytyi: true,
                        emit: emit,
                        etunimi: paik.etunimi,
                        sukunimi: paik.sukunimi,
                        seura: paik.seura,
                        sarja_id: paik.sarja_id,
                        kilpailija_id: paik.id
                    }, emit);
                    piilotaEhdotukset();
                } else {
                    asetaEhdotukset((cfg.nimet || []).filter(function (n) {
                        return parseInt(n.emit, 10) === emit;
                    }));
                    naytaEhdotukset(emit);
                }
                kesken = false;
                return;
            }
            var resp = await fetch('/api/api_emit_luku.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    kilpailu_id: cfg.kilpailuId,
                    moodi: 'hae',
                    emit: emit
                })
            });
            var data = await resp.json();
            if (data.taso === 'virhe') {
                naytaTulos('virhe', escapeHtml(data.message || 'Virhe'));
                soitaAani('virhe');
                return;
            }
            asetaEhdotukset(data.ehdotukset);
            taytaLomake(data, emit);
            var vanhaEl = document.getElementById('lisaa-vanha');
            if (data.loytyi) {
                if (vanhaEl && !cfg.iltarasti) {
                    vanhaEl.hidden = false;
                    vanhaEl.textContent = 'Jo kilpailussa'
                        + (data.numero != null ? ', numero ' + data.numero : '')
                        + (data.sarja ? ', ' + data.sarja : '')
                        + '. Voit korjata tiedot.';
                }
                if (!cfg.iltarasti) {
                    naytaTulos('varoitus', '<strong>Emit ' + escapeHtml(String(emit)) + '</strong><br>Täydennä tai korjaa tiedot.');
                }
                piilotaEhdotukset();
                soitaAani('varoitus');
            } else {
                if (vanhaEl) {
                    vanhaEl.hidden = true;
                }
                naytaEhdotukset(emit);
                if (!cfg.iltarasti) {
                    naytaTulos('ok', '<strong>Emit ' + escapeHtml(String(emit)) + '</strong><br>Uusi ' + (cfg.iltarasti ? 'henkilö' : 'kilpailija') + '. Täytä ' + rataSana + ' ja nimi.');
                } else if (viimeEhdotukset.length) {
                    naytaTulos('ok', '<strong>Emit ' + escapeHtml(String(emit)) + '</strong><br>Valitse tallennettu henkilö tai lisää uusi.');
                }
                soitaAani('ok');
            }
            if (lisaaEl && lisaaEl.scrollIntoView && !cfg.iltarasti) {
                lisaaEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            var suku = document.getElementById('sukunimi');
            var etu = document.getElementById('etunimi');
            var kohde = suku && !suku.value ? suku : etu;
            if (kohde) {
                kohde.focus();
            }
        } catch (e) {
            var paik2 = haeOsanottajaEmitilla(emit);
            if (paik2) {
                taytaLomake({
                    loytyi: true,
                    emit: emit,
                    etunimi: paik2.etunimi,
                    sukunimi: paik2.sukunimi,
                    seura: paik2.seura,
                    sarja_id: paik2.sarja_id,
                    kilpailija_id: paik2.id
                }, emit);
            } else {
                naytaTulos('virhe', 'Yhteysvirhe');
                soitaAani('virhe');
            }
        }
        kesken = false;
    }

    function korttiLuettu(sanoma) {
        var nyt = Date.now();
        var nLeimat = (sanoma.leimat && sanoma.leimat.length) || 0;
        if (sanoma.badge === viimeBadge && nLeimat <= (odottaaLeimat || []).length) {
            if (nyt - viimeAika < 8000 || samaNayttoEmit(sanoma.badge)) {
                viimeAika = nyt;
                return;
            }
        }
        if (lomakeKesken() && sanoma.badge !== odottaaEmit) {
            naytaTulos('varoitus', 'Tallenna ensin emit ' + odottaaEmit + '.');
            soitaAani('varoitus');
            return;
        }
        viimeBadge = sanoma.badge;
        viimeAika = nyt;
        nayttiKortin = true;
        if (window.parent && window.parent !== window && window.parent.JahKuori
            && typeof window.parent.JahKuori.naytaLukija === 'function') {
            var kuoriBody = null;
            try {
                kuoriBody = window.parent.document && window.parent.document.body;
            } catch (eKuori) { /* */ }
            if (!(kuoriBody && kuoriBody.classList.contains('is-lukija-nakyva'))) {
                window.parent.JahKuori.naytaLukija({ eiHistoria: onkoAutomaatti() });
            }
        }
        var korttiSana = (sanoma.lahde === 'si' || onkoSportIdent()) ? 'Kortti' : 'Emit';
        if (laiteEl) {
            laiteEl.textContent = korttiSana + ' ' + sanoma.badge;
        }
        var html = '<strong>' + korttiSana + ' ' + escapeHtml(String(sanoma.badge)) + '</strong>';
        var paik = haeOsanottajaEmitilla(sanoma.badge);
        if (paik) {
            if (cfg.viesti && (paik.joukkue_nimi || paik.osuus)) {
                html = viestiHenkiloHtml({
                    joukkue_nimi: paik.joukkue_nimi,
                    joukkue_numero: paik.joukkue_numero,
                    numero: paik.joukkue_numero != null ? paik.joukkue_numero : paik.numero,
                    osuus: paik.osuus,
                    osuus_txt: paik.osuus_txt,
                    etunimi: paik.etunimi,
                    sukunimi: paik.sukunimi,
                    nimi: paik.nimi,
                    sarja: paik.rata || paik.sarja,
                    emit: sanoma.badge
                });
            } else {
                var pnimi = ((paik.sukunimi || '') + ' ' + (paik.etunimi || '')).trim() || paik.nimi || '';
                if (pnimi) {
                    html = '<strong>' + escapeHtml(String(pnimi)) + '</strong>'
                        + '<br><span class="muted">' + korttiSana + ' ' + escapeHtml(String(sanoma.badge)) + '</span>';
                }
            }
        }
        if (sanoma.viikko && !cfg.iltarasti) {
            html += '<br><span class="muted">viikko ' + escapeHtml(String(sanoma.viikko))
                + (sanoma.vuosi ? '/' + escapeHtml(String(sanoma.vuosi)) : '') + '</span>';
        } else if (sanoma.viikko) {
            lokiin('Emit ' + sanoma.badge + ' kortin viikko ' + sanoma.viikko
                + (sanoma.vuosi ? '/' + sanoma.vuosi : '') + ' (ei näytetä).');
        }
        if (sanoma.leimat && sanoma.leimat.length) {
            html += '<br><span class="muted">' + sanoma.leimat.length + ' leimaa luettu</span>';
        } else if (moodi === 'maali' || moodi === 'automaatti') {
            html += '<br><span class="muted">Kortilta ei tullut leimoja — lue uudelleen</span>';
        }
        html += '<br>Luetaan rastit…';
        if (!(kesken && sanoma.badge === viimeBadge) && !samaNayttoEmit(sanoma.badge)) {
            naytaTulos('ok', html);
        }
        var leimat = (sanoma.leimat || []).map(function (l) {
            return {
                koodi: l.koodi,
                kello_sec: l.kello_sec != null ? l.kello_sec : l.aika_sec,
                kello: l.kello || '',
                aika_sec: l.aika_sec
            };
        });
        if (!(odottaaLeimat && odottaaLeimat.length > nLeimat && sanoma.badge === viimeBadge)) {
            odottaaLeimat = leimat;
        }
        var maaliNakyma = moodi === 'maali' || moodi === 'automaatti' || kioski;
        if (maaliNakyma || !cfg.iltarasti) {
            if ((odottaaLeimat || []).length) {
                naytaLeimat(odottaaLeimat, samaNayttoEmit(sanoma.badge) ? (viimeVastaus && viimeVastaus.tarkistus) : null);
            }
        } else if (leimaEl && !samaNayttoEmit(sanoma.badge)) {
            leimaEl.hidden = true;
            leimaEl.innerHTML = '';
        }
        asetaEmitKentta(sanoma.badge);
        if (cfg.iltarasti && !kioski && !onkoAutomaatti()) {
            var tunnettu = (cfg.osanottajat || []).some(function (h) {
                return parseInt(h.emit, 10) === parseInt(sanoma.badge, 10) && emitOnMaastossa(h);
            });
            if (!tunnettu) {
                var valmis = haeOsanottajaEmitilla(sanoma.badge);
                naytaKysely(sanoma.badge, leimat);
                naytaTulos('varoitus', '<strong>Emit ' + escapeHtml(String(sanoma.badge)) + '</strong><br>'
                    + (valmis
                        ? 'Tämä kortti on jo luettu pois maastosta. Luetaanko lähteväksi vai pois maastosta?'
                        : 'Uusi kortti. Luetaanko lähteväksi vai pois maastosta?'));
            } else if (moodi !== 'maali') {
                asetaMoodi('maali', { sailo: true });
            }
        }
        if (kioski) {
            laheta({ emit: sanoma.badge, leimat: leimat });
            return;
        }
        laheta({ emit: sanoma.badge, leimat: leimat }, 'lue').then(function () {
            if ((moodi === 'maali' || (viimeVastaus && viimeVastaus.tarkistus)) && !sanoma.lukija) {
                lokiin('Kortin lopussa ei näy lukijakoodia 250 tai 253.');
            }
        });
    }

    function tilaLuettu(sanoma) {
        if (onkoSportIdent() || (sanoma && sanoma.laite === 'SportIdent')) {
            saatiinTila = true;
            if (laiteEl) {
                laiteEl.textContent = 'SportIdent' + (sanoma.koodi ? ' asema ' + sanoma.koodi : '');
            }
            if (!nayttiKortin) {
                naytaTulos('ok', 'SportIdent-lukija vastaa. Näytä kortti.');
            }
            return;
        }
        var osat = [];
        if (sanoma.laite) {
            osat.push(sanoma.laite);
        }
        if (sanoma.koodi != null && !isNaN(sanoma.koodi)) {
            osat.push('koodi ' + sanoma.koodi);
        }
        if (sanoma.moodi != null && !isNaN(sanoma.moodi)) {
            osat.push('moodi ' + sanoma.moodi);
        }
        if (sanoma.kello) {
            osat.push('kello ' + sanoma.kello.teksti);
        }
        if (sanoma.sarjanumero) {
            osat.push('sn ' + sanoma.sarjanumero);
        }
        if (laiteEl) {
            laiteEl.textContent = laitteenRivi(osat.join(' · '));
        }
        saatiinTila = true;
        if (sanoma.moodi != null && !isNaN(sanoma.moodi) && sanoma.moodi !== 0 && !moodiKorjattu) {
            moodiKorjattu = true;
            naytaTulos('varoitus', 'Lukijan moodi on ' + sanoma.moodi + '. Pirilä vaatii moodin 0, joten asetan sen. Näytä kortti sen jälkeen.');
            lokiin('Asetetaan moodi 0 komennolla /PP0');
            kirjoita('/PP0\r\n/ST\r\n');
            return;
        }
        if (sanoma.koodi != null && !isNaN(sanoma.koodi) && sanoma.koodi < 66 && !koodiKorjattu) {
            koodiKorjattu = true;
            naytaTulos('varoitus', 'Lukijan koodi ' + sanoma.koodi
                + ' ei lähetä kortteja tietokoneelle. Asetan koodiksi 250. Näytä kortti sen jälkeen uudelleen.');
            soitaAani('varoitus');
            lokiin('Asetetaan koodi 250 komennolla /CD250');
            kirjoita('/CD250\r\n/ST\r\n');
            return;
        }
        if (!nayttiKortin) {
            naytaTulos('ok', 'Lukija vastaa'
                + (sanoma.koodi != null && !isNaN(sanoma.koodi) ? ' (koodi ' + sanoma.koodi + ')' : '')
                + '. Näytä kortti.');
        }
    }

    function laitteenRivi(lisa) {
        if (lisa) {
            return lisa;
        }
        return '';
    }

    function paivitaTavut() {
    }

    function odota(ms) {
        return new Promise(function (r) { setTimeout(r, ms); });
    }

    function kirjoita(teksti) {
        kirjoitusJono = kirjoitusJono.then(function () {
            return kirjoitaNyt(teksti);
        }).catch(function (e) {
            lokiin('Lähetys epäonnistui' + (e && e.message ? ': ' + e.message : ''));
            if (auki && laiteEl) {
                laiteEl.textContent = 'Komennon lähetys epäonnistui';
            }
        });
        return kirjoitusJono;
    }

    function kirjoitaTavut(bytes) {
        kirjoitusJono = kirjoitusJono.then(function () {
            return kirjoitaTavutNyt(bytes);
        }).catch(function (e) {
            lokiin('SI-lähetys epäonnistui' + (e && e.message ? ': ' + e.message : ''));
        });
        return kirjoitusJono;
    }

    async function kirjoitaTavutNyt(bytes) {
        if (!portti || !portti.writable || !bytes || !bytes.length) {
            return;
        }
        var writer = portti.writable.getWriter();
        try {
            await writer.write(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes));
        } finally {
            writer.releaseLock();
        }
    }

    async function kirjoitaNyt(teksti) {
        if (!portti || !portti.writable) {
            return;
        }
        var bytes = new TextEncoder().encode(teksti);
        var writer = portti.writable.getWriter();
        try {
            var i;
            for (i = 0; i < bytes.length; i++) {
                await writer.write(new Uint8Array([bytes[i]]));
                await odota(bytes[i] === 10 ? 100 : 5);
            }
        } finally {
            writer.releaseLock();
        }
        lokiin('LÄHETYS ' + teksti.replace(/\r/g, '\\r').replace(/\n/g, '\\n'));
    }

    async function valmistelu() {
        if (onkoSportIdent()) {
            await odota(600);
            if (!auki || !siKone) {
                return;
            }
            var ok = await siKone.aloita();
            if (!ok) {
                lokiin('Kokeillaan SportIdent 4800 baudia');
                try {
                    await asetaLinja(4800, 1);
                } catch (eSi) { /* */ }
                ok = await siKone.aloita();
            }
            if (!ok) {
                naytaTulos('varoitus', 'SportIdent-asema ei vastannut heti. Pidä asema lukumoodissa (Read SI-Cards) ja näytä kortti.');
            } else if (!nayttiKortin) {
                naytaTulos('ok', 'SportIdent yhdistetty. Näytä kortti.');
            }
            return;
        }
        if (ftdiKaytossa()) {
            return;
        }
        await odota(700);
        if (!auki) {
            return;
        }
        lokiin('Kuunnellaan kuten Pirilä. Kysytään vain tila, moodia ei muuteta vielä.');
        await kirjoita('/ST\r\n');
        await odota(2200);
        if (!auki || saatiinTila || loytyiMerkkeja) {
            return;
        }
        var paketit = usbLaskuri();
        var viesti;
        if (paketit > 0) {
            viesti = 'Lukija on linjalla (' + paketit + ' USB-pakettia) mutta ei ole lähettänyt merkkejä. Näytä kortti. Jos datatavuja pysyy nollassa, vaihda nopeus 9600.';
        } else {
            viesti = 'Selain ei saa USB-paketteja lukijalta. Irrota kaapeli ja yhdistä uudelleen.';
        }
        lokiin(viesti);
        naytaTulos('varoitus', escapeHtml(viesti));
    }

    var kerays = [];
    var keraysAjastin = null;
    var keraysAlku = 0;
    var keraysViime = 0;
    var otsikkoLahetetty = 0;
    var KERAYS_IDLE_MS = 2500;
    var KERAYS_MAX_MS = 8000;

    function asetaKerays(data) {
        kerays = data && data.length ? [data] : [];
        if (!kerays.length) {
            keraysAlku = 0;
            keraysViime = 0;
        }
    }

    function kasitteleTavut(bytes) {
        if (!bytes || !bytes.length) {
            return;
        }
        if (puskuri) {
            puskuri.tavut = (puskuri.tavut || 0) + bytes.length;
        }
        if (onkoSportIdent() && siKone) {
            siKone.syota(bytes);
            return;
        }
        kerays.push(bytes);
        keraysViime = Date.now();
        if (!keraysAlku) {
            keraysAlku = keraysViime;
        }
        var ennakko = yhdistaTavut(kerays);
        if (ennakko.length > 2000 && proto.lueKehykset) {
            var trim = proto.lueKehykset(ennakko);
            if (trim.alku > 0) {
                asetaKerays(ennakko.slice(trim.alku));
                ennakko = yhdistaTavut(kerays);
            } else if (trim.alku < 0) {
                asetaKerays(ennakko.slice(ennakko.length - 250));
                ennakko = yhdistaTavut(kerays);
            }
        }
        if (proto.etsiLukija && proto.etsiLukija(ennakko).length) {
            clearTimeout(keraysAjastin);
            tulkitseKerays();
            return;
        }
        var ots = proto.lueOtsikko ? proto.lueOtsikko(ennakko) : null;
        if (ots && ots.badge && ots.badge !== otsikkoLahetetty) {
            otsikkoLahetetty = ots.badge;
            if (!(cfg.iltarasti && (moodi === 'maali' || moodi === 'automaatti'))) {
                korttiLuettu(ots);
            }
        }
        if (!keraysAjastin) {
            keraysAjastin = setTimeout(tulkitseKerays, 400);
        }
    }

    function tulkitseKerays() {
        keraysAjastin = null;
        if (!kerays.length) {
            return;
        }
        var data = yhdistaTavut(kerays);
        var nyt = Date.now();
        var idle = nyt - (keraysViime || nyt);
        var ikainen = nyt - (keraysAlku || nyt);
        var luetut = proto.lueKehykset
            ? proto.lueKehykset(data)
            : { sanomat: proto.etsiLukija ? proto.etsiLukija(data) : [], jaljella: data, alku: -1 };
        if (luetut.sanomat && luetut.sanomat.length) {
            asetaKerays(luetut.jaljella);
            otsikkoLahetetty = 0;
            luetut.sanomat.forEach(function (s) {
                korttiLuettu(s);
            });
            if (kerays.length) {
                keraysAlku = nyt;
                keraysViime = nyt;
                keraysAjastin = setTimeout(tulkitseKerays, 400);
            }
            return;
        }
        var kesken = proto.keskenLukija && proto.keskenLukija(data);
        if ((kesken || idle < KERAYS_IDLE_MS) && ikainen < KERAYS_MAX_MS) {
            keraysAjastin = setTimeout(tulkitseKerays, 200);
            return;
        }
        if (luetut.alku > 0) {
            asetaKerays(data.slice(luetut.alku));
            keraysAlku = nyt;
            keraysAjastin = setTimeout(tulkitseKerays, 400);
            return;
        }
        var sanomat = [];
        if (!kesken) {
            proto.luoPuskuri().syota(data, function (s) {
                sanomat.push(s);
            });
        }
        var kortti = null;
        var tila = null;
        var i;
        for (i = 0; i < sanomat.length; i++) {
            if (sanomat[i].tyyppi === 'kortti' && sanomat[i].badge > 0) {
                var nLeima = (sanomat[i].leimat && sanomat[i].leimat.length) || 0;
                var vanhaN = (kortti && kortti.leimat && kortti.leimat.length) || 0;
                if (!kortti || nLeima >= vanhaN) {
                    kortti = sanomat[i];
                }
            } else if (sanomat[i].tyyppi === 'tila') {
                tila = sanomat[i];
            }
        }
        var loki = document.getElementById('emit-loki');
        if (kortti) {
            loytyiMerkkeja = true;
            asetaKerays(new Uint8Array(0));
            otsikkoLahetetty = 0;
            if (loki) {
                loki.textContent = '';
            }
            korttiLuettu(kortti);
            return;
        }
        if (tila) {
            loytyiMerkkeja = true;
            asetaKerays(new Uint8Array(0));
            otsikkoLahetetty = 0;
            if (loki) {
                loki.textContent = '';
            }
            tilaLuettu(tila);
            return;
        }
        if (data.length > 12 && !nayttiKortin && !cfg.iltarasti) {
            naytaTulos('varoitus', 'Lukijalta tuli ' + data.length + ' tavua, mutta kortin numeroa ei vielä saatu. ' + nayteHex(data));
        } else if (data.length) {
            lokiin('Osittainen kehys ' + data.length + ' tavua, odotetaan loppua.');
        }
        asetaKerays(new Uint8Array(0));
        otsikkoLahetetty = 0;
    }

    async function herataFtdi() {
        var dev = portti.device_;
        var inn = portti.inEndpoint_.endpointNumber;
        var out = portti.outEndpoint_ ? portti.outEndpoint_.endpointNumber : 2;
        var ifnum = portti.inEndpoint_.interfaceNumber;
        if (ifnum == null) {
            ifnum = 0;
        }
        try {
            await dev.selectAlternateInterface(ifnum, 0);
        } catch (e) { /* jo valittu */ }
        try {
            await dev.controlTransferOut({
                requestType: 'vendor',
                recipient: 'device',
                request: 1,
                value: 0x0303,
                index: 0
            });
            await dev.controlTransferOut({
                requestType: 'vendor',
                recipient: 'device',
                request: 9,
                value: 16,
                index: 0
            });
            await dev.controlTransferOut({
                requestType: 'vendor',
                recipient: 'device',
                request: 0,
                value: 1,
                index: 0
            });
        } catch (e) {
            lokiin('FTDI-herätys: ' + (e && e.message ? e.message : e));
        }
        try {
            await dev.clearHalt('in', inn);
        } catch (e2) {
            lokiin('Vastaanoton nollaus: ' + (e2 && e2.message ? e2.message : e2));
        }
        try {
            await dev.clearHalt('out', out);
        } catch (e3) { /* ulos */ }
        lokiin('Vastaanotto avattu, päätepiste ' + inn + ', paketti ' + (portti.maxPacketSize_ || 64));
    }

    function ftdiKaytossa() {
        return !!(portti && portti.device_ && portti.inEndpoint_);
    }

    function pelkkiaTyhjia(data) {
        var i;
        for (i = 0; i < data.length; i++) {
            if (data[i] !== 0 && data[i] !== 0xff) {
                return false;
            }
        }
        return true;
    }

    function otsakeTeksti(a, b) {
        var osat = [a.toString(16), b.toString(16)];
        if (b & 0x10) {
            osat.push('BREAK');
        }
        if (b & 0x08) {
            osat.push('kehysvirhe');
        }
        if (b & 0x04) {
            osat.push('pariteetti');
        }
        if (b & 0x02) {
            osat.push('ylivuoto');
        }
        return osat.join(' ');
    }

    function yhdistaTavut(palat) {
        var n = 0;
        var i;
        for (i = 0; i < palat.length; i++) {
            n += palat[i].length;
        }
        var out = new Uint8Array(n);
        var o = 0;
        for (i = 0; i < palat.length; i++) {
            out.set(palat[i], o);
            o += palat[i].length;
        }
        return out;
    }

    function ftdiData(tulos, maxPkt) {
        if (!tulos || tulos.status !== 'ok' || !tulos.data || tulos.data.byteLength < 2) {
            return null;
        }
        var raw = new Uint8Array(tulos.data.buffer.slice(
            tulos.data.byteOffset,
            tulos.data.byteOffset + tulos.data.byteLength
        ));
        var osat = [];
        var i;
        for (i = 0; i < raw.length; i += maxPkt) {
            var end = Math.min(i + maxPkt, raw.length);
            if (end - i > 2) {
                osat.push(raw.subarray(i + 2, end));
            }
        }
        if (!osat.length) {
            return null;
        }
        return yhdistaTavut(osat);
    }

    function nayteHex(data) {
        var n = Math.min(data.length, 14);
        var hex = [];
        var i;
        for (i = 0; i < n; i++) {
            hex.push((data[i] < 16 ? '0' : '') + data[i].toString(16));
        }
        return hex.join(' ') + (data.length > n ? ' …' : '');
    }

    function arvioiEmit(data) {
        var stx = 0;
        var etx = 0;
        var tab = 0;
        var merkki = 0;
        var roska = 0;
        var i;
        for (i = 0; i < data.length; i++) {
            var b = data[i];
            if (b === 2) {
                stx++;
            } else if (b === 3) {
                etx++;
            } else if (b === 9) {
                tab++;
            } else if ((b >= 32 && b < 127) || b === 10 || b === 13) {
                merkki++;
            } else if (b !== 0 && b !== 255) {
                roska++;
            }
        }
        var sanomia = 0;
        var kokeilu = proto.luoPuskuri();
        kokeilu.syota(data, function (s) {
            if (s.tyyppi === 'kortti' || s.tyyppi === 'tila') {
                sanomia++;
            }
        });
        return {
            sanomia: sanomia,
            stx: stx,
            etx: etx,
            tab: tab,
            merkki: merkki,
            roska: roska,
            n: data.length,
            piste: sanomia * 1000 + Math.min(stx, etx) * 80 + tab * 20 + merkki - roska * 3
        };
    }

    function kehysKelpaa(arvio) {
        return arvio.sanomia > 0 || (arvio.stx > 0 && arvio.etx > 0 && arvio.tab > 0 && arvio.merkki > arvio.roska);
    }

    async function asetaNopeus(baud) {
        if (portti && typeof portti.setBaud === 'function') {
            await portti.setBaud(baud);
        } else if (portti && typeof portti.reconfigure === 'function') {
            await portti.reconfigure({ baudRate: baud });
        }
        var valinta = document.getElementById('baud');
        if (valinta) {
            valinta.value = String(baud);
        }
        paivitaUsb();
    }

    async function asetaLinja(baud, stopBits) {
        nykyStop = stopBits;
        await asetaNopeus(baud);
        if (portti && typeof portti.setLine === 'function') {
            await portti.setLine(8, 'none', stopBits);
        } else if (portti && typeof portti.reconfigure === 'function') {
            try {
                await portti.reconfigure({ baudRate: baud, dataBits: 8, stopBits: stopBits, parity: 'none' });
            } catch (e) { /* selain pitää vanhan linjan */ }
        }
    }

    async function tyhjennaVastaanotto(dev, ep) {
        try {
            await dev.controlTransferOut({
                requestType: 'vendor',
                recipient: 'device',
                request: 0,
                value: 1,
                index: 0
            });
            await dev.clearHalt('in', ep);
        } catch (e) { /* tyhjennys */ }
    }

    function linjaKelpaa(data) {
        if (proto.etsiLukija(data).length) {
            return true;
        }
        if (proto.keskenLukija && proto.keskenLukija(data)) {
            return true;
        }
        return kehysKelpaa(arvioiEmit(data));
    }

    async function lueJakso(dev, ep, maxPkt, ms) {
        var palat = [];
        var alku = Date.now();
        while (auki && Date.now() - alku < ms) {
            var jaljella = ms - (Date.now() - alku);
            if (jaljella < 40) {
                break;
            }
            var lupaus = dev.transferIn(ep, maxPkt);
            var tulos;
            try {
                tulos = await Promise.race([
                    lupaus,
                    odota(jaljella).then(function () {
                        throw new Error('aikakatkaisu');
                    })
                ]);
            } catch (e) {
                if (!auki) {
                    break;
                }
                if (e && e.message === 'aikakatkaisu') {
                    try { await dev.clearHalt('in', ep); } catch (e2) { /* */ }
                    lupaus.catch(function () { /* keskeytetty */ });
                    break;
                }
                throw e;
            }
            portti.usbTilat = (portti.usbTilat || 0) + 1;
            var data = ftdiData(tulos, maxPkt);
            if (!data || !data.length || pelkkiaTyhjia(data)) {
                if (data) {
                    nollaTavuja += data.length;
                }
                continue;
            }
            palat.push(data);
        }
        return yhdistaTavut(palat);
    }

    async function lueFtdi() {
        var dev = portti.device_;
        var ep = portti.inEndpoint_.endpointNumber;
        var maxPkt = portti.maxPacketSize_ || 64;
        await herataFtdi();
        if (onkoSportIdent()) {
            await asetaLinja(38400, 1);
            await tyhjennaVastaanotto(dev, ep);
            naytaTulos('ok', 'SportIdent: kuunnellaan 38400 baudia. Näytä kortti.');
        } else {
        var linjat = [
            { baud: 9600, stop: 2 },
            { baud: 115200, stop: 2 },
            { baud: 9600, stop: 1 },
            { baud: 115200, stop: 1 },
            { baud: 19200, stop: 1 }
        ];
        var ix = 0;
        var loytyi = false;
        puskuri = proto.luoPuskuri();
        while (auki && !loytyi) {
            var linja = linjat[ix % linjat.length];
            ix++;
            await asetaLinja(linja.baud, linja.stop);
            await tyhjennaVastaanotto(dev, ep);
            naytaTulos('ok', 'Kortti saa olla lukijassa. Kuunnellaan ' + linja.baud + ' baudia, ' + linja.stop + ' stop-bittiä.');
            if (laiteEl) {
                laiteEl.textContent = 'Kuunnellaan ' + linja.baud + ' / ' + linja.stop + ' stop';
            }
            var data = await lueJakso(dev, ep, maxPkt, 1100);
            var kortteja = proto.etsiLukija(data).length;
            var arvio = arvioiEmit(data);
            lokiin('Nopeus ' + linja.baud + ', stop ' + linja.stop + ': ' + data.length + ' tavua, kortteja ' + kortteja
                + ', stx ' + arvio.stx + ', etx ' + arvio.etx
                + (data.length ? ' · ' + nayteHex(data) : ''));
            if (linjaKelpaa(data)) {
                loytyi = true;
                loytyiMerkkeja = true;
                try { localStorage.setItem('jah-emit-baud', String(linja.baud)); } catch (e2) { /* */ }
                kasitteleTavut(data);
            } else if (data.length) {
                naytaTulos('varoitus', 'Lukija lähetti ' + data.length + ' tavua nopeudella '
                    + linja.baud + ', ' + linja.stop + ' stop-bittiä.<br><span class="muted">'
                    + nayteHex(data) + '</span>');
            }
        }
        }
        if (!auki) {
            return;
        }
        while (auki) {
            var lupaus = dev.transferIn(ep, maxPkt);
            var tulos;
            try {
                tulos = await Promise.race([
                    lupaus,
                    odota(1500).then(function () {
                        throw new Error('aikakatkaisu');
                    })
                ]);
            } catch (e3) {
                if (!auki) {
                    break;
                }
                if (e3 && e3.message === 'aikakatkaisu') {
                    try { await dev.clearHalt('in', ep); } catch (e4) { /* */ }
                    lupaus.catch(function () { /* keskeytetty */ });
                    continue;
                }
                lukijaIrti('ftdi');
                break;
            }
            portti.usbTilat = (portti.usbTilat || 0) + 1;
            var pala = ftdiData(tulos, maxPkt);
            if (!pala || !pala.length || pelkkiaTyhjia(pala)) {
                if (pala) {
                    nollaTavuja += pala.length;
                }
                continue;
            }
            kasitteleTavut(pala);
        }
    }

    async function lueSilmukka() {
        var reader = portti.readable.getReader();
        try {
            while (auki) {
                var tulos = await reader.read();
                if (tulos.done) {
                    if (auki) {
                        lukijaIrti('sarja-loppu');
                    }
                    break;
                }
                if (tulos.value && tulos.value.length) {
                    kasitteleTavut(tulos.value);
                }
            }
        } catch (e) {
            if (auki) {
                lokiin('LUKU KATKESI ' + (e && e.message ? e.message : e));
                lukijaIrti('sarja');
            }
        } finally {
            try { reader.releaseLock(); } catch (e2) { /* jo vapaa */ }
        }
    }

    function onPuhelin() {
        return /Android/i.test(navigator.userAgent);
    }

    function usbVirhe(e) {
        var nimi = e && e.name ? e.name : '';
        var viesti = e && e.message ? e.message : '';
        if (nimi === 'NotFoundError') {
            return 'Lukijaa ei valittu. Kytke USB-kaapeli ja paina Yhdistä.';
        }
        if (nimi === 'SecurityError' || nimi === 'NotAllowedError') {
            return 'Selain ei avannut USB-ikkunaa. Paina Yhdistä uudelleen Chromessa tai Edgessä ja salli lukija.';
        }
        if (/claimInterface|Unable to claim|access denied|device is busy/i.test(viesti)) {
            return onPuhelin()
                ? 'Puhelin piti lukijan itsellään. Irrota kaapeli, kytke uudelleen ja valitse Chrome, jos puhelin kysyy millä sovelluksella laite avataan.'
                : 'Windows pitää lukijaa itsellään. Irrota kaapeli, kytke uudelleen ja paina Yhdistä. Jos laite näkyy COM-porttina, valitse se listasta.';
        }
        if (/user gesture|Must be handling/i.test(viesti)) {
            return 'Selain ei avannut USB-ikkunaa. Paina Yhdistä uudelleen.';
        }
        return 'USB-yhteys ei auennut. ' + viesti;
    }

    function odotaJahUsbSerial() {
        if (window.JahUsbSerial) {
            return Promise.resolve(window.JahUsbSerial);
        }
        return new Promise(function (ok, ei) {
            var n = 0;
            var t = setInterval(function () {
                n += 1;
                if (window.JahUsbSerial) {
                    clearInterval(t);
                    ok(window.JahUsbSerial);
                    return;
                }
                if (n >= 40) {
                    clearInterval(t);
                    ei(new Error('USB-sarjamoduuli ei latautunut. Avaa Automaatti uudelleen Chromessa.'));
                }
            }, 50);
        });
    }

    var USB_SARJA_SUODATTIMET = [
        { usbVendorId: 0x067B },
        { usbVendorId: 0x0403 },
        { usbVendorId: 0x1A86 },
        { usbVendorId: 0x10C4 },
        { usbVendorId: 0x2341 },
        { usbVendorId: 0x239A },
        { usbVendorId: 0x2E8A },
        { usbVendorId: 0x0483 },
        { usbVendorId: 0x26AC },
        { usbVendorId: 0x1209 },
        { usbVendorId: 0x27AC }
    ];

    function yhdistaKohde(ev) {
        return !!(ev && ev.target && ev.target.closest
            && ev.target.closest('#yhdista, #emit-automaatti-yhdista, #lukija-yhteys'));
    }

    function pyydaPorttiHetissa() {
        var opts = { filters: USB_SARJA_SUODATTIMET };
        try {
            if (!eiUsbPolyfill && navigator.usb && window.JahUsbSerial) {
                return window.JahUsbSerial.requestPort(opts);
            }
            if (navigator.serial) {
                return navigator.serial.requestPort(opts);
            }
            if (navigator.usb && window.JahUsbSerial) {
                return window.JahUsbSerial.requestPort(opts);
            }
        } catch (eHetissa) {
            return Promise.reject(eHetissa);
        }
        return Promise.reject(new Error('Tämä selain ei avaa USB-sarjaporttia. Käytä Chromea tai Edgeä.'));
    }

    function yhdistaNapista() {
        var lupaus = null;
        if (!auki) {
            lupaus = pyydaPorttiHetissa();
        }
        yhdista({ porttiLupaus: lupaus });
    }

    function kuoriUsbPortti(laite) {
        if (!laite) {
            return null;
        }
        if (laite.vendorId != null && window.JahUsbSerial
                && typeof window.JahUsbSerial.portFromDevice === 'function') {
            return window.JahUsbSerial.portFromDevice(laite);
        }
        if (typeof laite.open === 'function' && typeof laite.getInfo === 'function') {
            return laite;
        }
        return null;
    }

    async function avaaKuoriUsb() {
        var lupaus = null;
        try {
            var annettu = window.JahKuoriUsbLaite;
            window.JahKuoriUsbLaite = null;
            if (navigator.usb && window.JahUsbSerial
                    && typeof window.JahUsbSerial.portFromDevice === 'function') {
                var devices = [];
                try {
                    devices = await navigator.usb.getDevices();
                } catch (eDev) { /* */ }
                var d = null;
                var i;
                if (annettu && annettu.vendorId != null && devices.length) {
                    for (i = 0; i < devices.length; i++) {
                        if (devices[i].vendorId === annettu.vendorId
                                && devices[i].productId === annettu.productId) {
                            d = devices[i];
                            break;
                        }
                    }
                }
                if (!d && devices.length) {
                    d = devices[devices.length - 1];
                }
                if (!d && annettu && annettu.vendorId != null) {
                    d = annettu;
                }
                if (d) {
                    lupaus = Promise.resolve(window.JahUsbSerial.portFromDevice(d));
                }
            }
            if (!lupaus) {
                var kuoriP = kuoriUsbPortti(annettu);
                if (kuoriP) {
                    lupaus = Promise.resolve(kuoriP);
                }
            }
            if (!lupaus) {
                var tunnettu = await haeSallittuPortti();
                if (tunnettu) {
                    lupaus = Promise.resolve(tunnettu);
                }
            }
            if (!lupaus) {
                throw new Error('Lukijaa ei saatu auki. Paina Yhdistä uudelleen ja valitse laite.');
            }
        } catch (eAvaus) {
            naytaTulos('virhe', usbVirhe(eAvaus));
            soitaAani('virhe');
            paivitaNapit();
            return;
        }
        yhdista({ porttiLupaus: lupaus });
    }

    async function usbPolyfillPortti(opts) {
        if (!navigator.usb) {
            throw new Error(onPuhelin()
                ? 'Puhelimessa lukija avataan Chromella.'
                : 'Tämä selain ei avaa USB-sarjaporttia. Käytä Chromea tai Edgeä.');
        }
        if (window.JahUsbSerial) {
            return window.JahUsbSerial.requestPort(opts);
        }
        var serial = await odotaJahUsbSerial();
        return serial.requestPort(opts);
    }

    async function valitsePortti() {
        var opts = { filters: USB_SARJA_SUODATTIMET };
        if (!eiUsbPolyfill && navigator.usb && (onPuhelin() || window.JahUsbSerial)) {
            try {
                return await usbPolyfillPortti(opts);
            } catch (eUsb) {
                if (!navigator.serial || onPuhelin()) {
                    throw eUsb;
                }
                if (eUsb && eUsb.name !== 'NotFoundError' && eUsb.name !== 'SecurityError') {
                    throw eUsb;
                }
            }
        } else if (onPuhelin()) {
            throw new Error('Puhelimessa lukija avataan Chromella.');
        }
        if (navigator.serial) {
            try {
                return await navigator.serial.requestPort(opts);
            } catch (eSuod) {
                if (eSuod && eSuod.name === 'NotFoundError') {
                    return navigator.serial.requestPort();
                }
                throw eSuod;
            }
        }
        if (navigator.usb) {
            return usbPolyfillPortti(opts);
        }
        throw new Error('Tämä selain ei avaa USB-sarjaporttia. Käytä Chromea tai Edgeä.');
    }

    function lukijaHalutaan() {
        try {
            return localStorage.getItem('jah-lukija-halutaan') === '1';
        } catch (eHal) {
            return false;
        }
    }

    function asetaLukijaHalutaan(paalla) {
        try {
            if (paalla) {
                localStorage.setItem('jah-lukija-halutaan', '1');
            } else {
                localStorage.removeItem('jah-lukija-halutaan');
            }
        } catch (eHal2) { /* */ }
    }

    function portinTiedot(p) {
        var vid = 0;
        var pid = 0;
        try {
            if (p && typeof p.getInfo === 'function') {
                var info = p.getInfo() || {};
                vid = parseInt(info.usbVendorId, 10) || 0;
                pid = parseInt(info.usbProductId, 10) || 0;
            }
        } catch (eInfo) { /* */ }
        if (!vid && p && p.device_) {
            vid = parseInt(p.device_.vendorId, 10) || 0;
            pid = parseInt(p.device_.productId, 10) || 0;
        }
        return { vid: vid, pid: pid };
    }

    function talletaLukijaLaite(p) {
        var t = portinTiedot(p);
        if (!t.vid) {
            return;
        }
        try {
            localStorage.setItem('jah-lukija-vid', String(t.vid));
            localStorage.setItem('jah-lukija-pid', String(t.pid || ''));
        } catch (eTal) { /* */ }
    }

    function valitseSallittuPortti(lista) {
        if (!lista || !lista.length) {
            return null;
        }
        var muistiVid = 0;
        var muistiPid = 0;
        try {
            muistiVid = parseInt(localStorage.getItem('jah-lukija-vid'), 10) || 0;
            muistiPid = parseInt(localStorage.getItem('jah-lukija-pid'), 10) || 0;
        } catch (eMuisti) { /* */ }
        var i;
        if (muistiVid) {
            for (i = 0; i < lista.length; i++) {
                var t = portinTiedot(lista[i]);
                if (t.vid === muistiVid && (!muistiPid || t.pid === muistiPid)) {
                    return lista[i];
                }
            }
        }
        return lista[0];
    }

    async function haeSallittuPortti() {
        try {
            if (onPuhelin()) {
                var serial = window.JahUsbSerial || await odotaJahUsbSerial();
                var usbPortit = await serial.getPorts();
                var usbValittu = valitseSallittuPortti(usbPortit);
                if (usbValittu) {
                    return usbValittu;
                }
            }
            if (navigator.serial && navigator.serial.getPorts) {
                var serialPortit = await navigator.serial.getPorts();
                var serialValittu = valitseSallittuPortti(serialPortit);
                if (serialValittu) {
                    return serialValittu;
                }
            }
            if (!eiUsbPolyfill && window.JahUsbSerial && navigator.usb) {
                var polyPortit = await window.JahUsbSerial.getPorts();
                var polyValittu = valitseSallittuPortti(polyPortit);
                if (polyValittu) {
                    return polyValittu;
                }
            }
        } catch (ePortti) { /* ei aiempaa lupaa */ }
        return null;
    }

    function samaUsbLaite(device) {
        if (!device || !portti) {
            return false;
        }
        if (portti.device_ && portti.device_ === device) {
            return true;
        }
        var t = portinTiedot(portti);
        return !!(t.vid && t.vid === device.vendorId && (!t.pid || t.pid === device.productId));
    }

    function lukijaIrti(syy) {
        if (!auki && !portti) {
            return;
        }
        var p = portti;
        auki = false;
        portti = null;
        siKone = null;
        lukijaKatkesi = true;
        if (p) {
            try { p.close(); } catch (eSulje) { /* irti */ }
        }
        lokiin('LUKIJA IRTOSI' + (syy ? ' (' + syy + ')' : ''));
        paivitaNapit();
        if (onkoAutomaatti()) {
            naytaAutomaattiVirhe('Lukija irtosi. Kytke kaapeli ja paina Yhdistä lukija.');
            puhuAutomaatti('Lukija irtosi');
        } else {
            asetaYhteys('Lukija irtosi');
            naytaTulos('virhe', 'Lukija irtosi. Yhdistä uudelleen.');
        }
        soitaAani('virhe');
    }

    function kuunteleUsbTapahtumat() {
        if (navigator.usb && navigator.usb.addEventListener) {
            navigator.usb.addEventListener('disconnect', function (ev) {
                if (samaUsbLaite(ev && ev.device)) {
                    lukijaIrti('usb-disconnect');
                }
            });
            navigator.usb.addEventListener('connect', function () {
                if (!auki && lukijaHalutaan()) {
                    yhdista({ hiljainen: true }).catch(function () { /* */ });
                }
            });
        }
        if (navigator.serial && navigator.serial.addEventListener) {
            navigator.serial.addEventListener('disconnect', function (ev) {
                if (portti && ev && ev.target === portti) {
                    lukijaIrti('serial-disconnect');
                }
            });
            navigator.serial.addEventListener('connect', function () {
                if (!auki && lukijaHalutaan()) {
                    yhdista({ hiljainen: true }).catch(function () { /* */ });
                }
            });
        }
    }

    function vapautaYhdistaminen() {
        yhdistaKaynnissa = false;
        if (yhdistaVahti) {
            clearTimeout(yhdistaVahti);
            yhdistaVahti = null;
        }
    }

    function naytaYhdistetaan() {
        if (yhdistaBtn && !auki) {
            yhdistaBtn.textContent = 'Yhdistetään…';
        }
        var autoBtn = document.getElementById('emit-automaatti-yhdista');
        if (autoBtn) {
            autoBtn.textContent = 'Yhdistetään…';
        }
        var ots = document.getElementById('emit-automaatti-ots');
        if (onkoAutomaatti() && ots && !auki) {
            ots.textContent = 'Yhdistetään';
        } else if (!cfg.iltarasti) {
            asetaYhteys('Yhdistetään lukijaa…');
        }
    }

    async function yhdista(opts) {
        var hiljainen = !!(opts && opts.hiljainen);
        if (yhdistaKaynnissa) {
            return;
        }
        yhdistaKaynnissa = true;
        yhdistaVahti = setTimeout(function () {
            yhdistaKaynnissa = false;
            yhdistaVahti = null;
        }, 20000);
        try {
            if (auki) {
                if (hiljainen) {
                    return;
                }
                auki = false;
                siKone = null;
                lukijaKatkesi = false;
                try { await portti.close(); } catch (e) { /* suljettu */ }
                portti = null;
                asetaLukijaHalutaan(false);
                asetaYhteys('Lukija katkaistu');
                paivitaNapit();
                return;
            }
            if (!hiljainen) {
                naytaYhdistetaan();
            }
            if (onkoSportIdent() && !siProto) {
                throw new Error('SportIdent-ohjelma ei latautunut. Päivitä sivu.');
            }
            if (opts && opts.porttiLupaus) {
                portti = await opts.porttiLupaus;
            } else {
                portti = await haeSallittuPortti();
                if (!portti && !hiljainen) {
                    portti = await valitsePortti();
                }
            }
            if (!portti) {
                if (hiljainen) {
                    return;
                }
                throw new Error('Lukijaa ei valittu.');
            }
            var si = onkoSportIdent();
            nykyStop = si ? 1 : 2;
            var baudAvaus = si ? 38400 : 9600;
            var baudValinta = document.getElementById('baud');
            if (baudValinta) {
                if (si && !Array.prototype.some.call(baudValinta.options, function (o) { return o.value === '38400'; })) {
                    var opt = document.createElement('option');
                    opt.value = '38400';
                    opt.textContent = 'SportIdent 38400';
                    baudValinta.appendChild(opt);
                }
                baudValinta.value = String(baudAvaus);
            }
            var avaus = {
                baudRate: baudAvaus,
                dataBits: 8,
                stopBits: si ? 1 : 2,
                parity: 'none',
                bufferSize: 255
            };
            try {
                await portti.open(avaus);
            } catch (eOpen) {
                var openViesti = eOpen && eOpen.message ? String(eOpen.message) : '';
                if (onPuhelin() || !navigator.serial
                        || !/claimInterface|Unable to claim|access denied|device is busy/i.test(openViesti)) {
                    throw eOpen;
                }
                eiUsbPolyfill = true;
                try { portti.close(); } catch (eSuljeUsb) { /* */ }
                try {
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({ type: 'jah-lukija', usb: false, kaytaSarja: true }, location.origin);
                    }
                } catch (eSarjaViesti) { /* */ }
                var sarjaPortti = valitseSallittuPortti(
                    await navigator.serial.getPorts()
                );
                if (!sarjaPortti) {
                    throw eOpen;
                }
                portti = sarjaPortti;
                await portti.open(avaus);
            }
            if (portti.setSignals) {
                try {
                    await portti.setSignals({ dataTerminalReady: true, requestToSend: true });
                } catch (e2) { /* polyfill asettaa DTR:n itse */ }
            }
            auki = true;
            lukijaKatkesi = false;
            avaaAani();
            if (!hiljainen) {
                avaaPuhe();
            }
            talletaLukijaLaite(portti);
            koodiKorjattu = false;
            moodiKorjattu = false;
            saatiinTila = false;
            nayttiKortin = false;
            nollaTavuja = 0;
            loytyiMerkkeja = false;
            puskuri = proto.luoPuskuri();
            siKone = si ? siProto.luoLukija({
                kirjoita: kirjoitaTavut,
                onKortti: korttiLuettu,
                onTila: tilaLuettu,
                onVirhe: function (err) {
                    naytaTulos('varoitus', escapeHtml(err && err.message ? err.message : String(err)));
                },
                loki: lokiin
            }) : null;
            asetaYhteys(kioski ? 'Lukija yhdistetty' : 'USB yhdistetty — lue kortti');
            if (laiteEl && !cfg.iltarasti) {
                laiteEl.textContent = 'Kuunnellaan lukijaa…';
            }
            var lokiEl = document.getElementById('emit-loki');
            if (lokiEl) {
                lokiEl.textContent = '';
            }
            if (!cfg.iltarasti) {
                laiteRivit().forEach(lokiin);
                naytaTulos('ok', 'USB yhdistetty. Kortti saa olla lukijassa.');
            }
            paivitaNapit();
            paivitaUsb();
            asetaLukijaHalutaan(true);
            if (onkoAutomaatti() && !hiljainen) {
                puhuAutomaatti('Lukija yhdistetty');
            }
            if (ftdiKaytossa()) {
                lueFtdi();
            } else {
                lueSilmukka();
            }
            valmistelu();
            if (typeof window.jahWakeLockRefresh === 'function') {
                window.jahWakeLockRefresh();
            }
        } catch (e) {
            auki = false;
            portti = null;
            paivitaNapit();
            if (!hiljainen) {
                naytaTulos('virhe', usbVirhe(e));
                soitaAani('virhe');
            }
        } finally {
            vapautaYhdistaminen();
        }
    }

    function varmistaYlaTyyppi() {
        var yla = document.querySelector('.emit-yhteys');
        if (!yla || yla.querySelector('.emit-lukija-tyypit')) {
            return;
        }
        var wrap = document.createElement('div');
        wrap.className = 'emit-lukija-tyypit';
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-label', 'Lukijan tyyppi');
        wrap.innerHTML = '<button type="button" class="emit-lukija-tyyppi" data-tyyppi="emit">Emit</button>'
            + '<button type="button" class="emit-lukija-tyyppi" data-tyyppi="si">SportIdent</button>';
        var yhd = document.getElementById('yhdista');
        if (yhd && yhd.parentNode === yla) {
            yla.insertBefore(wrap, yhd);
        } else {
            yla.insertBefore(wrap, yla.firstChild);
        }
        paivitaTyyppiNapit();
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('.emit-lukija-tyyppi') : null;
        if (!btn || btn.closest('#emit-automaatti')) {
            return;
        }
        ev.preventDefault();
        asetaLukijaTyyppi(btn.getAttribute('data-tyyppi'));
    });

    if (cfg.automaattiSivu) {
        document.body.classList.add('emit-automaatti-sivu', 'is-automaatti-moodi');
        siirraAutomaattiYlapalkkiin();
    } else if (cfg.iltarasti) {
        siirraPuheYlapalkkiin();
    }
    lisaaAutomaattiNappi();
    varmistaYlaTyyppi();
    paivitaTyyppiNapit();
    document.querySelectorAll('.emit-moodit .emit-moodi').forEach(function (btn) {
        btn.addEventListener('click', function () {
            asetaMoodi(btn.getAttribute('data-moodi'));
        });
    });
    sidoKysy('kysy-lahto', 'lahto');
    sidoKysy('kysy-maali', 'maali');
    var uusiHenkiloNappi = document.getElementById('emit-uusi-henkilo');
    if (uusiHenkiloNappi) {
        uusiHenkiloNappi.addEventListener('click', function () {
            ['etunimi', 'sukunimi', 'seura'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) {
                    el.value = '';
                }
            });
            merkitseEhdotusValituksi(null);
            naytaMaksu(null);
            if (lisaaEl) {
                lisaaEl.hidden = false;
            }
            var suku = document.getElementById('sukunimi');
            if (suku) {
                suku.focus();
            }
        });
    }
    var emitKentta = document.getElementById('emit-koodi');
    if (emitKentta && cfg.iltarasti) {
        emitKentta.addEventListener('change', function () {
            var emit = emitNumeroLomakkeesta();
            if (emit > 0) {
                avaaKilpailija(emit);
            } else {
                asetaEhdotukset([]);
                piilotaEhdotukset();
            }
        });
    }
    var paatosRata = document.getElementById('paatos-rata');
    if (paatosRata) {
        paatosRata.addEventListener('change', function () { lahetaRataVaihto(); });
    }
    var arvausNappi = document.getElementById('arvaus-vaihda');
    if (arvausNappi) {
        arvausNappi.addEventListener('click', lahetaArvausVaihto);
    }
    var paatosOk = document.getElementById('paatos-ok');
    if (paatosOk) {
        paatosOk.addEventListener('click', function () { lahetaPaatos('T'); });
    }
    var paatosHyl = document.getElementById('paatos-hyl');
    if (paatosHyl) {
        paatosHyl.addEventListener('click', function () { lahetaPaatos('H'); });
    }
    var paatosKes = document.getElementById('paatos-kes');
    if (paatosKes) {
        paatosKes.addEventListener('click', function () { lahetaPaatos('K'); });
    }
    function lahetaNimella(moodiNimi) {
        var suku = document.getElementById('nimi-suku');
        var etu = document.getElementById('nimi-etu');
        var seuraEl = document.getElementById('nimi-seura');
        var rataEl = document.getElementById('nimi-rata');
        var emitEl = document.getElementById('nimi-emit');
        if (!suku || !suku.value.trim()) {
            naytaTulos('virhe', 'Anna vähintään sukunimi.');
            return;
        }
        var emitNro = emitEl ? parseInt(emitEl.value, 10) : 0;
        if (!emitNro || emitNro < 1) {
            emitNro = 0;
        }
        laheta({ emit: emitNro, leimat: [] }, moodiNimi, {
            etunimi: etu ? etu.value : '',
            sukunimi: suku.value,
            seura: seuraEl ? seuraEl.value : '',
            sarja_id: rataEl ? parseInt(rataEl.value, 10) : 0
        });
    }
    var nimiMaastoon = document.getElementById('nimi-maastoon');
    if (nimiMaastoon) {
        nimiMaastoon.addEventListener('click', function () { lahetaNimella('maastoon'); });
    }
    var nimiPois = document.getElementById('nimi-pois');
    if (nimiPois) {
        nimiPois.addEventListener('click', function () { lahetaNimella('pois_maastosta'); });
    }
    function sidoKysy(id, suunta) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('click', function () {
                avaaLisays(suunta);
            });
        }
    }

    if (yhdistaBtn) {
        yhdistaBtn.addEventListener('click', yhdistaNapista);
    }
    if (lukijaIkoni) {
        lukijaIkoni.addEventListener('click', yhdistaNapista);
    }

    var kasinForm = document.getElementById('kasin-form');
    if (kasinForm) kasinForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var badge = (document.getElementById('kasin-badge').value || '').replace(/\D+/g, '');
        document.getElementById('kasin-badge').value = '';
        if (!badge.length) {
            return;
        }
        if (badge.length < 4) {
            naytaTulos('virhe', 'Emit-numero on liian lyhyt.');
            return;
        }
        var emit = parseInt(badge, 10);
        if (lomakeKesken() && emit !== odottaaEmit) {
            naytaTulos('varoitus', 'Tallenna ensin emit ' + odottaaEmit + '.');
            soitaAani('varoitus');
            return;
        }
        laheta({ emit: emit, leimat: odottaaLeimat || [] }, kioski ? 'lahto' : 'lue');
    });

    if (lisaaEl) {
        lisaaEl.addEventListener('submit', function (e) {
            e.preventDefault();
            var emitNro = emitNumeroLomakkeesta() || odottaaEmit || 0;
            var eiAikaa = document.getElementById('ei-aikaa');
            var ilmanAikaa = !!(eiAikaa && eiAikaa.checked);
            if (!cfg.iltarasti && !emitNro) {
                naytaTulos('virhe', 'Lue ensin emit.');
                return;
            }
            if (cfg.iltarasti && !kentta('sukunimi').trim()) {
                naytaTulos('virhe', 'Anna vähintään sukunimi.');
                return;
            }
            var moodiLahetys = 'tallenna';
            if (cfg.iltarasti) {
                var nLeima = (odottaaLeimat || []).length;
                var pois = moodi === 'maali' || tarkoitus === 'maali' || nLeima > 0;
                if (pois) {
                    moodiLahetys = ilmanAikaa ? 'pois_maastosta' : 'maali';
                } else {
                    moodiLahetys = 'maastoon';
                }
            }
            asetaEmitKentta(emitNro);
            laheta({ emit: emitNro, leimat: odottaaLeimat }, moodiLahetys);
            piilotaKysely();
        });
        lisaaEl.addEventListener('input', function () {
            lomakeMuokattu = true;
        });
        lisaaEl.addEventListener('change', function () {
            lomakeMuokattu = true;
        });
    }

    function sido(id, tyyppi, fn) {
        var node = document.getElementById(id);
        if (node) {
            node.addEventListener(tyyppi, fn);
        }
    }
    sido('kysy-tila', 'click', function () {
        kirjoita('/ST\r\n');
    });
    sido('synkkaa-kello', 'click', function () {
        if (!window.confirm('Synkronoidaanko lukijan kello tämän laitteen kellonaikaan?')) {
            return;
        }
        kirjoita(proto.kelloKomento(new Date()));
    });
    sido('koodi-250', 'click', function () {
        if (!window.confirm('Asetetaanko lukijan koodiksi 250? Silloin kortista luetaan viimeisin kilpailu.')) {
            return;
        }
        kirjoita('/CD250\r\n/ST\r\n');
    });
    sido('koodi-248', 'click', function () {
        if (!window.confirm('Koodi 248 on maaliantenni. Rastileimat luetaan koodilla 250. Asetetaanko silti 248?')) {
            return;
        }
        kirjoita('/CD248\r\n/ST\r\n');
    });

    sido('nolla', 'change', function () {
        try { localStorage.setItem('jah-emit-nolla', document.getElementById('nolla').value); } catch (e) { /* */ }
    });
    sido('baud', 'change', function () {
        try { localStorage.setItem('jah-emit-baud', document.getElementById('baud').value); } catch (e) { /* */ }
        if (!auki) {
            return;
        }
        var baud = baudArvo();
        vaihdaNopeus(baud).then(function () {
            lokiin('Nopeus vaihdettu ' + baud);
            paivitaUsb();
            kirjoita('/ST\r\n');
        }).catch(function (e) {
            lokiin('Nopeuden vaihto epäonnistui' + (e && e.message ? ': ' + e.message : ''));
        });
    });

    document.querySelectorAll('.lukija-poistu').forEach(function (linkki) {
        linkki.addEventListener('click', function (e) {
            poistutaan = true;
            if (!window.confirm('Haluatko varmasti poistua lukijasta?')) {
                e.preventDefault();
                poistutaan = false;
            }
        });
    });

    try {
        if (cfg.automaattiSivu) {
            asetaMoodi('automaatti');
        } else if (cfg.vainMaali) {
            asetaMoodi('maali');
        } else if (!kioski) {
            var alku = urlMoodi() || cfg.alkuMoodi || '';
            if (alku === 'automaatti') {
                alku = '';
            }
            var tallennettu = localStorage.getItem('jah-emit-moodi');
            if (tallennettu === 'automaatti') {
                tallennettu = '';
            }
            if (alku && OHJE[alku]) {
                asetaMoodi(alku);
            } else if (tallennettu && OHJE[tallennettu]) {
                asetaMoodi(tallennettu);
            }
        }
        if (!kioski) {
            var nollaEl = document.getElementById('nolla');
            var nolla = localStorage.getItem('jah-emit-nolla');
            if (nolla && nollaEl) {
                nollaEl.value = nolla;
            }
            var baudEl = document.getElementById('baud');
            var baud = localStorage.getItem('jah-emit-baud');
            if (baud && baudEl) {
                baudEl.value = baud;
            }
        }
    } catch (e) { /* */ }

    if (!cfg.iltarasti) {
        if (onPuhelin()) {
            asetaYhteys('Paina Yhdistä. Lukija yhdistyy suoraan, jos se on jo sallittu.');
        } else if (!navigator.serial && !(window.JahUsbSerial && navigator.usb)) {
            asetaYhteys(kioski
                ? 'USB-lukija vaatii Chromen tai Edgen.'
                : 'USB-sarjaportti vaatii Chromen tai Edgen. Näppäile numero sisäänluvussa.');
        }
    }
    pyydaPysyvaTallennus();
    palautaIltaSnapshot().then(function () {
        return idbLue(jonoAvain());
    }).then(function (arr) {
        if (Array.isArray(arr) && arr.length && lueJono().length < arr.length) {
            try { localStorage.setItem(jonoAvain(), JSON.stringify(arr)); } catch (e2) { /* */ }
        }
        paivitaJonoBanneri();
        if (onkoVerkko() && lueJono().length) {
            tyhjennaJono();
        }
    });
    paivitaNapit();
    paivitaJonoBanneri();
    window.addEventListener('load', function () {
        if (cfg.automaattiSivu) {
            siirraAutomaattiYlapalkkiin();
            paivitaAutomaattiYhteys();
            paivitaNapit();
        } else if (cfg.iltarasti) {
            siirraPuheYlapalkkiin();
        }
    });
    setInterval(paivitaUsb, 400);
    if (typeof window.jahWakeLockRefresh === 'function') {
        window.jahWakeLockRefresh();
    }
    document.addEventListener('pointerdown', function (ev) {
        if (yhdistaKohde(ev)) {
            return;
        }
        avaaAani();
        avaaPuhe();
    }, { passive: true });
    if (window.speechSynthesis) {
        try {
            speechSynthesis.getVoices();
            speechSynthesis.addEventListener('voiceschanged', function () {
                suomiPuheAani();
            });
        } catch (eAani) { /* */ }
        setInterval(function () {
            try {
                if (automaattiPuhu && onkoAutomaatti() && speechSynthesis.paused) {
                    speechSynthesis.resume();
                }
            } catch (eRes) { /* */ }
        }, 4000);
    }
    window.addEventListener('online', function () { tyhjennaJono(); });
    window.addEventListener('offline', paivitaJonoBanneri);
    if (onkoVerkko() && lueJono().length) {
        tyhjennaJono();
    }
    window.JahAutomaatti = {
        parseAikaTxt: parseAikaTxt,
        malli: automaattiNayttoMalli,
        puhe: automaattiPuheTeksti,
        sijoitusHtml: sijoitusSeurantaHtmlsta
    };

    window.addEventListener('message', function (ev) {
        if (ev.origin !== location.origin || !ev.data || ev.data.type !== 'jah-kuori') {
            return;
        }
        if (ev.data.toiminto === 'automaatti') {
            cfg.automaattiSivu = true;
            document.body.classList.add('emit-automaatti-sivu', 'is-automaatti-moodi');
            asetaMoodi('automaatti');
            siirraAutomaattiYlapalkkiin();
            paivitaAutomaattiYhteys();
            paivitaNapit();
        } else if (ev.data.toiminto === 'tulospalvelu') {
            if (!cfg.automaattiSivu && moodi !== 'automaatti') {
                return;
            }
            cfg.automaattiSivu = false;
            document.body.classList.remove('emit-automaatti-sivu', 'is-automaatti-moodi');
            asetaMoodi('lahto');
            siirraPuheYlapalkkiin();
            paivitaNapit();
        } else if (ev.data.toiminto === 'kuori-usb') {
            document.body.classList.add('emit-kuori-usb');
        } else if (ev.data.toiminto === 'usb-valmis') {
            avaaKuoriUsb();
        }
    });

    kuunteleUsbTapahtumat();
    if (lukijaHalutaan() && usbLukijaSaatavilla()) {
        var kaynnistaLukija = function () {
            yhdista({ hiljainen: true }).catch(function () { /* ei aiempaa porttia */ });
        };
        if (onPuhelin() && !window.JahUsbSerial) {
            odotaJahUsbSerial().then(kaynnistaLukija).catch(function () { /* */ });
        } else {
            kaynnistaLukija();
        }
    }
})();
