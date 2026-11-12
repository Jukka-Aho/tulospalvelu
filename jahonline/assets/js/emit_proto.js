(function (root, factory) {
    var api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    root.JahEmitProto = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    var STX = 0x02;
    var ETX = 0x03;
    var LUKIJA = { 240: 1, 243: 1, 244: 1, 250: 1, 253: 1 };

    function parseAika(st) {
        var m = String(st || '').trim().match(/^(\d+):(\d{1,2}):(\d{1,2})(?:[.,](\d{1,3}))?$/);
        if (!m) {
            return null;
        }
        var hours = parseInt(m[1], 10);
        var min = parseInt(m[2], 10);
        var sec = parseInt(m[3], 10);
        if (min > 59 || sec > 59) {
            return null;
        }
        var h = hours % 24;
        var teksti = String(h).padStart(2, '0') + ':'
            + String(min).padStart(2, '0') + ':'
            + String(sec).padStart(2, '0');
        return { sec: hours * 3600 + min * 60 + sec, teksti: teksti };
    }

    function parseSanoma(text) {
        var osat = String(text || '').split('\t');
        var msgtp = 0;
        var badge = 0;
        var koodi = null;
        var moodi = null;
        var laite = '';
        var sarjanumero = '';
        var kello = null;
        var laiteKello = null;
        var controls = [];
        var i;
        for (i = 0; i < osat.length; i++) {
            var p = osat[i].replace(/[\x02\x03\r\n]/g, '').trim();
            if (!p) {
                continue;
            }
            var key = p.charAt(0);
            var rest = p.slice(1);
            if (key === 'I') {
                laite = rest;
                msgtp = 1;
            } else if (key === 'B' && msgtp === 0 && rest.charAt(0) === 'M') {
                msgtp = 4;
            } else if (key === 'C') {
                koodi = parseInt(rest, 10);
            } else if (key === 'X' && msgtp === 1) {
                moodi = parseInt(rest, 10);
            } else if (key === 'Y' && msgtp === 1) {
                sarjanumero = rest;
            } else if (key === 'N') {
                badge = parseInt(rest, 10) || 0;
                if (msgtp !== 4) {
                    msgtp = 2;
                }
            } else if (key === 'E' || key === 'W') {
                var aika = parseAika(rest);
                if (msgtp === 1) {
                    laiteKello = aika;
                } else if (aika) {
                    kello = aika;
                }
            } else if (key === 'P') {
                var pm = rest.match(/^(\d+)-(\d+)-(.+)$/);
                if (!pm) {
                    continue;
                }
                var pa = parseAika(pm[3]);
                if (!pa) {
                    continue;
                }
                controls.push({
                    indeksi: parseInt(pm[1], 10),
                    koodi: parseInt(pm[2], 10),
                    kello_sec: pa.sec,
                    kello: pa.teksti
                });
            }
        }
        controls.sort(function (a, b) { return a.indeksi - b.indeksi; });
        if (badge > 0) {
            var lukija = false;
            controls.forEach(function (c) {
                if (LUKIJA[c.koodi]) {
                    lukija = true;
                }
            });
            return {
                tyyppi: 'kortti',
                badge: badge,
                koodi: koodi,
                kello: kello,
                lukija: lukija,
                leimat: controls.filter(function (c) { return c.koodi > 0 && c.koodi < 256; })
            };
        }
        if (msgtp === 1 || laite) {
            return {
                tyyppi: 'tila',
                laite: laite,
                koodi: koodi,
                moodi: moodi,
                sarjanumero: sarjanumero,
                kello: laiteKello
            };
        }
        return null;
    }

    function latin1(bytes) {
        var s = '';
        var i;
        for (i = 0; i < bytes.length; i++) {
            s += String.fromCharCode(bytes[i]);
        }
        return s;
    }

    function luoPuskuri() {
        var buf = new Uint8Array(0);
        var api = { tavut: 0, viime: '' };

        function tulostettavaOsuus(bytes) {
            var ok = 0;
            var i;
            for (i = 0; i < bytes.length; i++) {
                var b = bytes[i];
                if (b === 9 || b === 10 || b === 13 || (b >= 32 && b < 127)) {
                    ok++;
                }
            }
            return bytes.length ? ok / bytes.length : 0;
        }

        function asetaViime(bytes) {
            if (tulostettavaOsuus(bytes) < 0.75) {
                return;
            }
            var s = latin1(bytes).replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g, '').replace(/[\t\r\n]/g, ' ').trim();
            if (s) {
                api.viime = s.length > 80 ? s.slice(-80) : s;
            }
        }

        function kasitteleTeksti(frame, cb) {
            if (frame.length <= 2) {
                return;
            }
            var sanoma = parseSanoma(latin1(frame));
            if (sanoma && cb) {
                asetaViime(frame);
                cb(sanoma);
            } else if (frame.length > 15 && tulostettavaOsuus(frame) >= 0.75 && cb) {
                asetaViime(frame);
                cb({ tyyppi: 'tuntematon', teksti: api.viime });
            }
        }

        api.syota = function (chunk, cb) {
            if (!chunk || !chunk.length) {
                return;
            }
            api.tavut += chunk.length;
            var next = new Uint8Array(buf.length + chunk.length);
            next.set(buf);
            next.set(chunk, buf.length);
            buf = next;
            if (buf.length > 200000) {
                buf = buf.slice(buf.length - 100000);
            }
            for (;;) {
                var stx = -1;
                var nl = -1;
                var i;
                for (i = 0; i < buf.length; i++) {
                    if (stx < 0 && buf[i] === STX) {
                        stx = i;
                    }
                    if (nl < 0 && (buf[i] === 10 || buf[i] === 13)) {
                        nl = i;
                    }
                    if (stx >= 0 && nl >= 0) {
                        break;
                    }
                }
                if (stx >= 0 && (nl < 0 || stx <= nl)) {
                    if (stx > 0) {
                        asetaViime(buf.slice(0, stx));
                        buf = buf.slice(stx);
                    }
                    var end = -1;
                    for (i = 1; i < buf.length; i++) {
                        if (buf[i] === ETX) {
                            end = i;
                            break;
                        }
                    }
                    if (end < 0) {
                        if (buf.length > 8000) {
                            buf = buf.slice(buf.length - 64);
                        }
                        return;
                    }
                    var frame = buf.slice(1, end);
                    buf = buf.slice(end + 1);
                    kasitteleTeksti(frame, cb);
                    continue;
                }
                if (nl >= 0) {
                    var line = buf.slice(0, nl);
                    var cut = nl + 1;
                    if (buf[nl] === 13 && buf.length > nl + 1 && buf[nl + 1] === 10) {
                        cut = nl + 2;
                    }
                    buf = buf.slice(cut);
                    kasitteleTeksti(line, cb);
                    continue;
                }
                if (buf.length > 512) {
                    asetaViime(buf.slice(-48));
                    buf = buf.slice(buf.length - 32);
                }
                return;
            }
        };
        return api;
    }

    function kelloKomento(date) {
        var d = date || new Date();
        function p(n) { return String(n).padStart(2, '0'); }
        return '/SC' + p(d.getHours()) + '.' + p(d.getMinutes()) + '.' + p(d.getSeconds()) + '\r\n/ST\r\n';
    }

    // Pirilän LID_LUKIJA: langalla 217 tavua, alku 0x20 0x20.
    // Tavut on XOR-koodattu 0xDF:llä. Tarkiste on (tavu ^ 0xDF) -summa nolla.
    var LUKIJA_PITUUS = 217;
    var LUKIJA_XOR = 0xDF;

    function lukijaSummaNolla(bytes, alku, pituus) {
        var sum = 0;
        var i;
        for (i = 0; i < pituus; i++) {
            sum = (sum - (bytes[alku + i] ^ LUKIJA_XOR)) & 255;
        }
        return sum === 0;
    }

    function sekunnitTekstiksi(t) {
        var h = Math.floor(t / 3600) % 24;
        var m = Math.floor((t % 3600) / 60);
        var s = t % 60;
        return String(h).padStart(2, '0') + ':'
            + String(m).padStart(2, '0') + ':'
            + String(s).padStart(2, '0');
    }

    function lukijaKentta(badge, viikko, vuosi, leimat, lukija) {
        if (badge < 1 || viikko < 1 || viikko > 53 || vuosi > 99) {
            return null;
        }
        return {
            tyyppi: 'kortti',
            lahde: 'lukija',
            badge: badge,
            viikko: viikko,
            vuosi: vuosi,
            koodi: null,
            kello: null,
            lukija: !!lukija,
            leimat: leimat || []
        };
    }

    function puraLukijaOtsikko(wire, offset) {
        var c0 = wire[offset] ^ LUKIJA_XOR;
        var c1 = wire[offset + 1] ^ LUKIJA_XOR;
        if (c0 !== 0xFF || c1 !== 0xFF) {
            return null;
        }
        var b0 = wire[offset + 2] ^ LUKIJA_XOR;
        var b1 = wire[offset + 3] ^ LUKIJA_XOR;
        var b2 = wire[offset + 4] ^ LUKIJA_XOR;
        var badge = b0 | (b1 << 8) | (b2 << 16);
        var viikko = wire[offset + 6] ^ LUKIJA_XOR;
        var vuosi = wire[offset + 7] ^ LUKIJA_XOR;
        return lukijaKentta(badge, viikko, vuosi, [], false);
    }

    function puraLukijaKehys(wire, offset, tavut) {
        var n = tavut != null ? tavut : (wire.length - offset);
        if (n < 10) {
            return null;
        }
        if (n > LUKIJA_PITUUS) {
            n = LUKIJA_PITUUS;
        }
        var c = new Uint8Array(n);
        var i;
        for (i = 0; i < n; i++) {
            c[i] = wire[offset + i] ^ LUKIJA_XOR;
        }
        var badge = c[2] | (c[3] << 8) | (c[4] << 16);
        var leimat = [];
        var off = 10;
        var lukija = false;
        var max = Math.floor((n - 10) / 3);
        if (max > 50) {
            max = 50;
        }
        for (i = 0; i < max; i++) {
            var code = c[off];
            var aika = c[off + 1] | (c[off + 2] << 8);
            off += 3;
            if (!code) {
                continue;
            }
            if (LUKIJA[code]) {
                lukija = true;
            }
            leimat.push({
                indeksi: i + 1,
                koodi: code,
                kello_sec: aika,
                kello: aika ? sekunnitTekstiksi(aika) : ''
            });
        }
        return lukijaKentta(badge, n > 7 ? c[6] : 0, n > 7 ? c[7] : 0, leimat, lukija);
    }

    function etsiLukija(data) {
        var loytyi = [];
        var i = 0;
        if (!data || data.length < 10) {
            return loytyi;
        }
        while (i + 10 <= data.length) {
            if (data[i] !== 0x20 || data[i + 1] !== 0x20) {
                i++;
                continue;
            }
            if (!lukijaSummaNolla(data, i + 2, 8)) {
                i++;
                continue;
            }
            var tavut = data.length - i;
            var sanoma = puraLukijaKehys(data, i, tavut);
            if (sanoma) {
                loytyi.push(sanoma);
                if (tavut >= LUKIJA_PITUUS) {
                    i += LUKIJA_PITUUS;
                    continue;
                }
                break;
            }
            var otsikko = puraLukijaOtsikko(data, i);
            if (otsikko) {
                loytyi.push(otsikko);
                if (tavut >= LUKIJA_PITUUS) {
                    i += LUKIJA_PITUUS;
                    continue;
                }
            }
            break;
        }
        return loytyi;
    }

    return {
        parseAika: parseAika,
        parseSanoma: parseSanoma,
        luoPuskuri: luoPuskuri,
        kelloKomento: kelloKomento,
        etsiLukija: etsiLukija,
        LUKIJA_PITUUS: LUKIJA_PITUUS,
        LUKIJA: LUKIJA
    };
});
