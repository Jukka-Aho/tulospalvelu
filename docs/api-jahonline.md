# JAHOnline API — kaksisuuntainen synkka

Pirilä (HkKisaWin ja ViestiWin) ↔ JAHOnline tulospalvelu.

## Miksi tämä tapa

Kilpailijatiedot elävät Pirilässä `kilptietue` / `KILP.DAT`-rakenteessa. Valmiit
CSV/XML/KILP.DAT-vientityökalut sopivat kertatuontiin, mutta kisapäivän
kaksisuuntaiseen synkkaan tarvitaan HTTP + Bearer-avain.

JAHOnline käyttää **käyttäjäkohtaista** `api_token`-arvoa (hallinta → käyttäjä).
Pirilä lähettää saman avaimen: `Authorization: Bearer <api_token>`.
Avaimella haetaan lista netissä luoduista **Pirilä-kilpailuista**, joista
valitaan yhdistettävä kisa (`kilpailu_id`).

JAHOnline API on ainoa siirtotapa. Kaksisuuntainen synkka on HkKisaWinissä ja ViestiWinissä (valikko
**JAHOnline API (synkka)**). Osanottajat, läsnäolo, maaliajat, online-rastit ja Emit-rastiväliajat
kulkevat samaa siltaa.

- Asennusohje: [docs-site/docs/asentaminen/jahonline.md](../docs-site/docs/asentaminen/jahonline.md)
- JAHOnline-sivun `docs/pirila.md` -korvaus: [docs/jahonline-docs/pirila.md](jahonline-docs/pirila.md)

## Endpoint

Oletus-URL:

`https://jahonline.com/public/api/kilpailijat_bridge.php`

## Auth

| Header | Arvo |
|--------|------|
| `Authorization` | `Bearer <käyttäjän api_token>` |
| `X-API-Key` | sama (vaihtoehto) |

`action=kilpailut` ei vaadi `kilpailu_id`:tä (lista käyttäjän Pirilä-kisoista).
Muissa toiminnoissa bodyyn: `"kilpailu_id": <int>` (valittu kisa).

## Actions

### `kilpailut` (käyttäjäavain → kisalista)
```json
{"action":"kilpailut","lahde":"HkKisaWin","tyyppi":"yksilo","pirila":true}
```
ViestiWin: `"lahde":"ViestiWin","tyyppi":"viesti"`.

→
```json
{
  "status":"ok",
  "kilpailut":[
    {"id":11,"nimi":"Kevätkisa","tyyppi":"yksilo","pvm":"2026-04-12","pirila":true},
    {"id":12,"nimi":"Viestikisa","tyyppi":"viesti","pvm":"2026-04-19","pirila":true}
  ]
}
```

Palvelin palauttaa vain ne kilpailut, joihin avaimen käyttäjällä on oikeus ja
jotka on merkitty Pirilä-kilpailuiksi. Pirilä suodattaa lisäksi `pirila:false`
pois ja HkKisaWin jättää `tyyppi: viesti` -rivit, ViestiWin muut kuin viestin.

Kentät: `id` (tai `kilpailu_id`), `nimi` (tai `name` / `otsikko`), valinnainen
`pvm` / `date`, `tyyppi` (`yksilo` / `viesti`), `pirila` (boolean).

Vastauksen kilpailurivillä voi olla myös `luonne` (`suunnistus`, `hiihto`,
`juoksu`, …) ja `yhdistetty` (boolean).

### `yhdista` (Pirilän **Yhdistä**-painike)
```json
{"action":"yhdista","kilpailu_id":11,"lahde":"HkKisaWin","tyyppi":"yksilo",
 "asema":"MAALI-PC","kansio":"C:\\Kisat\\Kevatkisa","pirila_nimi":"Kevätkisa",
 "laji":"S","luonne":"suunnistus","kello":"2026-10-16 12:00:00"}
```
→ kuten `ping`. Palvelin kirjaa yhteyden (`pirila_yhteydet`). Jos vastaus ei ole
`ok`, Pirilä yrittää vanhaa `ping`-pyyntöä, joten vanha palvelin toimii yhä.

### `ping`
```json
{"action":"ping","kilpailu_id":11}
```
→ `{ "status":"ok", "kilpailu": {...}, "server_time":"..." }`

