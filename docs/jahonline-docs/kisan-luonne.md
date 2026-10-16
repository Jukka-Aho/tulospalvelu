# Kisan luonne ja Pirilän yhdistäminen (JAHOnline-palvelin)

Tämä on JAHOnline-sivuston toteutusohje. Pirilän puoli (HkKisaWin ja
ViestiWin) on tehty tässä repossa. Sivuston koodi ei ole tässä repossa, joten
alla oleva pitää toteuttaa JAHOnlinen `kilpailijat_bridge.php`:hen ja
hallintasivuille.

## Mitä Pirilä tekee

JAHOnline API (synkka) -ikkunassa:

- **Yhdistä** (ent. *Testaa (ping)*) lähettää `action=yhdista`. Jos palvelin ei
  tunne toimintoa, Pirilä yrittää vanhaa `ping`-pyyntöä, joten vanha palvelin
  toimii edelleen.
- Onnistuneet yhdistämiset Pirilä muistaa koneella tiedostossa
  `jahonline_yhdistetyt.ini` (exe-kansiossa). Kisa näkyy listassa heti ikkunan
  avautuessa, ja uusi kilpailukansio saa API-avaimen ja edellisen siitä
  kansiosta yhdistetyn kisan automaattisesti.
- **Luo uusi JAHOnline-kisa** näkyy, kun palvelin on vastannut API-avaimella
  (*Hae kilpailut* tai *Yhdistä* onnistui). Se lähettää `action=luo_kilpailu`
  avatun kilpailun nimellä, päivämäärällä ja **luonteella**, ja yhdistää
  heti luotuun kisaan.

## Luonne

Luonne tulee Pirilän kilpailulajista (*Kilpailun määritykset → Kilpailulaji*,
`kilpparam.kilplaji`):

| Pirilän laji | `laji` | `luonne` |
|---|---|---|
| Suunnistus | `S` | `suunnistus` |
| Hiihto | `H` | `hiihto` |
| Juoksu/hölkkä | `J` | `juoksu` |
| Ampumahiihto | `A` | `ampumahiihto` |
| Uinti (vain HkKisaWin) | `U` | `uinti` |
| Ampumasuunnistus (vain HkKisaWin) | `B` | `ampumasuunnistus` |
| Sotilas-5-ottelu | `P` | `sotilas5ottelu` |
| Meri-5-ottelu | `N` | `meri5ottelu` |
| Muu | `M` (tai muu) | `muu` |

Viesti ja yksilökisa erotetaan edelleen kentällä `tyyppi` (`viesti` /
`yksilo`). Luonne on siitä riippumaton: viestikin voi olla `hiihto`.

## Tietokanta

```sql
ALTER TABLE kilpailut
  ADD COLUMN luonne VARCHAR(20) NULL DEFAULT NULL,
  ADD COLUMN pirila_laji CHAR(1) NULL DEFAULT NULL;

CREATE TABLE pirila_yhteydet (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kilpailu_id INT NOT NULL,
  kayttaja_id INT NOT NULL,
  lahde VARCHAR(20) NOT NULL,          -- HkKisaWin / ViestiWin
  asema VARCHAR(64) NOT NULL,          -- koneen tai aseman nimi
  kansio VARCHAR(260) NOT NULL,        -- Pirilän kilpailukansio
  pirila_nimi VARCHAR(160) NULL,
  laji CHAR(1) NULL,
  ensimmainen_at DATETIME NOT NULL,
  viimeksi_at DATETIME NOT NULL,
  UNIQUE KEY (kilpailu_id, asema, kansio),
  FOREIGN KEY (kilpailu_id) REFERENCES kilpailut(id)
);
```

`luonne = NULL` (vanhat kisat) tarkoittaa: näytä kaikki valikot kuten ennen.

Kisan tietojen hallintasivulle lisätään valinta **Kisan luonne** (yllä olevat
arvot). Ylläpitäjä voi muuttaa sitä, Pirilä ei ylikirjoita sitä yhdistäessä.

## API

Kaikki pyynnöt: `POST kilpailijat_bridge.php`, `Authorization: Bearer <api_token>`.

### `kilpailut`: lisäkentät vastaukseen

```json
{"id":11,"nimi":"Kevätkisa","tyyppi":"yksilo","pvm":"2026-04-12","pirila":true,
 "luonne":"suunnistus","yhdistetty":true}
```

`yhdistetty` on `true`, jos kisalla on rivi `pirila_yhteydet`-taulussa.

### `yhdista` (uusi)

