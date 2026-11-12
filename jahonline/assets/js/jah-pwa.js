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

    function paivitaYhteys() {
        var el = document.getElementById('iltarasti-yhteys');
        if (!el) {
            return;
        }
        var online = navigator.onLine !== false;
        el.textContent = online
            ? (el.getAttribute('data-online') || 'Yhteydessä')
            : (el.getAttribute('data-offline') || 'Paikallisesti toimiva');
        el.classList.toggle('is-online', online);
        el.classList.toggle('is-offline', !online);
    }
    paivitaYhteys();
    window.addEventListener('online', paivitaYhteys);
    window.addEventListener('offline', paivitaYhteys);

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
    var SW = '/sw-jahonline.js?v=20261112asema';
    var CACHE = 'jahonline-app-v19';

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