### `luo_kilpailu` (Pirilän **Luo uusi JAHOnline-kisa**)
```json
{"action":"luo_kilpailu","lahde":"HkKisaWin","tyyppi":"yksilo","pirila":true,
 "nimi":"Kevätkisa","pvm":"2026-04-12","luonne":"suunnistus","laji":"S",
 "alalaji":"","vaiheet":1}
```
→ `{"status":"ok","action":"luo_kilpailu","kilpailu_id":15,"id":15,"nimi":"Kevätkisa","pvm":"2026-04-12","tyyppi":"yksilo","luonne":"suunnistus"}`

Vaatii käyttäjäkohtaisen avaimen (admin, pääkäyttäjä tai seuran admin).
Luonne tulee Pirilän kilpailulajista ja ohjaa, mitkä valikot netissä näkyvät
(juoksussa ajanotto, suunnistuksessa ratatiedot ja Emit-sivut). Palvelimen
toteutus: [jahonline-docs/kisan-luonne.md](jahonline-docs/kisan-luonne.md).

### `kilpailijat` (JAHOnline → Pirilä)
```json
{"action":"kilpailijat","kilpailu_id":11}
```
→ lista kentillä: `numero`, `sukunimi`, `etunimi`, `nimi`, `seura`, `maa`,
`sarja_nimi`, `badge`/`emit_koodi`, `lasna`, `status`, `aika_sec`, `sija`,
`lahto_aika` / `pirila_lahto_at` / `lahto_sec`, `valiajat[]`

`lasna` on boolean (`true`, kun kilpailija on läsnä: ei poissa / ei-lähtenyt /
vakantti). Ilmoittautunut (`N`) käsitellään kuten avoin: `lasna: true` ja
`status` on `LASNA` jos tulosta ei vielä ole, muuten `OK` / `DNS` / `DNF` /
`DSQ`. Kirjain `N` kulkee silti kentässä `keskhyl`.

Kun netin API kertoo lähtöpaikan emit-luennan merkinneen ilmoittautuneen
läsnäolevaksi (`status` `LASNA`/`PRESENT`/`OK` ilman `keskhyl N` -kaiutusta,
`keskhyl: "-"`, tai väliaika/`aika_sec`), Pirilä vaihtaa `N` → `-`.
Oman synkan kaiutus (`keskhyl: "N"`) ei tee tätä muutosta. Maaliin tuleva
ilmoittautunut saa tark./esitys-statuksen leimaustiedoista kuten avoin.

### `synkkaa` (Pirilä → JAHOnline)
```json
{
  "action":"synkkaa",
  "kilpailu_id":11,
  "lahde":"HkKisaWin",
  "luo_puuttuvat":true,
  "kilpailijat":[
    {
      "numero":101,
      "sukunimi":"Meikäläinen",
      "etunimi":"Matti",
      "seura":"HSK",
      "sarja_nimi":"H21",
      "badge":123456,
      "lasna":true,
      "status":"LASNA",
      "aika_sec":null,
      "sija":null,
      "lahto_aika":"10:05:00",
      "pirila_lahto_at":"10:05:00",
      "lahto_sec":36300,
      "valiajat":[]
    }
  ]
}
```

`luo_puuttuvat:true` luo puuttuvat numerot JAHOnlineen (sarja nimellä).

Emit-luenta (leimantarkastus / `ESILUENTA`) merkitsee kilpailijan läsnäolevaksi
ja lähettää yhden kilpailijan `synkkaa`-sanoman heti (`lasna: true`).

Osanottaja- ja tulosmuutokset (nimi, seura, emit, **lähtöaika**, maali, status)
lähtevät nettiin samalla hetkellä kuin Pirilän sisäinen tiedonsiirto (`laheta`
`tallenna`-funktiossa). Ero: JAHOnline-jono ei vaadi `comfl` / RS-232 / TCP-asemaverkkoa.
Säie tyhjentää jonon noin 200 ms välein, kun kilpailu on auki, **Yhdistä**
on onnistunut (`kaynnissa`) ja taustasäie on käynnissä. Ilman avattua kisaa
synkkaa ei käynnistetä. **Lopeta synkka** pysäyttää säikeen ja nollaa `kaynnissa`.

Koko lista lähtee lisäksi Automaatio-välilehden välein (`lahetysvali`, oletus 10 s,
minimi 2 s) kun synkka on käynnissä. Tiedonsiirron
`YHTEYS=` / `COM=` -parametrit koskevat vain asemien välistä liikennettä, ei nettiä.