```json
{
  "action":"yhdista",
  "kilpailu_id":11,
  "lahde":"HkKisaWin",
  "tyyppi":"yksilo",
  "asema":"MAALI-PC",
  "kansio":"C:\\Kisat\\Kevatkisa",
  "pirila_nimi":"Kevätkisa",
  "laji":"S",
  "luonne":"suunnistus",
  "kello":"2026-10-16 12:00:00"
}
```

Palvelin:

1. tarkistaa käyttäjän oikeuden kisaan kuten `ping`
2. lisää tai päivittää rivin `pirila_yhteydet` (`viimeksi_at = NOW()`)
3. asettaa `kilpailut.pirila_laji = laji`, ja `luonne`-kentän **vain jos se on
   NULL**
4. vastaa kuten `ping`, kisan tiedoilla:

```json
{"status":"ok","kilpailu":{"id":11,"nimi":"Kevätkisa","pvm":"2026-04-12",
 "tyyppi":"yksilo","luonne":"suunnistus"},"server_time":"..."}
```

Jos netin luonne poikkeaa Pirilän lajista, Pirilä näyttää varoituksen mutta
yhdistää silti.

### `luo_kilpailu` (uusi)

```json
{
  "action":"luo_kilpailu",
  "lahde":"HkKisaWin",
  "tyyppi":"yksilo",
  "pirila":true,
  "nimi":"Kevätkisa",
  "pvm":"2026-04-12",
  "luonne":"suunnistus",
  "laji":"S",
  "alalaji":"",
  "vaiheet":1
}
```

`pvm` on `vvvv-kk-pp` tai `null`. `vaiheet` on HkKisaWinin vaiheiden määrä
(viestissä 1).

Palvelin luo kisan avaimen käyttäjälle (omistaja), merkitsee sen
Pirilä-kilpailuksi (`pirila = 1`), tallentaa `luonne`- ja `pirila_laji`-kentät
ja vastaa:

```json
{"status":"ok","kilpailu_id":15,"kilpailu":{"id":15,"nimi":"Kevätkisa","luonne":"suunnistus"}}
```

Virhe: `{"status":"error","message":"..."}`. Pirilä näyttää viestin lokissa.
Tuntematon `luonne` tallennetaan arvona `muu`.

## Valikot luonteen mukaan

Luonne ohjaa, mitkä valikot kisan sivuilla ja hallinnassa näkyvät:

| Valikko / sivu | suunnistus | ampumasuunnistus | hiihto | ampumahiihto | juoksu | uinti | sotilas5ottelu, meri5ottelu | muu |
|---|---|---|---|---|---|---|---|---|
| Ratatiedot | kyllä | kyllä | ei | ei | ei | ei | ei | ei |
| Emit-sivut (emit-luenta, rastiväliajat `valiajat.php`) | kyllä | kyllä | ei | ei | ei | ei | ei | ei |
| Ajanotto (ruudukko, nappi, numerot, jaettu ajanottolista) | ei | ei | kyllä | kyllä | kyllä | kyllä | kyllä | kyllä |
| Ampumasakot (mobiili: kilpailija) | ei | kyllä | ei | kyllä | ei | ei | kyllä | ei |
| Osanottajat, lähtölistat, tulokset, online-väliajat, seuranta | kyllä | kyllä | kyllä | kyllä | kyllä | kyllä | kyllä | kyllä |

`luonne = NULL`: kaikki valikot näkyvät.

Piilotus on vain näkymää: sivujen osoitteet ja API toimivat luonteesta
riippumatta, jotta Pirilän synkka (esim. emit-rastiväliajat) ei katkea, jos
luonne on valittu väärin.

Esimerkki PHP-apufunktiosta:

```php
function kisa_nayta_valikko(?string $luonne, string $valikko): bool
{
    if ($luonne === null || $luonne === '') {
        return true;
    }
    $saannot = [
        'ratatiedot' => ['suunnistus', 'ampumasuunnistus'],
        'emit'       => ['suunnistus', 'ampumasuunnistus'],
        'ajanotto'   => ['hiihto', 'ampumahiihto', 'juoksu', 'uinti',
                         'sotilas5ottelu', 'meri5ottelu', 'muu'],
        'sakot'      => ['ampumasuunnistus', 'ampumahiihto',
                         'sotilas5ottelu', 'meri5ottelu'],
    ];
    if (!isset($saannot[$valikko])) {
        return true;
    }
    return in_array($luonne, $saannot[$valikko], true);
}
```
