(function () {
    'use strict';

    var cfg = window.JAH_KELLO || {};
    var naytto = document.getElementById('kello-naytto');
    var laskuri = document.getElementById('kello-laskuri');
    var lahtoon = document.getElementById('kello-lahtoon');
    var seuraava = document.getElementById('kello-seuraava');
    var kuvio = document.getElementById('kello-kuvio');
    var synkka = document.getElementById('kello-synkka');
    var virhe = document.getElementById('kello-virhe');
    var aaniBtn = document.getElementById('kello-aani');
    var lyhytEl = document.getElementById('kello-lyhyet');
    var pitkaEl = document.getElementById('kello-pitkat');
    var minuuttiEl = document.getElementById('kello-minuutti');
    var naytaSeuraavaEl = document.getElementById('kello-nayta-seuraava');
    var naytaAikaEl = document.getElementById('kello-nayta-aika');
    var voimaEl = document.getElementById('kello-voima');
    var sarjaEl = document.getElementById('kello-sarja');
    var paikkaEl = document.getElementById('kello-paikka');
    var syotto = document.getElementById('kello-syotto');
    var lahdotNaytto = document.getElementById('kello-lahdot-naytto');
    var ajastinNaytto = document.getElementById('kello-ajastin-naytto');
    var ajastinTilaEl = document.getElementById('kello-ajastin-tila');
    var ajastinKellot = document.getElementById('kello-ajastin-kellot');
    var ajastinYlos = document.getElementById('kello-ajastin-ylos');
    var ajastinAlas = document.getElementById('kello-ajastin-alas');
    var ajastinYlosWrap = document.getElementById('kello-ajastin-ylos-wrap');
    var ajastinAlasWrap = document.getElementById('kello-ajastin-alas-wrap');
    var valiMinEl = document.getElementById('kello-vali-min');
    var valiSekEl = document.getElementById('kello-vali-sek');
    var kertaaEl = document.getElementById('kello-kertaa');
    var kaynnistaBtn = document.getElementById('kello-ajastin-kaynnista');
    var pysaytaBtn = document.getElementById('kello-ajastin-pysayta');
    var ajastinNollaaBtn = document.getElementById('kello-ajastin-nollaa');
    if (!naytto || !cfg.url) {
        return;
    }

    var AVAIN = 'jah-lahtokello';
    var LEAD_MS = 5000;
    var skew = 0;
    var ero = 0;
    var rivit = [];
    var paikat = [];
    var sarja = '';
    var paikka = '';
    var aani = false;
    var ctx = null;
    var ajastettu = {};
    var vilkku = 0;
    var tila = 'lahdot';
    var ajastinNayttoTila = 'molemmat';
    var ajastin = {
        kaynnissa: false,
        paussi: false,
        alkuMs: 0,
        kulunutPaussi: 0,
        valiMs: 6 * 60 * 1000,
        kertaa: 6
    };

    var esiasetukset = {
        tavallinen: { lyhyt: '30 10 5 4 3 2 1', pitka: '0' },
        harva: { lyhyt: '60 10', pitka: '0' },
        tihea: { lyhyt: '15 10 5 4 3 2 1', pitka: '0' }
    };

    function nytMs() {
        return Date.now() + skew + ero;
    }

    function lataaAsetus() {
        var tallennettu = {};
        try {
            tallennettu = JSON.parse(localStorage.getItem(AVAIN) || '{}') || {};
        } catch (e) {
            tallennettu = {};
        }
        if (lyhytEl && tallennettu.lyhyt) {
            lyhytEl.value = tallennettu.lyhyt;
        }
        if (pitkaEl && tallennettu.pitka) {
            pitkaEl.value = tallennettu.pitka;
        }
        if (minuuttiEl) {
            if (Object.prototype.hasOwnProperty.call(tallennettu, 'tyhjaMinuutti')) {
                minuuttiEl.checked = !!tallennettu.tyhjaMinuutti;
            } else {
                minuuttiEl.checked = true;
            }
        }
        if (naytaSeuraavaEl) {
            naytaSeuraavaEl.checked = tallennettu.naytaSeuraava !== false;
        }
        if (naytaAikaEl) {
            naytaAikaEl.checked = tallennettu.naytaAika !== false;
        }
        if (voimaEl && tallennettu.voima) {
            voimaEl.value = String(tallennettu.voima);
        }
        if (tallennettu.tila === 'ajastin' || tallennettu.tila === 'lahdot') {
            tila = tallennettu.tila;
        }
        if (valiMinEl && tallennettu.valiMin != null) {
            valiMinEl.value = String(tallennettu.valiMin);
        }
        if (valiSekEl && tallennettu.valiSek != null) {
            valiSekEl.value = String(tallennettu.valiSek);
        }
        if (kertaaEl && tallennettu.kertaa != null) {
            kertaaEl.value = String(tallennettu.kertaa);
        }
        if (tallennettu.ajastinNaytto === 'ylos' || tallennettu.ajastinNaytto === 'alas' || tallennettu.ajastinNaytto === 'molemmat') {
            ajastinNayttoTila = tallennettu.ajastinNaytto;
        }
        sarja = localStorage.getItem(AVAIN + '-sarja-' + cfg.kilpailuId) || '';
        paikka = localStorage.getItem(AVAIN + '-paikka-' + cfg.kilpailuId) || '';
        paivitaTilaValinnat();
    }

    function samaPaikka(rivi) {
        if (!paikka) {
            return true;
        }
        return String(rivi.lahtopaikka || 0) === String(paikka);
    }

    function talletaAsetus() {
        localStorage.setItem(AVAIN, JSON.stringify({
            lyhyt: lyhytEl ? lyhytEl.value : '',
            pitka: pitkaEl ? pitkaEl.value : '',
            minuutti: !!(minuuttiEl && minuuttiEl.checked),
            tyhjaMinuutti: !!(minuuttiEl && minuuttiEl.checked),
            naytaSeuraava: !naytaSeuraavaEl || naytaSeuraavaEl.checked,
            naytaAika: !naytaAikaEl || naytaAikaEl.checked,
            voima: voimaEl ? Number(voimaEl.value) : 70,
            tila: tila,
            valiMin: valiMinEl ? valiMinEl.value : '6',
            valiSek: valiSekEl ? valiSekEl.value : '0',
            kertaa: kertaaEl ? kertaaEl.value : '6',
            ajastinNaytto: ajastinNayttoTila
        }));
    }

    function numerot(teksti) {
        var osat = String(teksti || '').split(/[^0-9]+/);
        var out = [];
        var naky = {};
        for (var i = 0; i < osat.length; i++) {
            if (osat[i] === '') {
                continue;
            }
            var n = parseInt(osat[i], 10);
            if (!isFinite(n) || n < 0 || n > 600 || naky[n]) {
                continue;
            }
            naky[n] = true;
            out.push(n);
        }
        out.sort(function (a, b) { return b - a; });
        return out;
    }

    function kuvioNyt() {
        var lyhyt = numerot(lyhytEl ? lyhytEl.value : '');
        var pitka = numerot(pitkaEl ? pitkaEl.value : '');
        var pitkaM = {};
        for (var i = 0; i < pitka.length; i++) {
            pitkaM[pitka[i]] = true;
        }
        var kaikki = lyhyt.concat(pitka);
        var naky = {};
        var out = [];
        for (var j = 0; j < kaikki.length; j++) {
            if (naky[kaikki[j]]) {
                continue;
            }
            naky[kaikki[j]] = true;
            out.push({ s: kaikki[j], pitka: !!pitkaM[kaikki[j]] });
        }
        out.sort(function (a, b) { return b.s - a.s; });
        return out;
    }

    function kelloTeksti(ms) {
        try {
            return new Date(ms).toLocaleTimeString('fi-FI', {
                timeZone: 'Europe/Helsinki',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23'
            });
        } catch (e) {
            var d = new Date(ms);
            function p(n) { return (n < 10 ? '0' : '') + n; }
            return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
        }
    }

    function varmistaAani() {
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) {
            return null;
        }
        if (!ctx) {
            ctx = new AC();
        }
        if (ctx.state === 'suspended') {
            ctx.resume();
        }
        return ctx;
    }

    function soita(pitka, kun) {
        var audio = varmistaAani();
        if (!audio) {
            return;
        }
        var vol = voimaEl ? Number(voimaEl.value) / 100 : 0.7;
        if (!isFinite(vol)) {
            vol = 0.7;
        }
        vol = Math.max(0.02, Math.min(1, vol));
        var alku = kun || audio.currentTime;
        var kesto = pitka ? 0.62 : 0.12;
        var taajuus = 2090;
        var huippu = Math.min(1, vol * 1.9);
        function aanita(hz, vahvuus) {
            var o = audio.createOscillator();
            var g = audio.createGain();
            o.type = 'square';
            o.frequency.value = hz;
            g.gain.setValueAtTime(0.0001, alku);
            g.gain.exponentialRampToValueAtTime(vahvuus, alku + 0.008);
            if (pitka) {
                g.gain.setValueAtTime(vahvuus, alku + kesto * 0.78);
            }
            g.gain.exponentialRampToValueAtTime(0.0001, alku + kesto);
            o.connect(g);
            g.connect(audio.destination);
            o.start(alku);
            o.stop(alku + kesto + 0.02);
        }
        aanita(taajuus, huippu);
        aanita(taajuus * 2, Math.min(0.62, huippu * 0.5));
    }

    function soitaKaksi(kun) {
        var audio = varmistaAani();
        if (!audio) {
            return;
        }
        var alku = kun || audio.currentTime;
        soita(false, alku);
        soita(false, alku + 0.2);
    }

    function vilkahda() {
        var kellot = document.querySelectorAll('.kello-naytto');
        for (var i = 0; i < kellot.length; i++) {
            kellot[i].classList.add('is-piippi');
        }
        if (vilkku) {
            clearTimeout(vilkku);
        }
        vilkku = setTimeout(function () {
            for (var j = 0; j < kellot.length; j++) {
                kellot[j].classList.remove('is-piippi');
            }
        }, 180);
    }

    function onAjastin() {
        return tila === 'ajastin';
    }

    function ajastinValiMsAsetus() {
        var min = valiMinEl ? parseInt(valiMinEl.value, 10) : 6;
        var sek = valiSekEl ? parseInt(valiSekEl.value, 10) : 0;
        if (!isFinite(min) || min < 0) {
            min = 0;
        }
        if (!isFinite(sek) || sek < 0) {
            sek = 0;
        }
        if (sek > 59) {
            sek = 59;
        }
        var ms = (min * 60 + sek) * 1000;
        if (ms < 6000) {
            ms = 6000;
        }
        return ms;
    }

    function ajastinKertaaAsetus() {
        var n = kertaaEl ? parseInt(kertaaEl.value, 10) : 6;
        if (!isFinite(n) || n < 0) {
            n = 0;
        }
        return n;
    }

    function kayttoValiMs() {
        return (ajastin.kaynnissa || ajastin.paussi) ? ajastin.valiMs : ajastinValiMsAsetus();
    }

    function kayttoKertaa() {
        return (ajastin.kaynnissa || ajastin.paussi) ? ajastin.kertaa : ajastinKertaaAsetus();
    }

    function ajastinElapsed() {
        if (!ajastin.kaynnissa && !ajastin.paussi) {
            return 0;
        }
        if (ajastin.paussi || !ajastin.kaynnissa) {
            return ajastin.kulunutPaussi;
        }
        return Date.now() - ajastin.alkuMs + ajastin.kulunutPaussi;
    }

    function ajastinStartit() {
        var n = kayttoKertaa();
        if (n <= 0) {
            n = 9999;
        }
        var vali = kayttoValiMs();
        var out = [];
        for (var i = 0; i < n; i++) {
            out.push(LEAD_MS + i * vali);
        }
        return out;
    }

    function ajastinKuvio() {
        return [
            { s: 5, pitka: false },
            { s: 4, pitka: false },
            { s: 3, pitka: false },
            { s: 2, pitka: false },
            { s: 1, pitka: false },
            { s: 0, pitka: true }
        ];
    }

    function kestoTeksti(ms) {
        ms = Math.max(0, Math.floor(ms / 1000));
        var h = Math.floor(ms / 3600);
        var m = Math.floor((ms % 3600) / 60);
        var s = ms % 60;
        function p(n) { return (n < 10 ? '0' : '') + n; }
        if (h > 0) {
            return h + ':' + p(m) + ':' + p(s);
        }
        return m + ':' + p(s);
    }

    function luettuAjastinNaytto() {
        var val = document.querySelector('input[name="kello-ajastin-naytto"]:checked');
        if (val && (val.value === 'ylos' || val.value === 'alas' || val.value === 'molemmat')) {
            return val.value;
        }
        return ajastinNayttoTila || 'molemmat';
    }

    function paivitaTilaValinnat() {
        var lahdotRadio = document.getElementById('kello-tila-lahdot');
        var ajastinRadio = document.getElementById('kello-tila-ajastin');
        if (lahdotRadio) {
            lahdotRadio.checked = tila !== 'ajastin';
        }
        if (ajastinRadio) {
            ajastinRadio.checked = tila === 'ajastin';
        }
        var nayttoRadiot = document.querySelectorAll('input[name="kello-ajastin-naytto"]');
        for (var i = 0; i < nayttoRadiot.length; i++) {
            nayttoRadiot[i].checked = nayttoRadiot[i].value === ajastinNayttoTila;
        }
        paivitaTila();
    }

    function paivitaTila() {
        var aj = onAjastin();
        document.body.classList.toggle('kello-tila-ajastin', aj);
        if (lahdotNaytto) {
            lahdotNaytto.hidden = aj;
        }
        if (ajastinNaytto) {
            ajastinNaytto.hidden = !aj;
        }
        function piilota(id, piiloon) {
            var el = document.getElementById(id);
            if (el) {
                el.hidden = piiloon;
            }
        }
        piilota('kello-ajastin-asetus', !aj);
        piilota('kello-paikka-osio', aj);
        piilota('kello-naytto-asetus', aj);
        piilota('kello-piippu-kuviot', aj);
        piilota('kello-ajastin-piippu-ohje', !aj);
        piilota('kello-sarja-osio', aj);
        piilota('kello-synkka-osio', aj);
        var synkkaEl = document.getElementById('kello-synkka');
        if (synkkaEl) {
            synkkaEl.hidden = aj;
        }
        paivitaAjastinNapit();
        paivitaAjastinKelloLuokat();
    }

    function paivitaAjastinKelloLuokat() {
        ajastinNayttoTila = luettuAjastinNaytto();
        if (ajastinKellot) {
            ajastinKellot.classList.toggle('is-molemmat', ajastinNayttoTila === 'molemmat');
        }
        if (ajastinYlosWrap) {
            ajastinYlosWrap.hidden = ajastinNayttoTila === 'alas';
        }
        if (ajastinAlasWrap) {
            ajastinAlasWrap.hidden = ajastinNayttoTila === 'ylos';
        }
    }

    function paivitaAjastinNapit() {
        var kay = ajastin.kaynnissa && !ajastin.paussi;
        if (kaynnistaBtn) {
            kaynnistaBtn.hidden = kay;
            kaynnistaBtn.textContent = ajastin.paussi ? 'Jatka' : 'Käynnistä';
        }
        if (pysaytaBtn) {
            pysaytaBtn.hidden = !kay;
        }
        var lukitse = kay || ajastin.paussi;
        [valiMinEl, valiSekEl, kertaaEl].forEach(function (el) {
            if (el) {
                el.disabled = lukitse;
            }
        });
    }

    function asetaAaniPaalle() {
        aani = true;
        if (aaniBtn) {
            aaniBtn.setAttribute('aria-pressed', 'true');
            aaniBtn.setAttribute('aria-label', 'Äänet päällä');
            aaniBtn.setAttribute('title', 'Äänet päällä');
            aaniBtn.classList.add('is-paalla');
            aaniBtn.textContent = '🔊';
        }
        varmistaAani();
    }

    function kaynnistaAjastin() {
        asetaAaniPaalle();
        if (ajastin.paussi) {
            ajastin.alkuMs = Date.now();
            ajastin.kaynnissa = true;
            ajastin.paussi = false;
        } else {
            ajastin.valiMs = ajastinValiMsAsetus();
            ajastin.kertaa = ajastinKertaaAsetus();
            ajastin.alkuMs = Date.now();
            ajastin.kulunutPaussi = 0;
            ajastin.kaynnissa = true;
            ajastin.paussi = false;
            ajastettu = {};
        }
        paivitaAjastinNapit();
        suljeAsetukset();
        piirra();
    }

    function pysaytaAjastin() {
        if (!ajastin.kaynnissa || ajastin.paussi) {
            return;
        }
        ajastin.kulunutPaussi = ajastinElapsed();
        ajastin.kaynnissa = false;
        ajastin.paussi = true;
        paivitaAjastinNapit();
        piirra();
    }

    function nollaaAjastin() {
        ajastin.kaynnissa = false;
        ajastin.paussi = false;
        ajastin.alkuMs = 0;
        ajastin.kulunutPaussi = 0;
        ajastettu = {};
        paivitaAjastinNapit();
        if (laskuri) {
            laskuri.hidden = true;
            laskuri.textContent = '';
        }
        piirra();
    }

    function lahdot(ms) {
        var ajat = [];
        var naky = {};
        for (var i = 0; i < rivit.length; i++) {
            if (sarja && rivit[i].sarja !== sarja) {
                continue;
            }
            if (!samaPaikka(rivit[i])) {
                continue;
            }
            var sec = rivit[i].lahto;
            if (!naky[sec] && sec * 1000 > ms - 2000) {
                naky[sec] = true;
                ajat.push(sec);
            }
        }
        if (minuuttiEl && minuuttiEl.checked) {
            var min = Math.floor(ms / 60000) * 60;
            for (var k = 0; k < 3; k++) {
                var m = min + k * 60;
                if (!naky[m] && m * 1000 > ms - 2000) {
                    naky[m] = true;
                    ajat.push(m);
                }
            }
        }
        ajat.sort(function (a, b) { return a - b; });
        return ajat;
    }

    function seuraavaLahtija(ms) {
        var paras = null;
        for (var i = 0; i < rivit.length; i++) {
            if (sarja && rivit[i].sarja !== sarja) {
                continue;
            }
            if (!samaPaikka(rivit[i])) {
                continue;
            }
            if (rivit[i].lahto * 1000 < ms - 1500) {
                continue;
            }
            if (!paras || rivit[i].lahto < paras.lahto) {
                paras = rivit[i];
            }
        }
        return paras;
    }

    function aikatauluta() {
        if (!aani || !ctx) {
            return;
        }
        if (onAjastin()) {
            aikataulutaAjastin();
            return;
        }
        var ms = nytMs();
        var audioNyt = ctx.currentTime;
        var kuvi = kuvioNyt();
        var starts = lahdot(ms);
        for (var i = 0; i < starts.length; i++) {
            for (var j = 0; j < kuvi.length; j++) {
                var hetki = starts[i] * 1000 - kuvi[j].s * 1000;
                var viive = (hetki - ms) / 1000;
                if (viive < -0.08 || viive > 1.4) {
                    continue;
                }
                var avain = starts[i] + ':' + kuvi[j].s;
                if (ajastettu[avain]) {
                    continue;
                }
                ajastettu[avain] = true;
                var hetkiAani = audioNyt + Math.max(0, viive);
                if (kuvi[j].s === 10) {
                    soitaKaksi(hetkiAani);
                } else {
                    soita(kuvi[j].pitka, hetkiAani);
                }
                setTimeout(vilkahda, Math.max(0, viive * 1000));
            }
        }
    }

    function aikataulutaAjastin() {
        if (!ajastin.kaynnissa || ajastin.paussi) {
            return;
        }
        var elapsed = ajastinElapsed();
        var audioNyt = ctx.currentTime;
        var kuvi = ajastinKuvio();
        var starts = ajastinStartit();
        for (var i = 0; i < starts.length; i++) {
            for (var j = 0; j < kuvi.length; j++) {
                var hetki = starts[i] - kuvi[j].s * 1000;
                var viive = (hetki - elapsed) / 1000;
                if (viive < -0.08 || viive > 1.4) {
                    continue;
                }
                var avain = 'a:' + i + ':' + kuvi[j].s;
                if (ajastettu[avain]) {
                    continue;
                }
                ajastettu[avain] = true;
                soita(kuvi[j].pitka, audioNyt + Math.max(0, viive));
                setTimeout(vilkahda, Math.max(0, viive * 1000));
            }
        }
    }

    function piirraAjastin() {
        var elapsed = ajastinElapsed();
        var starts = ajastinStartit();
        var last = 0;
        var next = null;
        var tehty = 0;
        var i;
        for (i = 0; i < starts.length; i++) {
            if (elapsed >= starts[i]) {
                last = starts[i];
                tehty = i + 1;
            } else if (next === null) {
                next = starts[i];
                break;
            }
        }
        var kulunut = 0;
        var seuraavaan = kayttoValiMs();
        if (ajastin.kaynnissa || ajastin.paussi) {
            kulunut = tehty > 0 ? Math.max(0, elapsed - last) : 0;
            seuraavaan = next !== null ? Math.max(0, next - elapsed) : 0;
        }
        if (ajastinYlos) {
            ajastinYlos.textContent = kestoTeksti(kulunut);
        }
        if (ajastinAlas) {
            ajastinAlas.textContent = kestoTeksti(seuraavaan);
        }
        if (ajastinTilaEl) {
            var n = kayttoKertaa();
            var jatkuva = n <= 0;
            var seuraavaNro = next !== null ? tehty + 1 : tehty;
            if (!ajastin.kaynnissa && !ajastin.paussi) {
                ajastinTilaEl.textContent = jatkuva
                    ? 'Valmis · ' + kestoTeksti(kayttoValiMs()) + ' välein'
                    : 'Valmis · ' + kestoTeksti(kayttoValiMs()) + ' × ' + n;
            } else if (ajastin.paussi) {
                ajastinTilaEl.textContent = 'Tauko' + (jatkuva ? ' · lähtö ' + seuraavaNro : ' · lähtö ' + seuraavaNro + ' / ' + n);
            } else if (next === null) {
                ajastinTilaEl.textContent = jatkuva ? 'Valmis' : 'Valmis · ' + tehty + ' / ' + n;
            } else {
                ajastinTilaEl.textContent = jatkuva
                    ? 'Lähtö ' + seuraavaNro
                    : 'Lähtö ' + seuraavaNro + ' / ' + n;
            }
        }
        if (laskuri) {
            if (next !== null && (ajastin.kaynnissa || ajastin.paussi)) {
                var jaljellaS = Math.max(0, Math.floor(seuraavaan / 1000));
                if (jaljellaS <= 10) {
                    laskuri.hidden = false;
                    laskuri.textContent = String(jaljellaS);
                } else {
                    laskuri.hidden = true;
                    laskuri.textContent = '';
                }
            } else {
                laskuri.hidden = true;
                laskuri.textContent = '';
            }
        }
        if (kuvio) {
            kuvio.textContent = 'Lyhyet 5, 4, 3, 2, 1 s. Pitkät 0 s.';
        }
        aikatauluta();
    }

    function piirra() {
        if (onAjastin()) {
            paivitaAjastinKelloLuokat();
            piirraAjastin();
            return;
        }
        var ms = nytMs();
        naytto.textContent = kelloTeksti(ms);
        var h = seuraavaLahtija(ms);
        var naytaAika = !naytaAikaEl || naytaAikaEl.checked;
        var naytaSeur = !naytaSeuraavaEl || naytaSeuraavaEl.checked;
        if (lahtoon) {
            lahtoon.hidden = !naytaAika;
            if (naytaAika) {
                if (!h) {
                    lahtoon.textContent = rivit.length ? 'Ei tulevia lähtöjä' : 'Ei lähtöaikoja';
                } else {
                    var jaljella = Math.max(0, Math.round((h.lahto * 1000 - ms) / 1000));
                    var min = Math.floor(jaljella / 60);
                    var sek = jaljella % 60;
                    lahtoon.textContent = 'Seuraavaan lähtijään ' + min + ':' + (sek < 10 ? '0' : '') + sek;
                }
            }
        }
        if (seuraava) {
            seuraava.hidden = !naytaSeur;
            if (!h) {
                seuraava.textContent = '';
            } else {
                var nro = h.numero === '' || h.numero === null ? '' : String(h.numero) + ' ';
                seuraava.textContent = nro + (h.nimi || '') + (h.sarja ? ' · ' + h.sarja : '') + ' · ' + h.kello;
            }
        }
        if (laskuri) {
            var minuuttiin = (60 - Math.floor(ms / 1000) % 60) % 60;
            if (minuuttiin <= 10) {
                laskuri.hidden = false;
                laskuri.textContent = String(minuuttiin);
            } else {
                laskuri.hidden = true;
                laskuri.textContent = '';
            }
        }
        if (kuvio) {
            var k = kuvioNyt();
            var lyh = [];
            var pit = [];
            for (var i = 0; i < k.length; i++) {
                (k[i].pitka ? pit : lyh).push(String(k[i].s));
            }
            kuvio.textContent = 'Lyhyet ' + (lyh.join(', ') || '–') + ' s. Pitkät ' + (pit.join(', ') || '–') + ' s.';
        }
        aikatauluta();
    }

    function synkkaTeksti(data) {
        if (!synkka) {
            return;
        }
        var eroS = (data.ero_ms || 0) / 1000;
        var eroTxt = (eroS >= 0 ? '+' : '') + eroS.toFixed(1).replace('.', ',') + ' s';
        if (data.lahde === 'pirila') {
            synkka.textContent = 'Synkassa Pirilään (' + (data.ika_s === null ? 'juuri' : data.ika_s + ' s sitten') + '). Ero palvelimeen ' + eroTxt + '.';
        } else if (data.lahde === 'kasin') {
            synkka.textContent = 'Asetettu käsin Pirilän kellon mukaan. Ero palvelimeen ' + eroTxt + '.';
        } else {
            synkka.textContent = 'Kello seuraa palvelinta. Pirilä ei ole vielä lähettänyt aikaa.';
        }
    }

    function kaytaTila(data) {
        if (!data || !data.ok) {
            return;
        }
        if (typeof data.nyt_ms === 'number') {
            skew = data.nyt_ms - Date.now();
        }
        if (typeof data.ero_ms === 'number') {
            ero = data.ero_ms;
        }
        if (Array.isArray(data.lahtopaikat)) {
            paikat = data.lahtopaikat;
        }
        if (data.rivit) {
            rivit = data.rivit;
            taytaSarjat();
            taytaPaikat();
        }
        synkkaTeksti(data);
    }

    function taytaSarjat() {
        if (!sarjaEl) {
            return;
        }
        var nimet = [];
        var naky = {};
        for (var i = 0; i < rivit.length; i++) {
            if (rivit[i].sarja && !naky[rivit[i].sarja]) {
                naky[rivit[i].sarja] = true;
                nimet.push(rivit[i].sarja);
            }
        }
        var val = sarjaEl.value || sarja;
        while (sarjaEl.options.length > 1) {
            sarjaEl.remove(1);
        }
        for (var j = 0; j < nimet.length; j++) {
            var opt = document.createElement('option');
            opt.value = nimet[j];
            opt.textContent = nimet[j];
            sarjaEl.appendChild(opt);
        }
        var loytyi = false;
        for (var k = 0; k < sarjaEl.options.length; k++) {
            if (sarjaEl.options[k].value === val) {
                loytyi = true;
            }
        }
        sarjaEl.value = loytyi ? val : '';
        sarja = sarjaEl.value;
    }

    function taytaPaikat() {
        if (!paikkaEl) {
            return;
        }
        var lista = [];
        var naky = {};
        var lahde = paikat.length ? paikat : rivit.map(function (r) { return r.lahtopaikka; });
        for (var i = 0; i < lahde.length; i++) {
            var n = parseInt(lahde[i], 10);
            if (n > 0 && !naky[n]) {
                naky[n] = true;
                lista.push(n);
            }
        }
        lista.sort(function (a, b) { return a - b; });
        var val = paikkaEl.value || paikka;
        while (paikkaEl.options.length > 1) {
            paikkaEl.remove(1);
        }
        for (var j = 0; j < lista.length; j++) {
            var opt = document.createElement('option');
            opt.value = String(lista[j]);
            opt.textContent = 'Lähtö ' + lista[j];
            paikkaEl.appendChild(opt);
        }
        var loytyi = val === '';
        for (var k = 0; k < paikkaEl.options.length; k++) {
            if (paikkaEl.options[k].value === val) {
                loytyi = true;
            }
        }
        paikkaEl.value = loytyi ? val : '';
        paikka = paikkaEl.value;
    }

    function hae() {
        return fetch(cfg.url, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(kaytaTila)
            .catch(function () {
                if (virhe) {
                    virhe.textContent = 'Kellon tilaa ei saatu.';
                }
            });
    }

    function postita(body) {
        if (virhe) {
            virhe.textContent = '';
        }
        return fetch(cfg.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data || !data.ok) {
                if (virhe) {
                    virhe.textContent = (data && data.message) || 'Ajan asetus ei onnistunut.';
                }
                return;
            }
            kaytaTila(data);
        }).catch(function () {
            if (virhe) {
                virhe.textContent = 'Yhteys epäonnistui.';
            }
        });
    }

    lataaAsetus();
    var asetusIkkuna = document.getElementById('kello-asetusikkuna');
    var asetusBtn = document.getElementById('kello-asetukset');
    function avaaAsetukset() {
        if (!asetusIkkuna) {
            return;
        }
        if (typeof asetusIkkuna.showModal === 'function') {
            if (!asetusIkkuna.open) {
                asetusIkkuna.showModal();
            }
        } else {
            asetusIkkuna.setAttribute('open', '');
        }
    }
    function suljeAsetukset() {
        if (!asetusIkkuna) {
            return;
        }
        if (typeof asetusIkkuna.close === 'function' && asetusIkkuna.open) {
            asetusIkkuna.close();
        } else {
            asetusIkkuna.removeAttribute('open');
        }
    }
    if (asetusBtn) {
        asetusBtn.addEventListener('click', avaaAsetukset);
    }
    var asetusSulje = document.getElementById('kello-asetukset-sulje');
    if (asetusSulje) {
        asetusSulje.addEventListener('click', suljeAsetukset);
    }
    var poisDlg = document.getElementById('kello-poistu-varmistus');
    var poisPeru = document.getElementById('kello-poistu-peru');
    var poisOk = document.getElementById('kello-poistu-ok');
    var poisHref = cfg.hallintaUrl || '../admin/index.php';
    function piilotaLatausLogo() {
        try {
            sessionStorage.removeItem('jah-page-loading');
        } catch (e) {}
        document.documentElement.classList.remove('jah-loading');
    }
    function suljePoistuVarmistus() {
        piilotaLatausLogo();
        if (poisDlg) {
            poisDlg.hidden = true;
        }
    }
    function avaaPoistuVarmistus(ev) {
        if (ev) {
            ev.preventDefault();
            ev.stopPropagation();
        }
        piilotaLatausLogo();
        var src = ev && ev.currentTarget;
        if (src && src.getAttribute) {
            poisHref = src.getAttribute('data-href') || poisHref;
        }
        if (poisDlg) {
            poisDlg.hidden = false;
        }
    }
    function onJoKokoNaytto() {
        return !!(document.fullscreenElement
            || document.webkitFullscreenElement
            || window.matchMedia('(display-mode: fullscreen)').matches
            || window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true);
    }

    function onKosketusLaite() {
        return window.matchMedia('(pointer: coarse)').matches
            || window.matchMedia('(hover: none)').matches
            || (('ontouchstart' in window) && window.innerWidth < 900);
    }

    function pyydaSelainKokoNaytto() {
        if (onJoKokoNaytto()) {
            return;
        }
        if (!onKosketusLaite()) {
            return;
        }
        var el = document.documentElement;
        try {
            if (el.requestFullscreen) {
                el.requestFullscreen({ navigationUI: 'hide' }).catch(function () {});
            } else if (el.webkitRequestFullscreen) {
                el.webkitRequestFullscreen();
            }
        } catch (e) { /* ignore */ }
    }

    function sidoPoistuminen(el) {
        if (!el) {
            return;
        }
        el.addEventListener('click', avaaPoistuVarmistus);
    }
    document.querySelectorAll('.lukija-poistu').forEach(sidoPoistuminen);
    if (poisPeru) {
        poisPeru.addEventListener('click', function (ev) {
            ev.preventDefault();
            suljePoistuVarmistus();
        });
    }
    if (poisOk) {
        poisOk.addEventListener('click', function (ev) {
            ev.preventDefault();
            var siirry = function () {
                window.location.href = poisHref;
            };
            try {
                if (document.fullscreenElement && document.exitFullscreen) {
                    document.exitFullscreen().then(siirry).catch(siirry);
                    return;
                }
                if (document.webkitFullscreenElement && document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            } catch (e) { /* ignore */ }
            siirry();
        });
    }
    document.addEventListener('pointerdown', function (ev) {
        if (ev.target && ev.target.closest('#kello-poistu-varmistus, .lukija-poistu, a')) {
            return;
        }
        pyydaSelainKokoNaytto();
    }, { capture: true, passive: true });
    pyydaSelainKokoNaytto();
    if (aaniBtn) {
        aaniBtn.addEventListener('click', function () {
            aani = !aani;
            aaniBtn.setAttribute('aria-pressed', aani ? 'true' : 'false');
            aaniBtn.setAttribute('aria-label', aani ? 'Äänet päällä' : 'Äänet päälle');
            aaniBtn.setAttribute('title', aani ? 'Äänet päällä' : 'Äänet päälle');
            aaniBtn.classList.toggle('is-paalla', aani);
            aaniBtn.textContent = aani ? '🔊' : '🔇';
            if (aani) {
                varmistaAani();
                soita(false);
            }
        });
    }
    var kokeile = document.getElementById('kello-kokeile');
    if (kokeile) {
        kokeile.addEventListener('click', function () {
            var audio = varmistaAani();
            if (audio) {
                var t = audio.currentTime;
                soitaKaksi(t);
                soita(true, t + 0.55);
            }
            vilkahda();
        });
    }
    document.querySelectorAll('input[name="kello-tila"]').forEach(function (el) {
        el.addEventListener('change', function () {
            tila = el.value === 'ajastin' ? 'ajastin' : 'lahdot';
            ajastettu = {};
            talletaAsetus();
            paivitaTila();
            piirra();
        });
    });
    document.querySelectorAll('input[name="kello-ajastin-naytto"]').forEach(function (el) {
        el.addEventListener('change', function () {
            ajastinNayttoTila = luettuAjastinNaytto();
            talletaAsetus();
            paivitaAjastinKelloLuokat();
            piirra();
        });
    });
    [valiMinEl, valiSekEl, kertaaEl].forEach(function (el) {
        if (!el) {
            return;
        }
        el.addEventListener('change', talletaAsetus);
        el.addEventListener('input', function () {
            talletaAsetus();
            piirra();
        });
    });
    if (kaynnistaBtn) {
        kaynnistaBtn.addEventListener('click', kaynnistaAjastin);
    }
    if (pysaytaBtn) {
        pysaytaBtn.addEventListener('click', pysaytaAjastin);
    }
    if (ajastinNollaaBtn) {
        ajastinNollaaBtn.addEventListener('click', nollaaAjastin);
    }
    [lyhytEl, pitkaEl, minuuttiEl, voimaEl, naytaSeuraavaEl, naytaAikaEl].forEach(function (el) {
        if (!el) {
            return;
        }
        el.addEventListener('change', talletaAsetus);
        el.addEventListener('input', function () {
            talletaAsetus();
            piirra();
        });
    });
    document.querySelectorAll('[data-esiasetus]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var e = esiasetukset[btn.getAttribute('data-esiasetus')];
            if (!e) {
                return;
            }
            if (lyhytEl) {
                lyhytEl.value = e.lyhyt;
            }
            if (pitkaEl) {
                pitkaEl.value = e.pitka;
            }
            talletaAsetus();
            piirra();
        });
    });
    if (sarjaEl) {
        sarjaEl.addEventListener('change', function () {
            sarja = sarjaEl.value;
            localStorage.setItem(AVAIN + '-sarja-' + cfg.kilpailuId, sarja);
            ajastettu = {};
            piirra();
        });
    }
    if (paikkaEl) {
        paikkaEl.addEventListener('change', function () {
            paikka = paikkaEl.value;
            localStorage.setItem(AVAIN + '-paikka-' + cfg.kilpailuId, paikka);
            ajastettu = {};
            piirra();
        });
    }
    var miinus = document.getElementById('kello-miinus');
    var plus = document.getElementById('kello-plus');
    var nollaa = document.getElementById('kello-nollaa');
    if (miinus) {
        miinus.addEventListener('click', function () { postita({ siirra_ms: -1000 }); });
    }
    if (plus) {
        plus.addEventListener('click', function () { postita({ siirra_ms: 1000 }); });
    }
    if (nollaa) {
        nollaa.addEventListener('click', function () { postita({ nollaa: 1 }); });
    }
    var lomake = document.getElementById('kello-aseta');
    if (lomake) {
        lomake.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var arvo = syotto ? syotto.value.trim() : '';
            if (!/^\d{1,2}:\d{2}(:\d{2})?$/.test(arvo)) {
                if (virhe) {
                    virhe.textContent = 'Kirjoita aika muodossa 14:32:05.';
                }
                return;
            }
            postita({ kello: arvo });
        });
    }

    hae();
    setInterval(piirra, 100);
    setInterval(hae, 2000);
    setInterval(function () {
        if (onAjastin()) {
            var e = ajastinElapsed();
            Object.keys(ajastettu).forEach(function (avain) {
                if (avain.charAt(0) !== 'a') {
                    delete ajastettu[avain];
                    return;
                }
                var osat = avain.split(':');
                var idx = parseInt(osat[1], 10);
                var s = parseInt(osat[2], 10);
                if (!isFinite(idx) || !isFinite(s)) {
                    delete ajastettu[avain];
                    return;
                }
                var start = LEAD_MS + idx * kayttoValiMs();
                if (start - s * 1000 < e - 5000) {
                    delete ajastettu[avain];
                }
            });
            return;
        }
        var raja = nytMs() - 5000;
        Object.keys(ajastettu).forEach(function (avain) {
            var sec = parseInt(avain, 10);
            if (sec * 1000 < raja) {
                delete ajastettu[avain];
            }
        });
    }, 10000);
})();