Lähtöaika menee kentissä `lahto_aika` ja `pirila_lahto_at` (kellonaika `HH:MM:SS`)
sekä `lahto_sec` (sekunnit vuorokaudesta). HkKisaWin käyttää kilpailijan `tlahto`-
arvoa, tai sarjan ensimmäistä lähtöä jos henkilökohtaista ei ole. ViestiWin
käyttää `Lahto(osuus)` (osuuden `ylahto` tai sarjan lähtö). JAHOnline kirjoittaa
nämä `kilpailijat.pirila_lahto_at`-kenttään ja synkkaa sarjan `lahdot`-rivin.

Haku lukee lähtöajan samassa järjestyksessä: `lahto_aika`, sitten `pirila_lahto_at`,
sitten `lahto_sec`.

`aika_sec` on **tuloksen kokonaissekunnit** (maali − lähtö), ei Pirilän
sisäisiä millisekunteja (`SEK = 1000`) eikä kellonaikaa. Esim. 45:00 → `2700`.
Lähtökello on erikseen `lahto_sec` (sekunnit vuorokaudesta). ViestiWinissä
`aika_sec` on osuuden juoksuaika (`osTulos`), ei `Maali()`-kellonaika.

### Haku ei saa pyyhkiä kisan tilaa

Taustasynkka tekee järjestyksessä: live-jono (`synkkaa` / `tapahtuma`) → koko lista
ulos → haku. Haku **täyttää vain tyhjät** paikalliset ajat, väliajat ja lähtöajat.
`aika_sec: 0` / `null` ei tyhjennä maalia. Nimi, emit ja sarja päivitetään
taustahaulla vain jos paikallinen kenttä on tyhjä.

**Hae kilpailijat nyt** (`korvaaKentat`) saa korvata nimet, emit-koodin ja ajat
kun verkko lähettää arvon `> 0`. Nollaa/nullia ei silti kirjoiteta paikallisen
tuloksen päälle.

Viestissä `lasna: true` ei muuta tarkistettua (`T`) läsnä-merkiksi. Poissa / ei lähtenyt / vakantti
(`E`/`P`/`V`/`B`) voi muuttua läsnäolevaksi. Viesti luo puuttuvan joukkueen haulla
jos `sarja_nimi` täsmää paikalliseen sarjaan.

Statuskirjaimet kulkevat kentässä `keskhyl`. Sanastatus on sama kirjain:

| Kirjain | status |
|---------|--------|
| `T` | `OK` (tarkistettu) |
| `H` | `DSQ` |
| `K` | `DNF` |
| `E` / `P` / `V` | `DNS` |
| `N` | `LASNA`, jos tulosta ei ole, muuten `OK` |
| `-` | `OK` tai `LASNA` |

Jos live-jono (256) täyttyy, ylimääräiset merkitään lokiin (`Jono taynna`), niitä
ei pudoteta ääneti.

`synkkaa` sisältää myös `valiajat[]` ja `"tyyppi":"yksilo"` tai `"tyyppi":"viesti"`.
Sama taulukko kuljettaa kaksi asiaa:

1. **Online-väliaikapisteet** (`va[]` / `p_aika`) — rivi **ilman** `rasti_koodi`.
   JAHOnline päivittää näistä `sarjat.valia_lkm`.
2. **Emit-rastiväliajat** (`laskeemitvaliajat`, varalla `tee_emva`) — rivi
   **kentällä** `rasti_koodi`. Nämä syöttävät rastiväliajat-näkymän
   (`/public/valiajat.php`). `vali_sec` on valinnainen rastiväli sekunteina.

```json
"valiajat":[
  {"piste":1,"aika_sec":612,"sija":3},
  {"piste":1,"aika_sec":184,"rasti_koodi":31,"vali_sec":184},
  {"piste":2,"aika_sec":401,"rasti_koodi":42,"vali_sec":217}
]
```

Sama `piste`-numero online- ja emit-rivillä on sallittu: palvelin erottaa ne
`rasti_koodi`-kentällä. Haku takaisin Pirilään **ohittaa** rivit, joissa
`rasti_koodi > 0`, jotta emit-leimat eivät kirjoitu `va[]`-onlinepisteisiin.

