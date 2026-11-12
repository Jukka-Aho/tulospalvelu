(function (root, factory) {
    var api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    root.JahSiProto = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    var STX = 0x02;
    var ETX = 0x03;
    var ACK = 0x06;
    var NAK = 0x15;
    var WAKEUP = 0xFF;
    var CRC_POLY = 0x8005;

    var C_GET_SYS_VAL = 0x83;
    var C_GET_SI5 = 0xB1;
    var C_TRANS_REC = 0xD3;
    var C_GET_SI6 = 0xE1;
    var C_SI5_DET = 0xE5;
    var C_SI6_DET = 0xE6;
    var C_SI_REM = 0xE7;
    var C_SI9_DET = 0xE8;
    var C_GET_SI9 = 0xEF;
    var C_SET_MS = 0xF0;
    var C_GET_MS = 0xF1;
    var C_BEEP = 0xF9;

    var P_MS_DIRECT = 0x4D;
    var P_SI6_CB = 0x08;
    var M_READOUT = 0x05;
    var TIME_RESET = 0xEEEE;

    var CARD = {
        SI5: { CN2: 6, CN1: 4, CN0: 5, ST: 19, FT: 21, CT: 25, RC: 23, P1: 32, PL: 3, PM: 30, PTD: null, CN: 0, PTH: 1, PTL: 2 },
        SI6: { CN2: 11, CN1: 12, CN0: 13, STD: 24, SN: 25, ST: 26, FTD: 20, FN: 21, FT: 22, CTD: 28, CHN: 29, CT: 30, RC: 18, P1: 128, PL: 4, PM: 64, PTD: 0, CN: 1, PTH: 2, PTL: 3 },
        SI8: { CN2: 25, CN1: 26, CN0: 27, STD: 12, SN: 13, ST: 14, FTD: 16, FN: 17, FT: 18, CTD: 8, CHN: 9, CT: 10, RC: 22, P1: 136, PL: 4, PM: 30, PTD: 0, CN: 1, PTH: 2, PTL: 3, BC: 2 },
        SI9: { CN2: 25, CN1: 26, CN0: 27, STD: 12, SN: 13, ST: 14, FTD: 16, FN: 17, FT: 18, CTD: 8, CHN: 9, CT: 10, RC: 22, P1: 56, PL: 4, PM: 50, PTD: 0, CN: 1, PTH: 2, PTL: 3, BC: 2 },
        pCard: { CN2: 25, CN1: 26, CN0: 27, STD: 12, SN: 13, ST: 14, FTD: 16, FN: 17, FT: 18, CTD: 8, CHN: 9, CT: 10, RC: 22, P1: 176, PL: 4, PM: 20, PTD: 0, CN: 1, PTH: 2, PTL: 3, BC: 2 },
        SI10: { CN2: 25, CN1: 26, CN0: 27, STD: 12, SN: 13, ST: 14, FTD: 16, FN: 17, FT: 18, CTD: 8, CHN: 9, CT: 10, RC: 22, P1: 128, PL: 4, PM: 64, PTD: 0, CN: 1, PTH: 2, PTL: 3, BC: 8 }
    };

    function crc16(bytes) {
        var n = bytes && bytes.length ? bytes.length : 0;
        if (n < 1) {
            return [0, 0];
        }
        var crc = ((bytes[0] << 8) | (n > 1 ? bytes[1] : 0)) & 0xFFFF;
        var rest = [];
        var i;
        for (i = 2; i < n; i++) {
            rest.push(bytes[i]);
        }
        if (rest.length) {
            if (rest.length % 2 === 0) {
                rest.push(0, 0);
            } else {
                rest.push(0);
            }
        }
        for (i = 0; i < rest.length; i += 2) {
            var val = ((rest[i] << 8) | rest[i + 1]) & 0xFFFF;
            var j;
            for (j = 0; j < 16; j++) {
                var msb = (crc & 0x8000) !== 0;
                crc = (crc << 1) & 0xFFFF;
                if (val & 0x8000) {
                    crc = (crc + 1) & 0xFFFF;
                }
                if (msb) {
                    crc ^= CRC_POLY;
                }
                val = (val << 1) & 0xFFFF;
            }
        }
        return [(crc >> 8) & 0xFF, crc & 0xFF];
    }

    function crcOk(bytes, hi, lo) {
        var c = crc16(bytes);
        return c[0] === hi && c[1] === lo;
    }

    function rakennaKomento(cmd, params, herata) {
        var p = params || [];
        var runko = [cmd, p.length].concat(p);
        var c = crc16(runko);
        var out = [];
        if (herata !== false) {
            out.push(WAKEUP);
        }
        out.push(STX);
        var i;
        for (i = 0; i < runko.length; i++) {
            out.push(runko[i] & 0xFF);
        }
        out.push(c[0], c[1], ETX);
        return new Uint8Array(out);
    }

    function toInt(bytes, alku, n) {
        var v = 0;
        var i;
        for (i = 0; i < n; i++) {
            v = (v << 8) | (bytes[alku + i] & 0xFF);
        }
        return v;
    }

    function dekoodaaKorttinro(b0, b1, b2, b3) {
        if (b0 !== 0) {
            return (b1 << 16) | (b2 << 8) | b3;
        }
        var nr = (b1 << 16) | (b2 << 8) | b3;
        if (nr < 500000) {
            var perus = (b2 << 8) | b3;
            if (b1 < 2) {
                return perus;
            }
            return b1 * 100000 + perus;
        }
        return nr;
    }

    function korttityyppi(nro) {
        if (nro >= 7000000 && nro <= 9999999) {
            return 'SI10';
        }
        if (nro >= 4000000 && nro <= 4999999) {
            return 'pCard';
        }
        if (nro >= 2000000 && nro <= 2999999) {
            return 'SI8';
        }
        if (nro >= 1000000 && nro <= 1999999) {
            return 'SI9';
        }
        if (nro >= 6000000 && nro <= 6999999) {
            return 'SI9';
        }
        if (nro >= 500000 && nro <= 999999) {
            return 'SI6';
        }
        return 'SI5';
    }

    function kelloTeksti(sec) {
        if (sec == null || sec < 0) {
            return '';
        }
        var s = sec % 86400;
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var t = s % 60;
        return String(h).padStart(2, '0') + ':'
            + String(m).padStart(2, '0') + ':'
            + String(t).padStart(2, '0');
    }

    function dekoodaaAika(hi, lo, ptd) {
        if (((hi << 8) | lo) === TIME_RESET) {
            return null;
        }
        var sec = ((hi & 0xFF) << 8) | (lo & 0xFF);
        if (ptd != null && (ptd & 1)) {
            sec += 43200;
        } else if (ptd == null) {
            var nyt = new Date();
            var nytSec = nyt.getHours() * 3600 + nyt.getMinutes() * 60 + nyt.getSeconds();
            if (nytSec >= 43200 && sec < 43200) {
                sec += 43200;
            }
        }
        if (sec >= 86400) {
            sec -= 86400;
        }
        return sec;
    }

    function asemaKoodi(cn, ptd) {
        if (ptd == null) {
            return cn & 0xFF;
        }
        return ((ptd & 0xC0) << 2) + (cn & 0xFF);
    }

    function lueB(data, ix) {
        return ix == null || ix < 0 || ix >= data.length ? 0 : (data[ix] & 0xFF);
    }

    function puraKortti(data, tyyppi) {
        var card = CARD[tyyppi] || CARD.SI5;
        var badge = dekoodaaKorttinro(0, lueB(data, card.CN2), lueB(data, card.CN1), lueB(data, card.CN0));
        var leimat = [];
        function lisaa(koodi, sec) {
            if (sec == null) {
                return;
            }
            leimat.push({
                indeksi: leimat.length + 1,
                koodi: koodi,
                kello_sec: sec,
                kello: kelloTeksti(sec)
            });
        }
        var start = dekoodaaAika(lueB(data, card.ST), lueB(data, card.ST + 1), card.STD != null ? lueB(data, card.STD) : null);
        lisaa(0, start);
        var n = lueB(data, card.RC);
        if (tyyppi === 'SI5') {
            n -= 1;
        }
        if (n > card.PM) {
            n = card.PM;
        }
        if (n < 0) {
            n = 0;
        }
        var p = 0;
        var i = card.P1;
        while (p < n && i + card.PL <= data.length) {
            if (tyyppi === 'SI5' && i % 16 === 0) {
                i += 1;
            }
            var ptd = card.PTD != null ? lueB(data, i + card.PTD) : null;
            var cn = asemaKoodi(lueB(data, i + card.CN), ptd);
            var sec = dekoodaaAika(lueB(data, i + card.PTH), lueB(data, i + card.PTL), ptd);
            if (cn > 0 && sec != null) {
                lisaa(cn, sec);
            }
            i += card.PL;
            p += 1;
        }
        var finishPtd = card.FTD != null ? lueB(data, card.FTD) : null;
        var finish = dekoodaaAika(lueB(data, card.FT), lueB(data, card.FT + 1), finishPtd);
        var finishKoodi = card.FN != null ? asemaKoodi(lueB(data, card.FN), finishPtd) : 0;
        if (finish != null && finishKoodi > 0) {
            var jo = leimat.some(function (l) {
                return l.koodi === finishKoodi && l.kello_sec === finish;
            });
            if (!jo) {
                lisaa(finishKoodi, finish);
            }
        }
        return {
            tyyppi: 'kortti',
            lahde: 'si',
            siTyyppi: tyyppi,
            badge: badge,
            koodi: null,
            kello: null,
            lukija: true,
            leimat: leimat
        };
    }

    function puraKehykset(buf) {
        var kehykset = [];
        var i = 0;
        while (i < buf.length) {
            if (buf[i] === WAKEUP) {
                i += 1;
                continue;
            }
            if (buf[i] === NAK || buf[i] === ACK) {
                kehykset.push({ cmd: buf[i], station: 0, data: new Uint8Array(0), raw: buf.slice(i, i + 1) });
                i += 1;
                continue;
            }
            if (buf[i] !== STX) {
                i += 1;
                continue;
            }
            if (i + 3 < buf.length && buf[i + 1] === 0x46 && buf[i + 2] === 0x49 && buf[i + 3] === ETX) {
                kehykset.push({ cmd: 'FI', station: 0, data: new Uint8Array(0), raw: buf.slice(i, i + 4) });
                i += 4;
                continue;
            }
            if (i + 2 >= buf.length) {
                break;
            }
            var cmd = buf[i + 1];
            var len = buf[i + 2];
            var need = 6 + len;
            if (i + need > buf.length) {
                break;
            }
            if (buf[i + need - 1] !== ETX) {
                i += 1;
                continue;
            }
            var runko = buf.slice(i + 1, i + 3 + len);
            var hi = buf[i + 3 + len];
            var lo = buf[i + 4 + len];
            if (!crcOk(runko, hi, lo)) {
                i += 1;
                continue;
            }
            var payload = buf.slice(i + 3, i + 3 + len);
            var station = payload.length >= 2 ? ((payload[0] << 8) | payload[1]) : 0;
            var data = payload.length > 2 ? payload.slice(2) : new Uint8Array(0);
            kehykset.push({
                cmd: cmd,
                station: station,
                data: data,
                raw: buf.slice(i, i + need)
            });
            i += need;
        }
        return { kehykset: kehykset, jaljella: buf.slice(i) };
    }

    function luoLukija(opts) {
        opts = opts || {};
        var buf = new Uint8Array(0);
        var odota = null;
        var lukemassa = false;
        var jonossa = null;
        var api = {
            asema: 0,
            moodi: null,
            protoOk: false
        };

        function loki(txt) {
            if (opts.loki) {
                opts.loki(txt);
            }
        }

        function yhdista(a, b) {
            var out = new Uint8Array(a.length + b.length);
            out.set(a);
            out.set(b, a.length);
            return out;
        }

        function kirjoita(bytes) {
            if (!opts.kirjoita) {
                return Promise.resolve();
            }
            return Promise.resolve(opts.kirjoita(bytes));
        }

        function odotaKomentoa(cmd, ms) {
            return new Promise(function (ok, ei) {
                var t = setTimeout(function () {
                    if (odota && odota.ok === ok) {
                        odota = null;
                        ei(new Error('SportIdent ei vastannut'));
                    }
                }, ms || 2200);
                odota = {
                    cmd: cmd,
                    ok: function (k) {
                        clearTimeout(t);
                        ok(k);
                    },
                    ei: function (e) {
                        clearTimeout(t);
                        ei(e);
                    }
                };
            });
        }

        function laheta(cmd, params) {
            return kirjoita(rakennaKomento(cmd, params || [])).then(function () {
                return odotaKomentoa(cmd, 2200);
            });
        }

        async function luePalat(komento, eka, monta) {
            var data = new Uint8Array(0);
            var kehys = await laheta(komento, eka);
            var pala = kehys.data && kehys.data.length > 1 ? kehys.data.slice(1) : (kehys.data || new Uint8Array(0));
            data = yhdista(data, pala);
            var n;
            for (n = 1; n < monta; n++) {
                kehys = await odotaKomentoa(komento, 2200);
                pala = kehys.data && kehys.data.length > 1 ? kehys.data.slice(1) : (kehys.data || new Uint8Array(0));
                data = yhdista(data, pala);
            }
            return data;
        }

        async function lueKortti(tyyppi) {
            if (tyyppi === 'SI5') {
                var k = await laheta(C_GET_SI5, []);
                return k.data || new Uint8Array(0);
            }
            if (tyyppi === 'SI6') {
                return luePalat(C_GET_SI6, [P_SI6_CB], 3);
            }
            if (tyyppi === 'SI10') {
                return luePalat(C_GET_SI9, [P_SI6_CB], 5);
            }
            var bc = (CARD[tyyppi] && CARD[tyyppi].BC) || 2;
            var data = new Uint8Array(0);
            var b;
            for (b = 0; b < bc; b++) {
                var kehys = await laheta(C_GET_SI9, [b]);
                var pala = kehys.data && kehys.data.length > 1 ? kehys.data.slice(1) : (kehys.data || new Uint8Array(0));
                data = yhdista(data, pala);
            }
            return data;
        }

        async function kasitteleKortti(tyyppi, nro) {
            if (lukemassa) {
                return;
            }
            lukemassa = true;
            try {
                loki('SportIdent ' + tyyppi + ' ' + nro + ' luetaan');
                var data = await lueKortti(tyyppi);
                var sanoma = puraKortti(data, tyyppi);
                if (!sanoma.badge) {
                    sanoma.badge = nro;
                }
                await kirjoita(new Uint8Array([ACK]));
                try {
                    await laheta(C_BEEP, [1]);
                } catch (e2) { /* piippaus ei pakollinen */ }
                if (opts.onKortti) {
                    opts.onKortti(sanoma);
                }
            } catch (e) {
                loki('SportIdent-luku epäonnistui: ' + (e && e.message ? e.message : e));
                if (opts.onVirhe) {
                    opts.onVirhe(e);
                }
            }
            lukemassa = false;
        }

        function badgeDet(kehys, skipFirst) {
            var d = kehys.data || new Uint8Array(0);
            if (skipFirst && d.length > 1) {
                d = d.slice(1);
            }
            if (d.length >= 4 && d[0] === 0) {
                return dekoodaaKorttinro(d[0], d[1], d[2], d[3]);
            }
            if (d.length === 3) {
                return dekoodaaKorttinro(0, d[0], d[1], d[2]);
            }
            var n = 0;
            var i;
            for (i = 0; i < d.length; i++) {
                n = (n << 8) | d[i];
            }
            return n;
        }

        function kasitteleKehys(kehys) {
            if (kehys.station) {
                api.asema = kehys.station;
            }
            if (odota && (odota.cmd === kehys.cmd || odota.cmd === 0)) {
                var o = odota;
                odota = null;
                o.ok(kehys);
                return;
            }
            if (kehys.cmd === C_SI_REM) {
                if (odota) {
                    var o2 = odota;
                    odota = null;
                    o2.ei(new Error('Kortti poistettiin'));
                }
                return;
            }
            if (kehys.cmd === C_SI5_DET || kehys.cmd === C_SI6_DET || kehys.cmd === C_SI9_DET) {
                var tyyppi = kehys.cmd === C_SI5_DET ? 'SI5'
                    : (kehys.cmd === C_SI6_DET ? 'SI6' : korttityyppi(badgeDet(kehys, true)));
                var nro = kehys.cmd === C_SI9_DET ? badgeDet(kehys, true) : badgeDet(kehys, false);
                if (kehys.cmd !== C_SI9_DET && kehys.cmd === C_SI6_DET) {
                    tyyppi = 'SI6';
                }
                if (odota) {
                    jonossa = { tyyppi: tyyppi, nro: nro };
                    return;
                }
                kasitteleKortti(tyyppi, nro);
                return;
            }
            if (kehys.cmd === C_TRANS_REC) {
                if (opts.onVirhe) {
                    opts.onVirhe(new Error('Asema lähettää vain yksittäisiä leimoja. Vaihda lukumoodiin (Read SI-Cards).'));
                }
                return;
            }
            if (kehys.cmd === 'FI') {
                if (opts.onVirhe) {
                    opts.onVirhe(new Error('Vanha SI-asema. Käytä BSM7/BSM8 USB-lukijaa laajennetulla protokollalla.'));
                }
            }
        }

        api.syota = function (chunk) {
            if (!chunk || !chunk.length) {
                return;
            }
            buf = yhdista(buf, chunk instanceof Uint8Array ? chunk : new Uint8Array(chunk));
            if (buf.length > 20000) {
                buf = buf.slice(buf.length - 8000);
            }
            var tulos = puraKehykset(buf);
            buf = tulos.jaljella;
            tulos.kehykset.forEach(kasitteleKehys);
        };

        api.aloita = async function () {
            try {
                var ms = await laheta(C_SET_MS, [P_MS_DIRECT]);
                api.protoOk = true;
                loki('SportIdent master, asema ' + (ms.station || api.asema || ''));
            } catch (e) {
                api.protoOk = false;
                loki('SportIdent SET_MS: ' + (e && e.message ? e.message : e));
                if (jonossa) {
                    var j0 = jonossa;
                    jonossa = null;
                    kasitteleKortti(j0.tyyppi, j0.nro);
                }
                return false;
            }
            try {
                var sys = await laheta(C_GET_SYS_VAL, [0x00, 0x80]);
                var d = sys.data || new Uint8Array(0);
                var moodi = d.length > 0x71 + 1 ? d[0x71 + 1] : null;
                var proto = d.length > 0x74 + 1 ? d[0x74 + 1] : null;
                api.moodi = moodi;
                if (opts.onTila) {
                    opts.onTila({
                        tyyppi: 'tila',
                        laite: 'SportIdent',
                        koodi: api.asema,
                        moodi: moodi
                    });
                }
                if (moodi != null && moodi !== M_READOUT) {
                    loki('SportIdent-aseman moodi on ' + moodi + ', suositus lukumoodi 5');
                }
                if (proto != null && (proto & 1) === 0) {
                    loki('Asemalla ei ole laajennettua protokollaa');
                }
            } catch (e2) {
                loki('SportIdent GET_SYS_VAL: ' + (e2 && e2.message ? e2.message : e2));
            }
            if (jonossa) {
                var j = jonossa;
                jonossa = null;
                kasitteleKortti(j.tyyppi, j.nro);
            }
            return true;
        };

        return api;
    }

    return {
        crc16: crc16,
        rakennaKomento: rakennaKomento,
        puraKehykset: puraKehykset,
        dekoodaaKorttinro: dekoodaaKorttinro,
        korttityyppi: korttityyppi,
        puraKortti: puraKortti,
        luoLukija: luoLukija,
        C_SET_MS: C_SET_MS,
        C_GET_SI5: C_GET_SI5,
        C_SI5_DET: C_SI5_DET,
        CARD: CARD
    };
});
