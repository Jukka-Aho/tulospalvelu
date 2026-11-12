/**
 * JAHOnline PWA: /public/ (ajanotto, emit, iltarastien kioski) ja /admin/.
 * Ei rekisteröidä scopea /, jotta AhoAuto pysyy erillisenä.
 * Iltarastisivut viedään välimuistiin, kun ne avataan verkossa.
 */
(function () {
    if (navigator.storage && navigator.storage.persist) {
        navigator.storage.persist().catch(function () {});
    }

    function kioskiPaalle() {
        var el = document.documentElement;
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            return;
        }
        var fn = el.requestFullscreen || el.webkitRequestFullscreen;
        if (!fn) {
            return;
        }
        try {
            var p = fn.call(el, { navigationUI: 'hide' });
            if (p && p.catch) {
                p.catch(function () {});
            }
        } catch (e) { /* */ }
    }

    function kioskiPois() {
        var fn = document.exitFullscreen || document.webkitExitFullscreen;
        if (!fn) {
            return;
        }
        try {
            var p = fn.call(document);
            if (p && p.catch) {
                p.catch(function () {});
            }
        } catch (e) { /* */ }
    }

    function onIltarastiKuori() {
        return !!(document.body && document.body.classList.contains('iltarasti-kuori'));
    }

    function varmistaYhteysSelite(el) {
        if (!el) {
            return;
        }
        var parent = el.parentNode;
        var rivi = parent && parent.classList && parent.classList.contains('iltarasti-yhteys-rivi')
            ? parent
            : null;
        if (!rivi) {
            rivi = document.createElement('span');
            rivi.className = 'iltarasti-yhteys-rivi';
            if (parent) {
                parent.insertBefore(rivi, el);
            }
            rivi.appendChild(el);
        }
        if (!rivi.querySelector('.iltarasti-yhteys-selite')) {
            var selite = document.createElement('span');
            selite.className = 'iltarasti-yhteys-selite';
            selite.setAttribute('aria-hidden', 'true');
            selite.textContent = 'Palvelin';
            rivi.insertBefore(selite, el);
        }
    }

    function varmistaYhteysPallo() {
        if (!onIltarastiKuori()) {
            return document.getElementById('iltarasti-yhteys');
        }
        var el = document.getElementById('iltarasti-yhteys');
        if (el) {
            varmistaYhteysSelite(el);
            return el;
        }
        var toimet = document.querySelector('.site-topbar-actions');
        if (!toimet) {
            var palkki = document.querySelector('.site-topbar');
            if (!palkki) {
                return null;
            }
            toimet = document.createElement('div');
            toimet.className = 'site-topbar-actions';
            palkki.appendChild(toimet);
        }
        el = document.createElement('span');
        el.id = 'iltarasti-yhteys';
        el.className = 'iltarasti-yhteys is-online';
        el.setAttribute('role', 'img');
        el.setAttribute('data-online', 'Palvelin ok');
        el.setAttribute('data-offline', 'Ei verkkoa');
        var rivi = document.createElement('span');
        rivi.className = 'iltarasti-yhteys-rivi';
        var selite = document.createElement('span');
        selite.className = 'iltarasti-yhteys-selite';
        selite.setAttribute('aria-hidden', 'true');
        selite.textContent = 'Palvelin';
        rivi.appendChild(selite);
        rivi.appendChild(el);
        var tila = document.getElementById('iltarasti-tila');
        if (tila) {
            tila.insertBefore(rivi, tila.firstChild);
        } else {
            toimet.insertBefore(rivi, toimet.firstChild);
        }
        return el;
    }

    function merkitseYhteys(online) {
        var el = varmistaYhteysPallo();
        if (!el) {
            return;
        }
        var okTxt = el.getAttribute('data-online') || 'Palvelin ok';
        var eiTxt = el.getAttribute('data-offline') || 'Ei verkkoa';
        var teksti = online ? okTxt : eiTxt;
        el.textContent = '';
        el.setAttribute('title', teksti);
        el.setAttribute('aria-label', teksti);
        el.classList.toggle('is-online', online);
        el.classList.toggle('is-offline', !online);
    }

    function kuoriKilpailuId() {
        var n = 0;
        try {
            n = parseInt((document.body && document.body.getAttribute('data-jah-kilpailu')) || '0', 10) || 0;
        } catch (e) {}
        return n;
    }

    function varmistaTilaRivi() {
        var tila = document.getElementById('iltarasti-tila');
        if (tila) {
            return tila;
        }
        var yht = document.getElementById('iltarasti-yhteys');
        var toimet = document.querySelector('.site-topbar-actions');
        if (!toimet) {
            return null;
        }
        tila = document.createElement('span');
        tila.id = 'iltarasti-tila';
        tila.className = 'iltarasti-tila-rivi';
        var yhtRivi = yht && yht.parentNode && yht.parentNode.classList
            && yht.parentNode.classList.contains('iltarasti-yhteys-rivi')
            ? yht.parentNode
            : yht;
        if (yhtRivi && yhtRivi.parentNode === toimet) {
            toimet.insertBefore(tila, yhtRivi);
            tila.appendChild(yhtRivi);
        } else {
            toimet.insertBefore(tila, toimet.firstChild);
            if (yhtRivi) {
                tila.appendChild(yhtRivi);
            }
        }
        return tila;
    }

    function varmistaTilaLuku(id, luokka, otsikko, href) {
        var el = document.getElementById(id);
        if (el) {
            return el;
        }
        var tila = varmistaTilaRivi();
        if (!tila) {
            return null;
        }
        el = document.createElement(href ? 'a' : 'span');
        el.id = id;
        el.className = 'iltarasti-tila-luku' + (luokka ? ' ' + luokka : '');
        if (href) {
            el.href = href;
        }
        el.appendChild(document.createTextNode(otsikko + ' '));
        var nro = document.createElement('span');
        nro.className = 'iltarasti-tila-nro';
        nro.textContent = '–';
        el.appendChild(nro);
        tila.appendChild(el);
        return el;
    }

    function asetaTilaNro(id, n) {
        var el = document.getElementById(id);
        if (!el || n == null) {
            return;
        }
        var nro = el.querySelector('.iltarasti-tila-nro');
        if (nro) {
            nro.textContent = String(n);
        }
        el.classList.toggle('is-tyhja', !n);
        el.hidden = false;
    }

    var tilaHaku = 0;
    function paivitaTilaLuvut() {
        if (!onIltarastiKuori()) {
            return;
        }
        var kid = kuoriKilpailuId();
        if (!kid || navigator.onLine === false) {
            return;
        }
        var nro = ++tilaHaku;
        fetch('/api/iltarasti_tila.php?kilpailu_id=' + kid + '&t=' + Date.now(), {
            cache: 'no-store',
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        }).then(function (d) {
            if (nro !== tilaHaku || !d || !d.ok) {
                return;
            }
            varmistaTilaLuku(
                'iltarasti-maastossa',
                '',
                'Maastossa',
                d.esitykset != null ? '/public/kilpailijat.php?kilpailu_id=' + kid : ''
            );
            asetaTilaNro('iltarasti-maastossa', d.maastossa);
            if (d.esitykset != null) {
                varmistaTilaLuku(
                    'iltarasti-esitykset',
                    'is-esitys',
                    'Esitys',
                    '/public/iltarasti_esitykset.php?kilpailu_id=' + kid
                );
                asetaTilaNro('iltarasti-esitykset', d.esitykset);
            } else {
                var es = document.getElementById('iltarasti-esitykset');
                if (es) {
                    es.hidden = true;
                }
            }
        }).catch(function () {});
    }

    var yhteysHaku = 0;
    function kokeilePalvelin() {
        if (!onIltarastiKuori()) {
            return;
        }
        if (navigator.onLine === false) {
            merkitseYhteys(false);
            return;
        }
        var nro = ++yhteysHaku;
        var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
        var t = setTimeout(function () {
            if (ctrl) {
                ctrl.abort();
            }
        }, 4000);
        fetch('/api/yhteys.php?t=' + Date.now(), {
            cache: 'no-store',
            credentials: 'omit',
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (res) {
            clearTimeout(t);
            if (nro !== yhteysHaku) {
                return;
            }
            merkitseYhteys(!!(res && res.status !== 503));
        }).catch(function () {
            clearTimeout(t);
            if (nro !== yhteysHaku) {
                return;
            }
            merkitseYhteys(false);
        });
    }

    function paivitaYhteys() {
        if (!onIltarastiKuori()) {
            return;
        }
        if (navigator.onLine === false) {
            merkitseYhteys(false);
            return;
        }
        merkitseYhteys(true);
        kokeilePalvelin();
        paivitaTilaLuvut();
    }
    paivitaYhteys();
    window.addEventListener('online', paivitaYhteys);
    window.addEventListener('offline', paivitaYhteys);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'hidden') {
            paivitaYhteys();
        }
    });
    setInterval(function () {
        if (!onIltarastiKuori() || document.visibilityState === 'hidden') {
            return;
        }
        kokeilePalvelin();
        paivitaTilaLuvut();
    }, 20000);

    function iltarastiOfflineSallittu(href) {
        try {
            var u = new URL(href, window.location.href);
            if (u.origin !== window.location.origin) {
                return false;
            }
            var p = u.pathname;
            return (
                p.indexOf('/public/iltarastit.php') === 0
                || p.indexOf('/public/kilpailijat.php') === 0
                || p.indexOf('/public/iltarasti_') === 0
                || p.indexOf('/public/radat.php') === 0
                || p.indexOf('/public/emit_lukija.php') === 0
                || p.indexOf('/public/automaatti.php') === 0
                || p.indexOf('/admin/iltarastit.php') === 0
                || p.indexOf('/admin/kilpailijat.php') === 0
                || p.indexOf('/admin/iltarasti_') === 0
                || p.indexOf('/admin/radat.php') === 0
            );
        } catch (e) {
            return true;
        }
    }

    if (document.body && document.body.classList.contains('iltarasti-kuori')) {
        document.addEventListener('click', function (e) {
            if (navigator.onLine !== false) {
                return;
            }
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) {
                return;
            }
            var href = a.getAttribute('href') || '';
            if (href === '' || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
                return;
            }
            if (iltarastiOfflineSallittu(href)) {
                return;
            }
            e.preventDefault();
            window.alert('Ei verkkoyhteyttä. Iltarastin tiedot pysyvät puhelimen muistissa. Pysy tämän illan sivuilla, kunnes yhteys palaa.');
        }, true);
    }

    if (document.body && document.body.classList.contains('iltarasti-kioski')) {
        kioskiPaalle();
        document.addEventListener('click', function (e) {
            var sulje = e.target && e.target.closest
                ? e.target.closest('.iltarasti-kioski-sulje')
                : null;
            if (sulje) {
                kioskiPois();
                return;
            }
            kioskiPaalle();
        }, true);
        document.addEventListener('touchend', function (e) {
            var sulje = e.target && e.target.closest
                ? e.target.closest('.iltarasti-kioski-sulje')
                : null;
            if (sulje) {
                kioskiPois();
                return;
            }
            kioskiPaalle();
        }, { capture: true, passive: true });
    }

    if (!('serviceWorker' in navigator)) {
        return;
    }

    var AUTO = /\/(ahoauto|autot|ohjaus|scenic)(\/|$)/;
    var SW = '/sw-jahonline.js?v=20261112esitys';
    var CACHE = 'jahonline-app-v23';

    function iltarastiPrefetchUrlit() {
        var urls = {};
        function lisaa(href) {
            if (!href || !iltarastiOfflineSallittu(href)) {
                return;
            }
            try {
                urls[new URL(href, window.location.href).href] = true;
            } catch (e) { /* */ }
        }
        lisaa('/public/iltarastit.php');
        var kid = 0;
        try {
            kid = parseInt((document.body && document.body.getAttribute('data-jah-kilpailu')) || '0', 10) || 0;
        } catch (e) {
            kid = 0;
        }
        if (!kid) {
            try {
                kid = parseInt(new URL(window.location.href).searchParams.get('kilpailu_id') || '0', 10) || 0;
            } catch (e) {
                kid = 0;
            }
        }
        if (kid > 0) {
            lisaa('/public/kilpailijat.php');
            lisaa('/public/iltarasti_asetukset.php');
            lisaa('/public/iltarasti_tulokset.php');
            lisaa('/public/radat.php');
            lisaa('/assets/js/jah-radat-offline.js?v=20261107radat');
            lisaa('/public/emit_lukija.php?kilpailu_id=' + kid);
            lisaa('/public/emit_lukija.php?kilpailu_id=' + kid + '&moodi=lahto');
            lisaa('/public/emit_lukija.php?kilpailu_id=' + kid + '&moodi=maali');
            lisaa('/public/automaatti.php?kilpailu_id=' + kid);
        }
        document.querySelectorAll('.admin-sidebar a[href], a.iltarasti-kioski-sulje[href]').forEach(function (a) {
            lisaa(a.getAttribute('href'));
        });
        return Object.keys(urls);
    }

    function iltarastiTallennaCacheen(url, res) {
        if (!res || !res.ok || !('caches' in window)) {
            return;
        }
        res.clone().blob().then(function (blob) {
            var headers = new Headers();
            var ct = res.headers.get('Content-Type');
            if (ct) {
                headers.set('Content-Type', ct);
            }
            var tallenne = new Response(blob, {
                status: res.status,
                statusText: res.statusText,
                headers: headers
            });
            return caches.open(CACHE).then(function (cache) {
                return cache.put(url, tallenne);
            });
        }).catch(function () {});
    }

    function iltarastiPrefetch() {
        if (navigator.onLine === false) {
            return;
        }
        if (!document.body || !document.body.classList.contains('iltarasti-kuori')) {
            return;
        }
        var urls = iltarastiPrefetchUrlit();
        if (!urls.length) {
            return;
        }
        var avain = 'jah-ilta-prefetch-' + ((document.body.getAttribute('data-jah-kilpailu') || '0'));
        try {
            var edellinen = parseInt(sessionStorage.getItem(avain) || '0', 10) || 0;
            if (edellinen && Date.now() - edellinen < 15000) {
                return;
            }
            sessionStorage.setItem(avain, String(Date.now()));
        } catch (e) { /* */ }

        function lahetaSw() {
            if (!navigator.serviceWorker) {
                return Promise.resolve();
            }
            return navigator.serviceWorker.ready.then(function (reg) {
                var kohde = (reg && reg.active) || navigator.serviceWorker.controller;
                if (kohde) {
                    kohde.postMessage({ type: 'ILTA_PREFETCH', urls: urls });
                }
            }).catch(function () {});
        }

        lahetaSw();
        urls.forEach(function (u) {
            fetch(u, { credentials: 'same-origin', cache: 'reload', redirect: 'follow' })
                .then(function (res) { iltarastiTallennaCacheen(u, res); })
                .catch(function () {});
        });
    }

    navigator.serviceWorker
        .getRegistrations()
        .then(function (regs) {
            regs.forEach(function (reg) {
                var url =
                    (reg.active && reg.active.scriptURL) ||
                    (reg.waiting && reg.waiting.scriptURL) ||
                    (reg.installing && reg.installing.scriptURL) ||
                    '';
                if (AUTO.test(url) || AUTO.test(reg.scope || '')) return;
                if (!/\/sw-jahonline\.js/.test(url)) return;
                try {
                    if (new URL(reg.scope).pathname === '/') {
                        reg.unregister();
                    }
                } catch (e) {}
            });
        })
        .catch(function () {});

    navigator.serviceWorker.register(SW, { scope: '/public/' }).catch(function () {});
    navigator.serviceWorker.register(SW, { scope: '/admin/' }).catch(function () {});

    iltarastiPrefetch();
    window.addEventListener('online', iltarastiPrefetch);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            iltarastiPrefetch();
        }
    });
})();