Ajanotto / rastileima lähettää lisäksi heti:

### `tapahtuma` (live online-rasti)

```json
{
  "action":"tapahtuma",
  "kilpailu_id":11,
  "lahde":"HkKisaWin",
  "tapahtumat":[
    {"numero":101,"piste":1,"aika_sec":1234,"lahde":"online"}
  ]
}
```

Viesti (osuus 1-pohjainen JSON:ssa, 0-pohjainen Pirilässä):

```json
{
  "action":"tapahtuma",
  "kilpailu_id":11,
  "lahde":"ViestiWin",
  "tyyppi":"viesti",
  "tapahtumat":[
    {"numero":42,"osuus":2,"piste":1,"aika_sec":890,"lahde":"online"}
  ]
}
```

- `piste` **0** = maali, **≥ 1** = online-rasti / väliaikapiste
- HkKisaWin-sisällä maali on `va[1]` (`p_aika(0)`), 1. väliaika `va[2]` (`p_aika(1)`).
  JSON-`piste` n on `va[n+1]`, ei `va[n]` — muuten maaliaika menisi 1. väliaikaan.
  Lähetys lukee vaiheen `k_pv` (vaihe 1 ⇒ 0). `k_pv-1` jätti nykyisen vaiheen
  `valiajat[]`-taulukon tyhjäksi.
- `aika_sec` samoin kokonaissekunteina (esim. `1234` ≈ 20:34), ei millisekunteina
- Haku: `kilpailijat`-vastauksen `valiajat[]` kirjoitetaan paikalliseen kantaan
  kun *Vastaanota väliajat* on päällä
- JAHOnline-bridgen (`kilpailijat_bridge.php`) pitää hyväksyä `action=tapahtuma`
  ja kirjoittaa `valiajat` / maaliaika (viestissä myös `osuus`)

### Työasemien siirto: `tapahtumat` ja `tapahtumat_hae`

Automaatio-välilehden **Työasemien siirto** valitsee, miten Pirilän asemat vaihtavat
samaa kilpailijadataa.

Lähiverkko (`laheta` / `comfl`) säilyy, vaikka synkka on päällä. Yksi kone
voi olla ainoa, joka puhuu nettiin. Muut koneet ovat sen kanssa samassa
lähiverkossa ja lähettävät muutokset sille. Se kone vie ne JAHOnlineen.

- **Ei tällä koneella** (`siirtotapa=0`): tämä kone ei lue tapahtumalokia.
  Julkaisusynkka (`synkkaa`, `tapahtuma`) toimii silti, kun Yhdistä on onnistunut,
  ja lähiverkko toimii, jos se on määritetty.
- **Tämä kone** (`siirtotapa=1`): tämä kone kirjoittaa ja lukee tapahtumalokia
  lähiverkon lisäksi. Lokista tuleva rivi tallennetaan ja lähtee `laheta`-liikenteenä
  muille lähiverkon koneille. Konsoliohjelmat eivät käytä lokia.

Lähetys (`action=tapahtumat`), lähde on aseman nimi (tai tietokoneen nimi):

```json
{
  "action":"tapahtumat",
  "kilpailu_id":11,
  "lahde":"maali-pc",
  "tapahtumat":[
    {"tyyppi":"kilpailija","numero":101,"vaihe":0,"sukunimi":"Meikäläinen","etunimi":"Matti","keskhyl":"T"},
    {"tyyppi":"status","numero":101,"vaihe":0,"keskhyl":"K"},
    {"tyyppi":"aika","numero":101,"vaihe":0,"piste":0,"aika_tick":2700000},
    {"tyyppi":"sakko","numero":101,"vaihe":0,"asakot":"02"},
    {"tyyppi":"emit","numero":101,"leimat":[{"jarjestys":1,"koodi":31,"aika_sec":184}]}
  ]
}
```

Viestissä `vaihe`-kentän tilalla on `osuus` (1-pohjainen). `aika_tick` on Pirilän
tikki (`SEK = 1000`): henkilökohtaisessa kisassa tuloksen tikit, viestissä
`Maali()`-kellonaika. Viesti lähettää lisäksi `aika_sec` (osuuden juoksuaika
sekunteina). Sivusto tallentaa `aika_sec`:n, jos se on mukana, muuten
`intdiv(aika_tick, 1000)`. Toinen Pirilä-asema soveltaa `aika_tick`-arvon
sellaisenaan.

