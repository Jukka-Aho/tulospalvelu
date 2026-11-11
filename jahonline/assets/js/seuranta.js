(function () {
    'use strict';

    function formatAikaSec(sec) {
        var neg = sec < 0;
        sec = Math.abs(Math.floor(sec));
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        var txt;

        if (h > 0) {
            txt = h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        } else {
            txt = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        }

        return neg ? '-' + txt : txt;
    }

    function withParens(el, text) {
        if (el.getAttribute('data-parens') === '1') {
            return '(' + text + ')';
        }

        return text;
    }

    function parseLocalDatetime(str) {
        if (!str) {
            return null;
        }

        var normalized = str.replace(' ', 'T');
        var ts = Date.parse(normalized);

        if (Number.isNaN(ts)) {
            return null;
        }

        return Math.floor(ts / 1000);
    }

    function paivitaAjat() {
        var now = Math.floor(Date.now() / 1000);

        document.querySelectorAll('[data-live-elapsed]').forEach(function (el) {
            var baseSec = parseInt(el.getAttribute('data-base-sec'), 10);
            var punchUnix = parseLocalDatetime(el.getAttribute('data-punch-at'));

            if (Number.isNaN(baseSec) || punchUnix === null) {
                return;
            }

            el.textContent = withParens(el, formatAikaSec(baseSec + (now - punchUnix)));
        });

        document.querySelectorAll('[data-live-start]').forEach(function (el) {
            var startUnix = parseLocalDatetime(el.getAttribute('data-start-at'));

            if (startUnix === null) {
                return;
            }

            el.textContent = withParens(el, formatAikaSec(now - startUnix));
        });
    }

    paivitaAjat();
    setInterval(paivitaAjat, 1000);

    /** Rastiväliajat: sukunimilyhenne kun sija/nro/nimi scrollattu piiloon. */
    var lyhRaf = 0;

    function paivitaNimiLyhScroll(scrollEl) {
        if (!scrollEl || !scrollEl.classList.contains('lb-scroll')) {
            return;
        }
        if (!scrollEl.querySelector('.lb-rivi.has-va')) {
            scrollEl.classList.remove('is-past-name');
            return;
        }

        var past = false;
        var headNimi = scrollEl.querySelector('.lb-rivi.has-va.lb-head .lb-nimi');
        if (headNimi) {
            var sr = scrollEl.getBoundingClientRect();
            var nr = headNimi.getBoundingClientRect();
            past = nr.right <= sr.left + 2;
        } else {
            past = scrollEl.scrollLeft > 72;
        }
        scrollEl.classList.toggle('is-past-name', past);
    }

    function paivitaKaikkiNimiLyh() {
        document.querySelectorAll('.lb-scroll').forEach(paivitaNimiLyhScroll);
    }

    function scheduleNimiLyh(scrollEl) {
        if (lyhRaf) {
            return;
        }
        lyhRaf = requestAnimationFrame(function () {
            lyhRaf = 0;
            if (scrollEl) {
                paivitaNimiLyhScroll(scrollEl);
            } else {
                paivitaKaikkiNimiLyh();
            }
        });
    }

    document.addEventListener('scroll', function (e) {
        var t = e.target;
        if (t && t.classList && t.classList.contains('lb-scroll')) {
            scheduleNimiLyh(t);
        }
    }, true);

    window.addEventListener('resize', function () {
        scheduleNimiLyh(null);
    });
    document.addEventListener('lb-live-replaced', paivitaKaikkiNimiLyh);
    paivitaKaikkiNimiLyh();

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

    function formatEro(sec) {
        if (sec == null || isNaN(sec)) {
            return '';
        }
        if (sec <= 0) {
            return '0';
        }
        return '+' + formatAikaSec(sec).replace(/^00:/, '');
    }

    function mukavaMaxEro(sec) {
        sec = Math.max(1, Math.ceil(Number(sec) || 1));
        var portaat = [10, 15, 20, 30, 45, 60, 90, 120, 180, 240, 300, 360, 480, 600, 900, 1200, 1800];
        var i;
        for (i = 0; i < portaat.length; i++) {
            if (sec <= portaat[i]) {
                return portaat[i];
            }
        }
        return Math.ceil(sec / 300) * 300;
    }

    function yViivat(maxEro) {
        if (maxEro <= 15) {
            return [0, maxEro];
        }
        if (maxEro <= 60) {
            return [0, Math.round(maxEro / 2), maxEro];
        }
        return [0, Math.round(maxEro / 3), Math.round((2 * maxEro) / 3), maxEro];
    }

    function lyhennaNimi(nimi) {
        var t = String(nimi || '').trim();
        if (!t) {
            return '';
        }
        var osat = t.split(/\s+/);
        if (osat.length === 1) {
            return t.length > 12 ? t.slice(0, 11) + '…' : t;
        }
        return osat[0];
    }

    function henkiloAvain(h) {
        return String(h && h.sija != null ? h.sija : '') + '\t' + String((h && h.nimi) || '');
    }

    function paneeliAvain(paneeli) {
        var ots = paneeli.querySelector('.lb-otsikko');
        return ((ots && ots.textContent) || 'va').replace(/\s+/g, ' ').trim();
    }

    function oletusValitut(henkilot) {
        var jarj = (henkilot || []).slice().sort(function (a, b) {
            var as = a.sija == null ? 9999 : a.sija;
            var bs = b.sija == null ? 9999 : b.sija;
            return as - bs;
        });
        var out = {};
        jarj.slice(0, 5).forEach(function (h) {
            out[henkiloAvain(h)] = true;
        });
        return out;
    }

    var vaValinnat = {};

    function lueVaPaneeli(paneeli) {
        var head = paneeli.querySelector('.lb-rivi.has-va.lb-head');
        if (!head) {
            return null;
        }
        var rastit = [];
        head.querySelectorAll('.lb-va-head').forEach(function (h, ix) {
            var piste = (h.querySelector('.lb-va-piste') || {}).textContent || String(ix + 1);
            var koodi = (h.querySelector('.lb-va-koodi') || {}).textContent || '';
            rastit.push({
                ix: ix,
                piste: String(piste).trim(),
                koodi: String(koodi).trim()
            });
        });
        if (!rastit.length) {
            return null;
        }
        var henkilot = [];
        paneeli.querySelectorAll('.lb-rivi.has-va:not(.lb-head)').forEach(function (rivi) {
            var nimiEl = rivi.querySelector('.lb-nimi-paa, .lb-nimi-link, .lb-nimi');
            var nimi = '';
            if (nimiEl) {
                nimi = (nimiEl.getAttribute('title') || nimiEl.textContent || '').trim();
                nimi = nimi.replace(/\s+/g, ' ');
            }
            var sijaTxt = rivi.querySelector('.lb-sija');
            var sija = sijaTxt ? parseInt(String(sijaTxt.textContent || '').replace(/\D+/g, ''), 10) : NaN;
            var ajat = [];
            var sijat = [];
            rivi.querySelectorAll('.lb-va').forEach(function (solu) {
                var kok = solu.querySelector('.lb-va-stack-kok .lb-va-aika');
                var sij = solu.querySelector('.lb-va-stack-kok .lb-va-sija');
                ajat.push(parseAikaTxt(kok ? kok.textContent : ''));
                var sn = sij ? parseInt(String(sij.textContent || '').replace(/\D+/g, ''), 10) : NaN;
                sijat.push(isNaN(sn) ? null : sn);
            });
            henkilot.push({
                nimi: nimi,
                lyh: lyhennaNimi(nimi),
                sija: isNaN(sija) ? null : sija,
                ajat: ajat,
                sijat: sijat,
                tulos: parseAikaTxt((rivi.querySelector('.lb-aika') || {}).textContent)
            });
        });
        var j;
        var tilanne = rastit.map(function (r, ix) {
            var leimanneet = [];
            henkilot.forEach(function (h) {
                if (h.ajat[ix] != null) {
                    leimanneet.push({ nimi: h.nimi, lyh: h.lyh, aika: h.ajat[ix], sija: h.sijat[ix] });
                }
            });
            leimanneet.sort(function (a, b) { return a.aika - b.aika; });
            var karki = leimanneet[0] || null;
            return {
                rastit: r,
                karki: karki,
                n: leimanneet.length,
                total: henkilot.length,
                leimanneet: leimanneet
            };
        });
        var maxEro = 1;
        var viivat = henkilot.map(function (h) {
            var pisteet = [{ x: -1, y: 0, aika: 0 }];
            for (j = 0; j < rastit.length; j++) {
                if (h.ajat[j] == null) {
                    continue;
                }
                var karkiAika = tilanne[j].karki ? tilanne[j].karki.aika : h.ajat[j];
                var ero = h.ajat[j] - karkiAika;
                if (ero > maxEro) {
                    maxEro = ero;
                }
                pisteet.push({ x: j, y: ero, aika: h.ajat[j] });
            }
            return { henkilo: h, pisteet: pisteet };
        }).filter(function (v) { return v.pisteet.length > 1; });
        return {
            rastit: rastit,
            henkilot: henkilot,
            tilanne: tilanne,
            viivat: viivat,
            maxEro: maxEro
        };
    }

    var VA_VARIT = ['#2563eb', '#dc2626', '#059669', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#4f46e5', '#65a30d', '#ea580c'];

    function svgPolku(pisteet, x0, y0, xAskel, ySkaala) {
        return pisteet.map(function (p, i) {
            var x = x0 + (p.x + 1) * xAskel;
            var y = y0 + p.y * ySkaala;
            return (i ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1);
        }).join(' ');
    }

    function suodataViivat(data, valitut) {
        var nayta = (data.viivat || []).filter(function (v) {
            return valitut[henkiloAvain(v.henkilo)];
        });
        nayta.sort(function (a, b) {
            var as = a.henkilo.sija == null ? 9999 : a.henkilo.sija;
            var bs = b.henkilo.sija == null ? 9999 : b.henkilo.sija;
            return as - bs;
        });
        var maxEro = 1;
        nayta.forEach(function (v) {
            v.pisteet.forEach(function (p) {
                if (p.y > maxEro) {
                    maxEro = p.y;
                }
            });
        });
        return { nayta: nayta, maxEro: mukavaMaxEro(maxEro) };
    }

    function rakennaValinta(data, valitut, avain, paneeli) {
        var wrap = document.createElement('div');
        wrap.className = 'lb-va-valinta';
        var n = 0;
        Object.keys(valitut).forEach(function (k) {
            if (valitut[k]) {
                n += 1;
            }
        });
        var auki = document.createElement('button');
        auki.type = 'button';
        auki.className = 'lb-va-valinta-auki';
        auki.textContent = 'Kilpailijat kaaviossa (' + n + ')';
        var lista = document.createElement('div');
        lista.className = 'lb-va-valinta-lista';
        lista.hidden = true;
        auki.addEventListener('click', function () {
            lista.hidden = !lista.hidden;
            auki.classList.toggle('is-auki', !lista.hidden);
        });
        var toiminnot = document.createElement('div');
        toiminnot.className = 'lb-va-valinta-toiminnot';
        function nappi(txt, fn) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'lb-va-valinta-nappi';
            b.textContent = txt;
            b.addEventListener('click', function (ev) {
                ev.preventDefault();
                fn();
                vaValinnat[avain] = valitut;
                piirraVaGrafiikka(paneeli, data);
            });
            return b;
        }
        toiminnot.appendChild(nappi('Kärki 5', function () {
            valitut = oletusValitut(data.henkilot);
        }));
        toiminnot.appendChild(nappi('Kaikki', function () {
            valitut = {};
            (data.henkilot || []).forEach(function (h) {
                valitut[henkiloAvain(h)] = true;
            });
        }));
        lista.appendChild(toiminnot);
        (data.henkilot || []).forEach(function (h) {
            var key = henkiloAvain(h);
            var lab = document.createElement('label');
            lab.className = 'lb-va-valinta-rivi';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = !!valitut[key];
            if (cb.checked) {
                cb.setAttribute('checked', '');
            }
            cb.addEventListener('change', function () {
                if (cb.checked) {
                    valitut[key] = true;
                } else {
                    delete valitut[key];
                }
                var jaa = Object.keys(valitut).some(function (k) { return valitut[k]; });
                if (!jaa) {
                    valitut = oletusValitut(data.henkilot);
                }
                vaValinnat[avain] = valitut;
                piirraVaGrafiikka(paneeli, data);
            });
            lab.appendChild(cb);
            lab.appendChild(document.createTextNode(
                (h.sija ? h.sija + '. ' : '') + (h.nimi || '')
            ));
            lista.appendChild(lab);
        });
        wrap.appendChild(auki);
        wrap.appendChild(lista);
        return wrap;
    }

    function piirraVaGrafiikka(paneeli, data) {
        var vanha = paneeli.querySelector('.lb-va-grafiikka');
        var listaOliAuki = !!(vanha && vanha.querySelector('.lb-va-valinta-lista') && !vanha.querySelector('.lb-va-valinta-lista').hidden);
        if (vanha) {
            vanha.remove();
        }
        if (!data || !data.rastit.length) {
            return;
        }
        var avain = paneeliAvain(paneeli);
        if (!vaValinnat[avain]) {
            vaValinnat[avain] = oletusValitut(data.henkilot);
        }
        var valitut = vaValinnat[avain];
        var suod = suodataViivat(data, valitut);
        if (!suod.nayta.length) {
            valitut = oletusValitut(data.henkilot);
            vaValinnat[avain] = valitut;
            suod = suodataViivat(data, valitut);
        }
        var wrap = document.createElement('div');
        wrap.className = 'lb-va-grafiikka';
        wrap.setAttribute('aria-label', 'Tilanne rasteilla');
        wrap.appendChild(rakennaValinta(data, valitut, avain, paneeli));
        if (listaOliAuki) {
            var lista = wrap.querySelector('.lb-va-valinta-lista');
            var aukiBtn = wrap.querySelector('.lb-va-valinta-auki');
            if (lista) {
                lista.hidden = false;
            }
            if (aukiBtn) {
                aukiBtn.classList.add('is-auki');
            }
        }

        var asemat = document.createElement('div');
        asemat.className = 'lb-va-asemat';
        data.tilanne.forEach(function (t) {
            var r = t.rastit;
            var el = document.createElement('div');
            el.className = 'lb-va-asema';
            var karki = t.karki ? escapeAttr(t.karki.lyh || t.karki.nimi) : '–';
            var karkiAika = t.karki ? formatAikaSec(t.karki.aika).replace(/^00:/, '') : '';
            el.innerHTML = '<div class="lb-va-asema-nro">' + escapeAttr(r.piste) + '</div>'
                + (r.koodi ? '<div class="lb-va-asema-koodi">' + escapeAttr(r.koodi) + '</div>' : '')
                + '<div class="lb-va-asema-karki" title="' + escapeAttr(t.karki ? t.karki.nimi : '') + '">'
                + escapeAttr(karki) + '</div>'
                + '<div class="lb-va-asema-aika">' + escapeAttr(karkiAika) + '</div>'
                + '<div class="lb-va-asema-n">' + t.n + '/' + t.total + '</div>';
            asemat.appendChild(el);
        });
        wrap.appendChild(asemat);

        var nR = data.rastit.length;
        var W = Math.max(560, 88 + nR * 76);
        var H = 240;
        var padL = 48;
        var padR = 64;
        var padT = 12;
        var padB = 28;
        var xAskel = (W - padL - padR) / nR;
        var ySkaala = (H - padT - padB) / Math.max(suod.maxEro, 1);
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        svg.setAttribute('class', 'lb-va-kaavio');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'Ero kärkeen rasteittain');

        var grid = '';
        var g;
        for (g = 0; g < nR; g++) {
            var x = padL + (g + 1) * xAskel;
            grid += '<line x1="' + x + '" y1="' + padT + '" x2="' + x + '" y2="' + (H - padB)
                + '" class="lb-va-grid"/>';
            grid += '<text x="' + x + '" y="' + (H - 8) + '" class="lb-va-grid-txt">'
                + escapeAttr(data.rastit[g].piste) + '</text>';
        }
        yViivat(suod.maxEro).forEach(function (arvo) {
            var y = padT + arvo * ySkaala;
            grid += '<line x1="' + padL + '" y1="' + y.toFixed(1) + '" x2="' + (W - padR)
                + '" y2="' + y.toFixed(1) + '" class="lb-va-grid"/>';
            grid += '<text x="6" y="' + (y + 4).toFixed(1) + '" class="lb-va-grid-txt">'
                + (arvo ? formatEro(arvo) : '0') + '</text>';
        });
        svg.innerHTML = grid;

        suod.nayta.forEach(function (v, ix) {
            var vari = VA_VARIT[ix % VA_VARIT.length];
            var d = svgPolku(v.pisteet, padL, padT, xAskel, ySkaala);
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', vari);
            path.setAttribute('stroke-width', (v.henkilo.sija && v.henkilo.sija <= 3) ? '2.6' : '1.8');
            path.setAttribute('stroke-linejoin', 'round');
            path.setAttribute('stroke-linecap', 'round');
            svg.appendChild(path);
            var last = v.pisteet[v.pisteet.length - 1];
            var lx = padL + (last.x + 1) * xAskel;
            var ly = padT + last.y * ySkaala;
            var tit = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            tit.textContent = v.henkilo.nimi + (last.y ? ' ' + formatEro(last.y) : ' kärki');
            path.appendChild(tit);
            var lab = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            lab.setAttribute('x', String(lx + 4));
            lab.setAttribute('y', String(ly + 3));
            lab.setAttribute('class', 'lb-va-viiva-nimi');
            lab.setAttribute('fill', vari);
            lab.textContent = v.henkilo.lyh;
            svg.appendChild(lab);
        });
        wrap.appendChild(svg);

        var selite = document.createElement('div');
        selite.className = 'lb-va-selite';
        selite.textContent = 'Skaala valittujen mukaan. Kärki rastilla = 0.';
        wrap.appendChild(selite);

        var ots = paneeli.querySelector('.lb-otsikko');
        var scroll = paneeli.querySelector('.lb-scroll');
        if (ots && ots.nextSibling) {
            paneeli.insertBefore(wrap, ots.nextSibling);
        } else if (scroll) {
            paneeli.insertBefore(wrap, scroll);
        } else {
            paneeli.appendChild(wrap);
        }
    }

    function escapeAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function paivitaVaGrafiikat() {
        document.querySelectorAll('.lb-main--valiajat .lb-paneeli').forEach(function (paneeli) {
            piirraVaGrafiikka(paneeli, lueVaPaneeli(paneeli));
        });
    }

    document.addEventListener('lb-live-replaced', paivitaVaGrafiikat);
    paivitaVaGrafiikat();

    window.JahVaGrafiikka = {
        parseAikaTxt: parseAikaTxt,
        lueVaPaneeli: lueVaPaneeli,
        mukavaMaxEro: mukavaMaxEro,
        paivita: paivitaVaGrafiikat
    };
})();
