(function () {
    var cfg = window.JAH_ESITYS || {};
    var lista = document.getElementById('esitys-lista');
    if (!lista) {
        return;
    }

    function paivitaTyhja() {
        var jaa = lista.querySelectorAll('.esitys-kortti');
        var tyhja = document.getElementById('esitys-tyhja');
        if (jaa.length === 0) {
            lista.hidden = true;
            if (tyhja) {
                tyhja.hidden = false;
            }
        }
    }

    function asetaNro(id, n) {
        var el = document.getElementById(id);
        if (!el) {
            return;
        }
        var nro = el.querySelector('.iltarasti-tila-nro');
        if (nro) {
            nro.textContent = String(n);
        }
        el.classList.toggle('is-tyhja', !n);
    }

    function merkitseLuvut(luvut) {
        if (!luvut) {
            return;
        }
        if (luvut.maastossa != null) {
            asetaNro('iltarasti-maastossa', luvut.maastossa);
        }
        if (luvut.esitykset != null) {
            asetaNro('iltarasti-esitykset', luvut.esitykset);
        }
    }

    function laheta(body, kortti) {
        var napit = kortti.querySelectorAll('button');
        napit.forEach(function (b) { b.disabled = true; });
        fetch('/api/iltarasti_esitys.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function (res) {
            return res.text().then(function (teksti) {
                var data = null;
                try {
                    data = teksti ? JSON.parse(teksti) : null;
                } catch (e) {
                    data = null;
                }
                if (!data || !data.ok) {
                    throw new Error((data && data.message) || 'Tallennus epäonnistui.');
                }
                return data;
            });
        }).then(function (data) {
            merkitseLuvut(data.luvut);
            if (data.poista !== false) {
                kortti.parentNode.removeChild(kortti);
                paivitaTyhja();
            } else {
                window.location.reload();
            }
        }).catch(function (err) {
            napit.forEach(function (b) { b.disabled = false; });
            window.alert(err && err.message ? err.message : 'Ei verkkoa.');
        });
    }

    lista.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('button[type="submit"]') : null;
        if (b && b.form && b.form.classList && b.form.classList.contains('esitys-lomake')) {
            b.form.setAttribute('data-nappi', b.value || '');
        }
    });

    lista.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.classList || !form.classList.contains('esitys-lomake')) {
            return;
        }
        e.preventDefault();
        var kortti = form.closest('.esitys-kortti');
        if (!kortti) {
            return;
        }
        var nappi = e.submitter;
        var body = {
            kilpailu_id: cfg.kilpailu_id,
            kilpailija_id: parseInt(kortti.getAttribute('data-id'), 10),
            action: form.getAttribute('data-action') || 'paatos',
            csrf: cfg.csrf
        };
        if (body.action === 'paatos') {
            body.paatos = (nappi && nappi.value) || form.getAttribute('data-nappi') || 'T';
        }
        if (body.action === 'vaihda_rata') {
            var sid = form.querySelector('[name="sarja_id"]');
            body.sarja_id = sid ? parseInt(sid.value, 10) : 0;
        }
        laheta(body, kortti);
    });
})();
