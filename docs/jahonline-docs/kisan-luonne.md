# Kisan luonne ja Pirilän yhdistäminen (JAHOnline-palvelin)

Pirilän puoli (HkKisaWin ja ViestiWin) on tässä repossa. JAHOnline-sivuston
koodi ei ole tässä repossa. Sivuston puoli on toteutettu palvelimelle
seuraavasti:

| Tiedosto | Muutos |
|---|---|
| `includes/kisa_luonne.php` (uusi) | sarakkeet ja `pirila_yhteydet`-taulu (`kisaLuonneVarmista`), luonteet, `kisaNaytaValikko()`, yhteyksien muisti |
| `public/api/kilpailijat_bridge.php` | `action=luo_kilpailu` ja `action=yhdista` |
| `includes/api_auth.php` | `kilpailut`-listaan `luonne` ja `yhdistetty` |
| `admin/kilpailut.php` | valinta *Kisan luonne*, luonne kisalistan merkkinä |
| `includes/admin_nav.php` | hallintavalikko luonteen mukaan, merkkinä luonteen nimi |
| `includes/seura_kisavalikko.php`, `public/ajanotto_valinta.php` | seuran pikavalikko ja ajanoton valintasivu luonteen mukaan |

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

`kisaLuonneVarmista()` lisää sarakkeet ja taulun automaattisesti, kun
sivua tai API:a käytetään ensimmäisen kerran:

```sql
ALTER TABLE kilpailut
  ADD COLUMN luonne VARCHAR(20) NULL DEFAULT NULL,
  ADD COLUMN pirila_laji CHAR(1) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS pirila_yhteydet (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kilpailu_id INT NOT NULL,
  kayttaja_id INT NULL,                -- NULL, jos käytetty vanhaa kilpailuavainta
  lahde VARCHAR(40) NULL,              -- HkKisaWin / ViestiWin
  tyyppi VARCHAR(10) NULL,             -- yksilo / viesti
  asema VARCHAR(64) NOT NULL DEFAULT '',
  kansio VARCHAR(255) NOT NULL DEFAULT '',  -- Pirilän kilpailukansio
  pirila_nimi VARCHAR(255) NULL,
  laji CHAR(1) NULL,
  ensimmainen_at DATETIME NOT NULL,
  viimeksi_at DATETIME NOT NULL,
  UNIQUE KEY uq_pirila_yhteys (kilpailu_id, asema, kansio(190)),
  KEY idx_pirila_yhteys_kilpailu (kilpailu_id)
);
```

`luonne = NULL` (vanhat kisat) tarkoittaa: näytä kaikki valikot kuten ennen.

Kisan tietojen hallintasivulla on valinta **Kisan luonne** (yllä olevat arvot)
ja ruksi **Pirilä-synkka**. Ylläpitäjä voi muuttaa luonnetta; Pirilä ei
ylikirjoita sitä yhdistäessä.

**Pirilä-synkka** määrää, onko kisa Pirilä-kilpailu. Kun ruksi on päällä, sarjat,
radat, kilpailijat ja ajat tulevat HkKisaWinistä / ViestiWinistä. Kun ruksi on
pois, kisa tehdään JAHOnessa ja luonne valitsee näkyvät valikot.

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
4. tallentaa `kello`-kentän kuten `ping` ja vastaa:

```json
{"status":"ok","action":"yhdista","kilpailu_id":11,
 "kilpailu":{"id":11,"nimi":"Kevätkisa","tyyppi":"henkilokohtainen","tila":"luonnos","osuuksia":1},
 "nimi":"Kevätkisa","pvm":"2026-04-12","luonne":"suunnistus",
 "yhdistetty":true,"yhteydet":1,"server_time":"...","kello":{...}}
```

`yhteydet` on kisaan yhdistettyjen asemien ja kansioiden määrä. Jos `luonne`
puuttuu pyynnöstä, palvelin päättelee sen `laji`-kirjaimesta.

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

`pvm` on `vvvv-kk-pp` tai `null`. Palvelin hyväksyy myös muodon `p.k.vvvv`.
`vaiheet` on HkKisaWinin vaiheiden määrä (viestissä 1).

Pyyntö vaatii käyttäjäkohtaisen API-avaimen (ei `kilpailu_id`:tä). Kisan voi
luoda sama joukko kuin hallinnassa: admin, pääkäyttäjä tai seuran admin.
Muuten vastaus on HTTP 403.

Palvelin luo kisan tilaan `luonnos`, merkitsee sen Pirilä-kilpailuksi
(`kisa_tyyppi = 'pirila'`, `pirila_synkka = 1`) ja asettaa:

- omistajan: avaimen käyttäjä, tai aliakäyttäjällä tämän pääkäyttäjä
- seuraksi käyttäjän ensimmäisen seuran, jossa hän on admin
- kentät `luonne` ja `pirila_laji`

Vastaus:

```json
{"status":"ok","action":"luo_kilpailu","kilpailu_id":15,"id":15,"nimi":"Kevätkisa",
 "pvm":"2026-04-12","tyyppi":"yksilo","luonne":"suunnistus"}
```

Pirilä kutsuu heti perään `yhdista`, joka tallentaa aseman ja kansion.

Virhe: `{"status":"error","message":"..."}`. Pirilä näyttää viestin lokissa.
Jos `luonne` on tuntematon, palvelin käyttää `laji`-kirjaimen luonnetta (tuntematon
kirjain on `muu`).

## Valikot luonteen mukaan

Luonne ohjaa, mitkä valikot kisan sivuilla ja hallinnassa näkyvät:

| Valikko / sivu | suunnistus | ampumasuunnistus | hiihto | ampumahiihto | juoksu | uinti | sotilas5ottelu, meri5ottelu | muu |
|---|---|---|---|---|---|---|---|---|
| Ratatiedot (hallinta: Radat) | kyllä | kyllä | ei | ei | ei | ei | ei | ei |
| Emit-sivut (sisäänluku ja leimantarkastus) | kyllä | kyllä | ei | ei | ei | ei | ei | ei |
| Ajanotto (Mobiiliajanotto, Maalinappi, Numerot) | ei | ei | kyllä | kyllä | kyllä | kyllä | kyllä | kyllä |
| Ampumapaikka | ei* | kyllä* | ei* | kyllä* | ei* | ei* | kyllä* | ei* |
| Kilpailijat, Sarjat, Lähdöt, Nimenhuuto, Lähtökello, tulokset, seuranta | kyllä | kyllä | kyllä | kyllä | kyllä | kyllä | kyllä | kyllä |

Hallinnan kehys on **Kilpailutoiminnat** (ent. Ajanotto). Sen alla on **Lähtö**
(lähtöluenta, nimenhuuto, lähtökello). Kenttäkäyttäjän rooli *lähtötoiminnat*
avaa nämä. Ampumapaikka-valikko näkyy vain, jos kisaan on ruksittu
**Ampumapaikat**; vanhoille ampumalajeille ruksi asetetaan siirrossa.
Käyttöoikeus *ampumapaikat* näyttää valikon.

Hallinnan **Sarjat** ja **Radat** merkitään Pirilä-kisassa **Sarjat (Pirila)**
ja **Radat (Pirila)**. Nimet, matkat, lähdöt, radat ja rastit tulevat synkasta;
muokkaus on vain HkKisaWinissä tai ViestiWinissä. Jos kisaa ei ole liitetty
Pirilään, sarjat ja radat määritetään sovelluksessa (sarjoilla nimi, matka,
lähtö ja järjestys; radoilla tunnus, matkat, rastikoodit, sarjat ja
ajanoton käynnistys nollaleimauksesta tai lähtöajasta). Nollaleimaus merkitsee
lähtöajaksi nollaushetken; lähtöaika-mallissa tulos lasketaan kilpailijan
kellosta tai sarjan lähdöstä. Ilman Pirilää Radat-sivu näkyy hallinnassa luonteen
*ratatiedot*-suodatuksesta riippumatta, jotta leimantarkastukseen saa koodit.

Luonne vain piilottaa valikoita, se ei tuo uusia. Pirilä-kisan omat säännöt
pysyvät: Pirilä-kisassa ajanoton ruudukko ja Maalinappi eivät näy (ajat tulevat
Pirilästä), ja Numerot näkyy vain jaetulla ajanottolistalla.

\* Ampumapaikka vaatii lisäksi kisan raksin *Ampumapaikat* ja käyttäjän
käyttöoikeuden *ampumapaikat* (pääkäyttäjä näkee valikon hallinnassa).

`luonne = NULL`: kaikki valikot näkyvät.

Piilotus on vain näkymää: sivujen osoitteet ja API toimivat luonteesta
riippumatta, jotta Pirilän synkka (esim. emit-rastiväliajat) ei katkea, jos
luonne on valittu väärin.

Toteutus on `includes/kisa_luonne.php`:ssä:

```php
function kisaNaytaValikko(array $kilpailu, string $valikko): bool
{
    $luonne = kisaLuonneNormalisoi($kilpailu['luonne'] ?? null);
    if ($luonne === null) {
        return true;
    }
    $saannot = [
        'ratatiedot' => ['suunnistus', 'ampumasuunnistus'],
        'emit' => ['suunnistus', 'ampumasuunnistus'],
        'ajanotto' => ['hiihto', 'ampumahiihto', 'juoksu', 'uinti', 'sotilas5ottelu', 'meri5ottelu', 'muu'],
        'ampuma' => ['ampumasuunnistus', 'ampumahiihto', 'sotilas5ottelu', 'meri5ottelu'],
    ];
    if (!isset($saannot[$valikko])) {
        return true;
    }
    return in_array($luonne, $saannot[$valikko], true);
}
```