Haku (`action=tapahtumat_hae`) palauttaa rivit, joiden `id` on suurempi kuin
`jalkeen` ja joiden `lahde` ei ole oma asema:

```json
{"action":"tapahtumat_hae","kilpailu_id":11,"lahde":"maali-pc","jalkeen":0,"raja":100}
```

Vastauksen `tapahtumat[]` on tallennettu JSON plus `id`, `lahde` ja `tyyppi`.
Asema ei saa omia rivejään takaisin. Vastaanotto ylikirjoittaa paikallisen
arvon (ei täytä vain tyhjää). Emit-leimoja ei kirjoiteta takaisin EMIT-tiedostoon;
ne näkyvät sivustolla ja puhelimessa.

Puhelimen sivu `public/mobiili_kilpailija.php` kirjoittaa samaan lokiin lähteellä
`mobiili-<käyttäjä>`. Se kirjaa nimen, statuksen ja ampumasakot. Emit-leimat
näkyvät, mutta puhelin ei lue USB-lukijaa.

## Käyttö (HkKisaWin ja ViestiWin)

1. Valikko → **JAHOnline API (synkka)**
2. Aseta Bridge-URL ja **käyttäjäkohtainen API-avain**
3. **Hae kilpailut** → valitse listasta netissä luotu Pirilä-kilpailu.
   Jos kisaa ei vielä ole netissä, **Luo uusi JAHOnline-kisa** (näkyy, kun
   Hae kilpailut tai Yhdistä on onnistunut) luo sen avatusta kilpailusta.
4. **Yhdistä** (vain kun paikallinen kilpailu on auki — käynnistää taustasynkan).
   Yhdistetyt kisat muistetaan (`jahonline_yhdistetyt.ini` exe-kansiossa).
5. **Lähetä kilpailijat nyt** / **Hae kilpailijat nyt**
6. **Lopeta synkka** pysäyttää taustasäikeen
7. Automaatio: välilehti *Automaatio*. **Tapahtumaloki** on oletuksena
   *Ei tällä koneella*. *Tämä kone* lukee lokin lähiverkon lisäksi.
   Lähiverkkoa ei katkaista. Aseman nimi erottaa lähettäjän.
   **Yhdistä** + OK käynnistää täyssynkan
   (`lahetysvali` = koko listan lähetysväli, oletus 10 s)
8. Osanottajakaavakkeen tallennus / lähtöajan muutos → `synkkaa` noin 200 ms:ssa
   kun synkka on käynnissä
9. Live-väliajat: rastileima ajanotossa → `tapahtuma` heti
10. **Maaliajat ilman numeroa**: Automaatio-välilehden ruksi ja netissä
    kilpailun ruksi *Jaettu ajanottolista*. Tapahtumaloki on päällä.
    Maaliaika lähtee lokiin ilman numeroa. Numero syötetään selaimen
    Numerot-sivulla, ja pari palaa Pirilään sekä tuloksiin.
    Juoksukisa toimii yhä myös ilman tätä ruksia.

Asetukset tallentuvat kilpailun kansioon: `jahonline_api.ini`.
Yhdistetyt kisat, API-avain ja URL tallentuvat lisäksi exe-kansion
`jahonline_yhdistetyt.ini`-tiedostoon. Uusi kilpailukansio saa niistä avaimen
ja siitä kansiosta viimeksi yhdistetyn kisan.
Tiedosto luetaan automaattisesti, kun kilpailu avataan (`Initialisoi`).
Jos kilpailun kansiossa ei ole tiedostoa, vanha exe-kansion `jahonline_api.ini`
kopioidaan sinne ensimmäisellä avauksella.

## Toteutustiedostot

- `TPsource/V52/cbHk/ApiYhteydet.*` — UI + HTTP + ini (yhteinen)
- `TPsource/V52/cbHk/ApiSaike.*` — Hk-synkka
- `TPsource/V52/ViestiWin/ApiSaike.*` — viesti (joukkue + osuus)
- `TPsource/V52/cbHk/ApiJson.*` — kevyt JSON
- `TPsource/V52/cbHk/ApiIntegration.*` / `ApiHkIntegration.h` / `ApiVIntegration.h`
- Valikko: `WinHk.*` / `UnitMain.*` → `JahonlineApi1`
