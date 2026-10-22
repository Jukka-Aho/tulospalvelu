# Pirilä synkka

Kilpailijat, läsnäolo, maaliajat ja **online-rastit** siirtyvät **HkKisaWinistä**
ja **ViestiWinistä** suoraan JAHOnlineen HTTP-APIn kautta.

JAHOnline API on ainoa siirtotapa. Netin asetukset ovat sivulla
**Hallinta → Pirilä-asetukset**.

Lähdekoodi: [jaho81-sys/tulospalvelu](https://github.com/jaho81-sys/tulospalvelu)
(valikko **JAHOnline API (synkka)**).

## Käyttöönotto (HkKisaWin / ViestiWin)

1. Avaa kilpailu HkKisaWinissä tai ViestiWinissä.
2. Valitse **JAHOnline API (synkka)**.
3. Täytä:
   - **Bridge-URL:** `https://jahonline.com/public/api/kilpailijat_bridge.php`
     (oletus)
   - **API-avain:** JAHOnline-käyttäjän `api_token` (Hallinta → käyttäjä)
4. Paina **Hae kilpailut** ja valitse listasta netissä luotu Pirilä-kilpailu.
   Jos kisaa ei vielä ole netissä, paina **Luo uusi JAHOnline-kisa**. Se luo
   kisan avatun kilpailun nimellä, päivämäärällä ja luonteella
   (Pirilän kilpailulaji: suunnistus, hiihto, juoksu, …).
5. Paina **Yhdistä** (paikallisen kilpailun pitää olla auki). Pirilä muistaa
   yhdistetyt kisat, joten seuraavalla kerralla kisa on valmiina listassa.
6. **Lähetä kilpailijat nyt** vie osanottajat (nimet, emit, ajat, lähtöajat,
   läsnäolo, online-väliajat ja Emit-rastiväliajat) Pirilästä JAHOnlineen.
7. **Lopeta synkka** pysäyttää taustasynkan.
8. **Hae kilpailijat nyt** vain jos netistä pitää tuoda päivityksiä takaisin
   `KILP.DAT`:iin. Taustahaku ei ylikirjoita paikallista maaliaikaa, väliaikaa
   eikä lähtöaikaa; se täyttää vain tyhjät kentät. `aika_sec: 0` ei tyhjennä
   tulosta. Viesti luo puuttuvan joukkueen, jos `sarja_nimi` tunnetaan.

Asetukset tallentuvat tiedostoon `jahonline_api.ini` **kilpailun kansioon**.
Tiedosto luetaan, kun kilpailu avataan.

Suunnistuksessa osanottajat elävät Pirilässä (IRMA / CSV / käsin). JAHOnline
näyttää ne synkan jälkeen — erillistä ilmoittautumislomaketta ei käytetä.

## Läsnäolo (emit)

Kun emit-kortti luetaan (leimantarkastus tai `ESILUENTA`-lähtöluenta),
HkKisaWin:

1. yhdistää kortin kilpailijaan
2. merkitsee poissa / ei-lähtenyt / vakantti / ilmoittautunut -osanottajan **läsnäolevaksi**
3. lähettää heti `synkkaa`-sanoman (`lasna: true`, status `LASNA` jos tulosta
   ei vielä ole)

Osanottaja- ja tulosmuutokset (myös lähtöaika) lähtevät tallennuksen yhteydessä
heti, samaan tapaan kuin Pirilän sisäinen tiedonsiirto (`tallenna` → `laheta`).
JAHOnline-lähetys ei vaadi `comfl`-asemaverkkoa: tarvitaan `kilpailu_id` ja
API-avain. Jono lähtee noin 200 ms välein.

Taustasynkka (välilehti *Automaatio*) lähettää lisäksi koko listan valitun välein
(`lahetysvali`, oletus 10 s) kun Yhdistä on onnistunut. **Lopeta synkka** pysäyttää
säikeen. Synkkaa ei käynnistetä, jos kilpailua ei ole avattu.

## API

| | |
|--|--|
| Endpoint | `POST /public/api/kilpailijat_bridge.php` |
| Auth | `Authorization: Bearer <käyttäjän api_token>` tai `X-API-Key` |
| Body | `kilpailut` ilman id:tä; muuten `"kilpailu_id": <int>` |

| Action | Suunta | Käyttö |
|--------|--------|--------|
| `kilpailut` | JAHOnline → Pirilä | Käyttäjän Pirilä-kilpailut valintalistaan |
| `yhdista` | Pirilä → JAHOnline | Yhdistä-painike: kirjaa yhteyden, palauttaa kisan ja luonteen |
| `ping` | — | Yhteystesti (vanha palvelin) |
| `luo_kilpailu` | Pirilä → JAHOnline | Uusi kisa nimellä, päivämäärällä ja luonteella |
| `synkkaa` | Pirilä → JAHOnline | Osanottajat, ajat, lähtöajat, läsnäolo, `valiajat[]` (online + Emit-rastit `rasti_koodi`) |
| `kilpailijat` | JAHOnline → Pirilä | Haku `KILP.DAT`:iin (myös väliajat) |
| `tapahtuma` | live molempiin | Online-rasti heti (`piste`, `aika_sec`, viestissä `osuus`) |
| `tapahtumat` | asema → loki | Kilpailija, status, aika (`aika_tick`), sakko, emit |
| `tapahtumat_hae` | loki → asema | Rivin jälkeen, ei omaa lähdettä |

Synkka ei katkaise lähiverkkoa. Yksi kone voi puhua nettiin, ja muut koneet
ovat sen kanssa samassa lähiverkossa. Tapahtumaloki tällä koneella on lisä:
se tuo puhelimen rivit ja välittää ne lähiverkkoon.
Puhelin: Hallinta → **Kilpailutoiminnat**. Lähtöluenta, nimenhuuto ja
lähtökello ovat kehyksessä **Lähtö** (käyttöoikeus *lähtötoiminnat*).

Kun netissä on ruksi *Jaettu ajanottolista* ja Pirilässä *Maaliajat ilman numeroa*,
maaliaika kirjataan Pirilässä ja kilpailijan numero selaimen Numerot-sivulla.
Ne kohtaavat samassa listassa. Juoksun oma ajanotto (ruudukko, nappi, numerot)
jää käyttöön, ja sen voi yhdistää Pirilään samalla ruksilla.

HkKisaWin: `"tyyppi":"yksilo"`. ViestiWin: `"tyyppi":"viesti"` + `"osuus"`.

Protokolla: [docs/api-jahonline.md](https://github.com/Jukka-Aho/tulospalvelu/blob/main/docs/api-jahonline.md).

## Kaksi “väliaika”-käsitettä (näyttö)

Nämä koskevat sitä, miten JAHOnline **näyttää** synkatut tiedot — ei erillistä
siirto-ohjelmaa.

1. **Online-väliaikapisteet** (radio / maasto) — sarakkeet `1. va`, `2. va`…
   **vain viestin osuuslistassa**. Määrä = `sarjat.valia_lkm` (admin / `tapahtuma`).
   Henkilökohtaisissa **tuloksissa** näytetään vain tulos (aika + ero), ei VA-sarakkeita.
   Wire: JSON-`piste` n = Hk `p_aika(n)` / `va[n+1]` (maali on `p_aika(0)` / piste 0).
2. **Rastiväliajat** (koko Emit-rata) — synkan `valiajat[]` Emit-leimoista
   (`laskeemitvaliajat` / `tee_emva` + `rasti_koodi`) tai online-`va[]`-pisteistä.
   Seuranta → **Rastiväliajat** (viestissä osuusvalinnalla) tai
   `/public/valiajat.php?kilpailu_id=…&numero=…&osuus=…`.
   Erillistä `rastivaliajat[]`-taulukkoa ei ole: emit-rivit ovat samoja
   `valiajat[]`-objekteja kentällä `rasti_koodi`.

| Käsite | JAHOnline |
|--------|-----------|
| Maali / vaihto | `ajat` (viestissä per osuus-kilpailija) |
| Online-väliaika | `valiajat` pisteet `1..valia_lkm` (ilman `rasti_koodi`) |
| Emit-rastit | samat `valiajat`-rivit + `rasti_koodi`; rastiväliajat-näkymä näyttää ne |
| Läsnä / ilmoittautunut / DNS / DNF / DSQ | `lasna` + `status` (`LASNA` / `OK` / `DNS` / `DNF` / `DSQ`; ilmoittautunut `N` kuten avoin `LASNA`, kirjain kentässä `keskhyl`) |
| Lähtöaika | `lahto_aika` / `pirila_lahto_at` / `lahto_sec` → `kilpailijat` + sarjan `lahdot` |
| Maaliaika | `aika_sec` = tuloksen sekunnit (45:00 → 2700), ei Pirilän ms-tikkejä |

## Seuranta / tulokset

- Lista: **maalissa → maastossa → status** (DNF / DNS / DSQ)
- Tulokset: **online-va + maaliaika + ero kärkeen**
- Rastiväliajat: Emit-/API-väliajat (synkan `valiajat[]` + `rasti_koodi`)
- Maastossa: kulunut aika suluissa; ei Emit-rastilistaa

## Pirilä-synkka

Kisan asetuksissa ruksi **Pirilä-synkka** (ent. kilpailutyyppi *Pirila-kisa*).
Kun se on päällä, hallinta näyttää Pirilä-kilpailun: *Sarjat (Pirila)*,
*Radat (Pirila)*, synkka ja muokkaus vain Pirilässä.

Kun ruksi on pois, kisa tehdään JAHOnessa. **Kisan luonne** valitsee valikot
(suunnistuksessa radat ja Emit, juoksussa ja hiihdossa ajanotto).

## Sarjat

Pirilä-kisassa hallinnan valikko on **Sarjat (Pirila)**. Nimi, matka, lähtö ja
järjestys tulevat HkKisaWinin / ViestiWinin synkasta. Niitä ei lisätä, muokata
eikä poisteta JAHOnessa.

Jos Pirilä-synkka ei ole päällä, samalla sivulla voi määritellä sarjat
sovelluksessa.

**Online-va** (netin väliaikasarakkeiden määrä) on JAHOnlinen asetus: synkka
ei ylikirjoita sitä. Pirilä-kisassa sen voi asettaa sarjalistasta.

## Radat

Pirilä-kisassa hallinnan valikko on **Radat (Pirila)**. Radat, rastikoodit,
pituudet ja sarjojen rataliitokset tulevat HkKisaWinin / ViestiWinin
synkasta. Niitä ei muokata JAHOnessa.

Jos Pirilä-synkka ei ole päällä, samalla sivulla voi määritellä radat
sovelluksessa: tunnus, matkat, rastikoodit, sarjat ja se, käynnistyykö
ajanotto nollaleimauksesta vai lähtöajasta. Leimantarkastus laskee tuloksen
tämän valinnan mukaan.

## Kisan luonne

Kisan luonne (`suunnistus`, `hiihto`, `juoksu`, `ampumahiihto`, …) tulee
Pirilän kilpailulajista, kun kisa luodaan Pirilästä. Sen voi muuttaa kisan
tiedoista. Luonne määrää, mitkä valikot näkyvät: esimerkiksi juoksussa
ajanottosivut, suunnistuksessa ratatiedot ja Emit-sivut. Tarkemmin:
[kisan-luonne.md](kisan-luonne.md).

## Online-rastit

Ajanotto ja rastileima lähettävät heti `action=tapahtuma`
(`piste` 0 = maali, ≥ 1 = online). Viestissä mukana `osuus` (1, 2, 3…).
HkKisaWin: JSON-piste n = `va[n+1]` (`p_aika`), ei `va[n]`.
Synkka käyttää kilpailun nykyistä vaihetta (`k_pv`, 0-pohjainen) — ei `k_pv-1`.
Täysi lista kulkee myös `synkkaa`-sanoman `valiajat[]`-kentässä
(online-pisteet ilman `rasti_koodi`, Emit-rastit `rasti_koodi`-kentällä).

JAHOnline-bridgen pitää hyväksyä `action=tapahtuma` ja kirjoittaa
`valiajat` / maaliaika (viestissä myös `osuus`).

## Ei vielä

- Sakot / tarkemmat leimausvirheet UI:ssa
