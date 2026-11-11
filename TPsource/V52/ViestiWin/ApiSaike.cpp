#include <vcl.h>
#pragma hdrstop
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <vector>

#include "ApiSaike.h"
#include "ApiJson.h"
#include "ApiYhteydet.h"
#include "VDeclare.h"

#pragma package(smart_init)

extern CRITICAL_SECTION tall_CriticalSection;

wchar_t ApiPirilaLaji(void)
{
	return kilpparam.kilplaji;
}

wchar_t ApiPirilaAlalaji(void)
{
	return kilpparam.alalaji;
}

UnicodeString ApiPirilaKilpailuNimi(void)
{
	UnicodeString nimi = UnicodeString(kilpailu).Trim();
	if (nimi.IsEmpty())
		nimi = UnicodeString(kilpparam.Name).Trim();
	return nimi;
}

// kilpparam.Date is free text from Kilpailun m‰‰ritykset; send vvvv-kk-pp when it parses.
UnicodeString ApiPirilaPvm(void)
{
	wchar_t pvm[14], st[12];

	wcsncpy(pvm, kilpparam.Date, 13);
	pvm[13] = 0;
	int dn = DateNoFromSt(pvm);
	if (dn <= 0)
		return UnicodeString(pvm).Trim();
	stDateNo(st, dn);
	return UnicodeString(st);
}

int ApiPirilaVaiheet(void)
{
	return 1;
}

static CRITICAL_SECTION lasnaJonoCS;
static bool lasnaJonoAlustettu = false;
static int lasnaJono[256];
static int lasnaJonoN = 0;

typedef struct {
	int numero;
	int osuus;
	int piste;
	int aikaSec;
} ApiTapahtuma;

static CRITICAL_SECTION tapJonoCS;
static bool tapJonoAlustettu = false;
static ApiTapahtuma tapJono[256];
static int tapJonoN = 0;

static void LasnaJonoAlusta(void)
{
	if (!lasnaJonoAlustettu) {
		InitializeCriticalSection(&lasnaJonoCS);
		lasnaJonoAlustettu = true;
	}
}

static void TapJonoAlusta(void)
{
	if (!tapJonoAlustettu) {
		InitializeCriticalSection(&tapJonoCS);
		tapJonoAlustettu = true;
	}
}

static bool ApiAikaOk(INT32 a)
{
	return a != 0 && a != TMAALI0;
}

// JAHOnline synkka: lahto_aika / pirila_lahto_at (kellonaika) + lahto_sec (sekunnit vuorokaudesta).
static UnicodeString ApiLahtoKentat(INT32 tl)
{
	if (tl == TMAALI0)
		return L",\"lahto_aika\":null,\"pirila_lahto_at\":null,\"lahto_sec\":null";
	wchar_t st[24];
	aikatowstr_cols_n(st, tl, t0, 0, 8);
	int sec = (int)(tl / SEK + (INT32)t0 * 3600L);
	sec %= 86400;
	if (sec < 0)
		sec += 86400;
	return UnicodeString(L",\"lahto_aika\":") + ApiJsonString(st)
		+ L",\"pirila_lahto_at\":" + ApiJsonString(st)
		+ L",\"lahto_sec\":" + IntToStr(sec);
}

// JAHOnline aika_sec = whole seconds of race/leg result.
// Piril√§ stores times as ticks (SEK = 1000 ms). Maali() is clock-of-day.
static int ApiTulosSec(INT32 tl)
{
	if (tl <= 0 || tl == TMAALI0)
		return 0;
	return (int)(tl / SEK);
}

static INT32 ApiSecToTicks(__int64 sec)
{
	if (sec <= 0)
		return 0;
	if (sec >= (__int64)100000)
		return (INT32)sec;
	return (INT32)(sec * (__int64)SEK);
}

static bool ApiLueLahtoAika(const UnicodeString& o, INT32& tl)
{
	UnicodeString s;
	if (ApiJsonFindString(o, L"lahto_aika", s) && !s.IsEmpty()) {
		tl = wstrtoaika_vap(s.c_str(), t0);
		return ApiAikaOk(tl);
	}
	if (ApiJsonFindString(o, L"pirila_lahto_at", s) && !s.IsEmpty()) {
		tl = wstrtoaika_vap(s.c_str(), t0);
		return ApiAikaOk(tl);
	}
	__int64 sec = -1;
	if (ApiJsonFindInt64(o, L"lahto_sec", sec) && sec >= 0 && sec < 86400) {
		tl = (INT32)((sec - (INT32)t0 * 3600L) * (__int64)SEK);
		return true;
	}
	return false;
}

static int lasnaJonoPudotettu = 0;
static int tapJonoPudotettu = 0;

static INT32 ApiViestiTulosTicks(kilptietue& kilp, int os, int va)
{
	INT32 ot = kilp.osTulos(os, va);
	if (ot > 0)
		return ot;
	INT32 maali = kilp.Maali(os, va);
	if (!ApiAikaOk(maali))
		return 0;
	INT32 lahto = kilp.Lahto(os);
	if (lahto != TMAALI0)
		return NORMTULOS(maali - lahto);
	if (kilp.sarja >= 0 && kilp.sarja < sarjaluku)
		return NORMTULOS(maali - Sarjat[kilp.sarja].lahto);
	return 0;
}

static INT32 ApiViestiInboundMaali(kilptietue& kilp, int os, __int64 sec)
{
	if (sec <= 0)
		return TMAALI0;
	INT32 tulosTicks = ApiSecToTicks(sec);
	if (sec >= (__int64)100000)
		return tulosTicks;
	INT32 lahto = kilp.Lahto(os);
	if (lahto == TMAALI0)
		return tulosTicks;
	return lahto + tulosTicks;
}

static int ApiViestiTapahtumaSec(int kilpno, int osuus, int piste, INT32 maaliTicks)
{
	if (!ApiAikaOk(maaliTicks))
		return 0;
	int d = getpos(kilpno);
	if (d > 0) {
		kilptietue kilp;
		kilp.getrec(d);
		INT32 ot = kilp.osTulos(osuus, piste);
		if (ot > 0)
			return ApiTulosSec(ot);
		INT32 lahto = kilp.Lahto(osuus);
		if (lahto != TMAALI0)
			return ApiTulosSec(NORMTULOS(maaliTicks - lahto));
		if (kilp.sarja >= 0 && kilp.sarja < sarjaluku)
			return ApiTulosSec(NORMTULOS(maaliTicks - Sarjat[kilp.sarja].lahto));
	}
	return ApiTulosSec(maaliTicks);
}

static void ApiSovellaTapahtumaJson(const UnicodeString& tapahtumaJson);

__fastcall TApiSaike::TApiSaike(bool CreateSuspended)
	: TThread(CreateSuspended), pysaytysPyynnon(false), viiveMs(10000)
{
	FreeOnTerminate = false;
	ApiAsetaTapahtumaSovella(ApiSovellaTapahtumaJson);
}

__fastcall TApiSaike::~TApiSaike(void)
{
	pysaytysPyynnon = true;
}

void TApiSaike::PyynnoPysaytys(void)
{
	pysaytysPyynnon = true;
}

void __fastcall TApiSaike::LisaaMemo(void)
{
	if (FormApiYhteydet && FormApiYhteydet->MemoTila)
		FormApiYhteydet->MemoTila->Lines->Add(viestiJono);
}

void __fastcall TApiSaike::Paivita(const UnicodeString msg, bool virhe)
{
	viestiJono = (virhe ? UnicodeString(L"! ") : UnicodeString(L"")) + msg;
	Synchronize(&LisaaMemo);
}

static UnicodeString StatusMerkki(wchar_t keskhyl, bool onLasna, bool onTulos)
{
	switch (keskhyl) {
	case L'T': return L"OK";
	case L'H': return L"DSQ";
	case L'K': return L"DNF";
	case L'E':
	case L'P':
	case L'V': return L"DNS";
	case L'N': return onTulos ? L"OK" : L"LASNA";
	default:
		break;
	}
	if (onLasna && !onTulos)
		return L"LASNA";
	if (onLasna)
		return L"OK";
	return L"DNS";
}

static wchar_t StatusMerkkiin(const UnicodeString& st)
{
	if (st.CompareIC(L"ILMOITTAUTUNUT") == 0 || st.CompareIC(L"REGISTERED") == 0)
		return L'N';
	if (st.CompareIC(L"DNS") == 0 || st.CompareIC(L"EI_LAHTENYT") == 0)
		return L'E';
	if (st.CompareIC(L"DNF") == 0 || st.CompareIC(L"KESKEYTTI") == 0)
		return L'K';
	if (st.CompareIC(L"DSQ") == 0 || st.CompareIC(L"HYLATTY") == 0)
		return L'H';
	return L' ';
}

// Start-gate emit on the web marks ilmoittautunut (N) as lasna (-).
// Do not convert on status LASNA alone: outbound synkkaa publishes N as LASNA,
// and a fetch without keskhyl would otherwise wipe N on every restart.
static void ApiIlmoittautunutEmitLasna(kilptietue& kilp, int os,
	bool kesAnnettu, const UnicodeString& keskhylIn, bool onEmitTieto)
{
	if (kilp.wTark(os) != L'N')
		return;
	if (onEmitTieto) {
		kilp.SetTark(os, L'-');
		return;
	}
	if (kesAnnettu && keskhylIn.Length() > 0 && keskhylIn[1] == L'-')
		kilp.SetTark(os, L'-');
}

// JAHOnline emit course legs: same valiajat[] as online splits, plus rasti_koodi.
// Rows without rasti_koodi update sarjat.valia_lkm; rows with rasti_koodi
// feed /public/valiajat.php. Viesti tee_emva AVs if rata is NULL, so check first.
static void ApiValiajatLisaaEmitRivi(UnicodeString& valia, bool& vfirst,
	int piste, int sec, int koodi, int vali)
{
	if (koodi <= 0 && sec <= 0)
		return;
	if (!vfirst)
		valia += L",";
	vfirst = false;
	valia += L"{\"piste\":" + IntToStr(piste)
		+ L",\"aika_sec\":" + IntToStr(sec)
		+ L",\"rasti_koodi\":" + IntToStr(koodi);
	if (vali > 0)
		valia += L",\"vali_sec\":" + IntToStr(vali);
	valia += L"}";
}

static void ApiLisaaEmitValiajat(kilptietue& kilp, int os, UnicodeString& valia, bool& vfirst)
{
	if (!emitfl || os < 0)
		return;
	emittp em;
	if (getem(&em, kilp.KilpNo(), os) < 0 || em.kilpno <= 0)
		return;
	em.osuus = (INT16)os;

	if (emitanal_fl) {
		emitvaanaltp *eva = 0;
		int nva = 0;
		if (laskeemitvaliajat(&em, &kilp, &eva, 0, &nva) == 0 && eva && nva > 0) {
			if (nva > MAXNRASTI)
				nva = MAXNRASTI;
			for (int i = 0; i < nva; i++)
				ApiValiajatLisaaEmitRivi(valia, vfirst, i + 1,
					eva[i].aika, eva[i].rkoodi, eva[i].rvaika);
			free(eva);
			return;
		}
		if (eva)
			free(eva);
	}

	ratatp *rt = haerata(&kilp, os);
	if (!rt)
		return;
	emitvatp emva;
	if (tee_emva(&emva, &em) != 0)
		return;
	int n = emva.rastiluku;
	if (n < 0)
		n = 0;
	if (n > MAXEMVALAH)
		n = MAXEMVALAH;
	int prev = 0;
	for (int i = 0; i < n; i++) {
		int koodi = (int)emva.rastit[i][0];
		int sec = (int)emva.rastit[i][1];
		if (koodi <= 0 && i < MAXNRASTI)
			koodi = rt->rastikoodi[i];
		int vali = 0;
		if (sec > 0) {
			vali = sec - prev;
			if (vali < 0)
				vali = 0;
			prev = sec;
		}
		ApiValiajatLisaaEmitRivi(valia, vfirst, i + 1, sec, koodi, vali);
	}
}

static UnicodeString ApiOsuusObj(kilptietue& kilp, int os)
{
	int numero = kilp.KilpNo();
	UnicodeString sarjaNimi = L"";
	if (kilp.sarja >= 0 && kilp.sarja < sarjaluku)
		sarjaNimi = UnicodeString(Sarjat[kilp.sarja].sarjanimi);

	int nva = 0;
	if (kilp.sarja >= 0 && kilp.sarja < sarjaluku && os >= 0 && os < Sarjat[kilp.sarja].osuusluku)
		nva = Sarjat[kilp.sarja].valuku[os];
	if (nva < 0) nva = 0;
	if (nva > VAIKALUKU) nva = VAIKALUKU;

	wchar_t suku[80], etu[80];
	kilp.SukuNimi(suku, 79, os);
	kilp.EtuNimi(etu, 79, os);

	INT32 maaliKello = kilp.Maali(os, 0);
	INT32 tls = ApiViestiTulosTicks(kilp, os, 0);
	wchar_t tark = kilp.wTark(os);
	bool onLasna = kilp.osHyv(os) || tark == L'-' || tark == L'T';
	if (tark == L'P' || tark == L'E' || tark == L'V' || tark == L'B')
		onLasna = false;

	UnicodeString valia = L"[";
	bool vfirst = true;
	for (int p = 1; p <= nva; p++) {
		INT32 va = ApiViestiTulosTicks(kilp, os, p);
		int vsec = ApiTulosSec(va);
		if (vsec <= 0)
			continue;
		if (!vfirst) valia += L",";
		vfirst = false;
		valia += L"{\"piste\":" + IntToStr(p)
			+ L",\"aika_sec\":" + IntToStr(vsec)
			+ L",\"sija\":" + IntToStr((int)kilp.Sija(os, p))
			+ L"}";
	}
	ApiLisaaEmitValiajat(kilp, os, valia, vfirst);
	valia += L"]";

	int badge = (int)kilp.ostiet[os].badge[0];
	int badge2 = (int)kilp.ostiet[os].badge[1];

	UnicodeString arr = L"{";
	arr += L"\"numero\":" + IntToStr(numero);
	arr += L",\"osuus\":" + IntToStr(os + 1);
	arr += L",\"joukkue\":" + IntToStr((int)kilp.joukkue);
	arr += L",\"sukunimi\":" + ApiJsonString(suku);
	arr += L",\"etunimi\":" + ApiJsonString(etu);
	arr += L",\"nimi\":" + ApiJsonString(UnicodeString(suku) + L" " + UnicodeString(etu));
	arr += L",\"seura\":" + ApiJsonString(UnicodeString(kilp.seura));
	arr += L",\"maa\":" + ApiJsonString(UnicodeString(kilp.maa));
	arr += L",\"sarja_nimi\":" + ApiJsonString(sarjaNimi);
	arr += L",\"badge\":" + IntToStr(badge);
	arr += L",\"badge2\":" + IntToStr(badge2);
	arr += L",\"emit_koodi\":" + IntToStr(badge);
	arr += L",\"lasna\":" + ApiJsonBool(onLasna);
	arr += L",\"status\":" + ApiJsonString(StatusMerkki(tark, onLasna, ApiAikaOk(maaliKello)));
	arr += L",\"keskhyl\":" + ApiJsonString(UnicodeString(tark));
	if (apiconfig.lahetaTulokset) {
		int tsec = ApiTulosSec(tls);
		if (tsec > 0)
			arr += L",\"aika_sec\":" + IntToStr(tsec);
		else
			arr += L",\"aika_sec\":null";
		INT16 sija = kilp.Sija(os, 0);
		if (sija > 0)
			arr += L",\"sija\":" + IntToStr((int)sija);
		else
			arr += L",\"sija\":null";
		arr += L",\"valiajat\":" + valia;
	} else {
		arr += L",\"aika_sec\":null,\"sija\":null,\"valiajat\":[]";
	}
	arr += ApiLahtoKentat(kilp.Lahto(os));
	arr += L",\"tyyppi\":\"viesti\"";
	arr += L"}";
	return arr;
}

UnicodeString ApiRakennaKilpailijatJson(void)
{
	UnicodeString arr = L"[";
	bool first = true;

	if (datf2.numrec < 2)
		return UnicodeString(L"[]");

	for (int d = 1; d < datf2.numrec; d++) {
		kilptietue kilp;
		kilp.getrec(d);
		if (kilp.kilpstatus != 0)
			continue;
		int numero = kilp.KilpNo();
		if (numero <= 0)
			continue;
		if (kilp.sarja < 0 || kilp.sarja >= sarjaluku)
			continue;
		int nos = Sarjat[kilp.sarja].osuusluku;
		if (nos < 1) nos = 1;
		if (nos > MAXOSUUSLUKU) nos = MAXOSUUSLUKU;
		for (int os = 0; os < nos; os++) {
			if (!first) arr += L",";
			first = false;
			arr += ApiOsuusObj(kilp, os);
		}
	}
	arr += L"]";
	return arr;
}

int ApiSovellaKilpailijatJson(const UnicodeString& json, bool korvaaKentat)
{
	std::vector<UnicodeString> objs;
	int n = ApiJsonExtractObjectArray(json, L"kilpailijat", objs);
	if (n <= 0)
		return 0;

	bool haeKilp = apiconfig.vastaanottaKilpailijat != 0;
	bool haeVa = apiconfig.vastaanottaValiajat != 0;
	bool haeDns = apiconfig.vastaanottaEiLahteneet != 0;
	if (!haeKilp && !haeVa && !haeDns)
		return 0;

	int updated = 0;
	EnterCriticalSection(&tall_CriticalSection);
	__try {
		for (size_t i = 0; i < objs.size(); i++) {
			const UnicodeString& o = objs[i];
			int numero = 0, osuus1 = 1;
			if (!ApiJsonFindInt(o, L"numero", numero) || numero <= 0)
				continue;
			if (!ApiJsonFindInt(o, L"osuus", osuus1) || osuus1 < 1)
				osuus1 = 1;
			int os = osuus1 - 1;

			UnicodeString sukunimi, etunimi, seura, maa, sarjaNimi, status;
			ApiJsonFindString(o, L"sukunimi", sukunimi);
			ApiJsonFindString(o, L"etunimi", etunimi);
			ApiJsonFindString(o, L"seura", seura);
			ApiJsonFindString(o, L"maa", maa);
			ApiJsonFindString(o, L"sarja_nimi", sarjaNimi);
			ApiJsonFindString(o, L"status", status);

			bool lasnaFlag = false;
			bool lasnaAnnettu = ApiJsonFindBool(o, L"lasna", lasnaFlag);

			int badge = 0, badge2 = 0, sija = -1;
			ApiJsonFindInt(o, L"badge", badge);
			if (badge <= 0)
				ApiJsonFindInt(o, L"emit_koodi", badge);
			ApiJsonFindInt(o, L"badge2", badge2);
			__int64 a64 = 0;
			int aikaSec = -1;
			if (ApiJsonFindInt64(o, L"aika_sec", a64))
				aikaSec = (int)a64;
			ApiJsonFindInt(o, L"sija", sija);

			int d = getpos(numero);
			kilptietue kilp;
			bool uusi = false;
			if (d > 0) {
				kilp.getrec(d);
			} else if (!haeKilp) {
				continue;
			} else {
				int srj = -1;
				if (!sarjaNimi.IsEmpty()) {
					for (int s = 0; s < sarjaluku; s++) {
						if (sarjaNimi.CompareIC(UnicodeString(Sarjat[s].sarjanimi)) == 0) {
							srj = s;
							break;
						}
					}
				}
				if (srj < 0)
					continue;
				kilp.nollaa();
				kilp.setKilpNo(numero);
				kilp.sarja = srj;
				uusi = true;
			}
			if (kilp.sarja < 0 || kilp.sarja >= sarjaluku)
				continue;
			if (os < 0 || os >= Sarjat[kilp.sarja].osuusluku)
				continue;

			bool muuttui = uusi;
			wchar_t suku0[80], etu0[80];
			kilp.SukuNimi(suku0, 79, os);
			kilp.EtuNimi(etu0, 79, os);

			if (haeKilp) {
				if (!sukunimi.IsEmpty() && (korvaaKentat || suku0[0] == 0)) {
					kilp.setSukuNimi(sukunimi.c_str(), os);
					muuttui = true;
				}
				if (!etunimi.IsEmpty() && (korvaaKentat || etu0[0] == 0)) {
					kilp.setEtuNimi(etunimi.c_str(), os);
					muuttui = true;
				}
				if (!seura.IsEmpty() && (korvaaKentat || kilp.seura[0] == 0)) {
					kilp.setSeura(seura.c_str());
					muuttui = true;
				}
				if (!maa.IsEmpty() && (korvaaKentat || kilp.maa[0] == 0)) {
					kilp.setMaa(maa.c_str());
					muuttui = true;
				}
				if (!sarjaNimi.IsEmpty()) {
					for (int s = 0; s < sarjaluku; s++) {
						if (sarjaNimi.CompareIC(UnicodeString(Sarjat[s].sarjanimi)) == 0) {
							if (kilp.sarja != s) {
								kilp.sarja = s;
								muuttui = true;
							}
							break;
						}
					}
				}
				if (badge > 0 && (korvaaKentat || kilp.ostiet[os].badge[0] == 0)) {
					if (kilp.ostiet[os].badge[0] != badge) {
						kilp.ostiet[os].badge[0] = badge;
						muuttui = true;
					}
				}
				if (badge2 > 0 && (korvaaKentat || kilp.ostiet[os].badge[1] == 0)) {
					if (kilp.ostiet[os].badge[1] != badge2) {
						kilp.ostiet[os].badge[1] = badge2;
						muuttui = true;
					}
				}
			}
			{
				UnicodeString kesKirjain;
				bool kesKirjainAnnettu = ApiJsonFindString(o, L"keskhyl", kesKirjain);
				if (kesKirjainAnnettu && kesKirjain.Length() > 0) {
					wchar_t ch = kesKirjain[1];
					if (ch != 0 && kilp.wTark(os) != ch) {
						kilp.SetTark(os, ch);
						muuttui = true;
					}
				} else if (haeDns && !status.IsEmpty()) {
					wchar_t m = StatusMerkkiin(status);
					wchar_t t = kilp.wTark(os);
					bool onTulos = ApiAikaOk(kilp.Maali(os, 0));
					if (m != L' ' && !(t == L'N' && m == L'-') && t != m) {
						if (!(m == L'E' && onTulos)) {
							kilp.SetTark(os, m);
							muuttui = true;
						}
					}
				}
			}
			if (haeKilp && lasnaAnnettu && lasnaFlag) {
				wchar_t t = kilp.wTark(os);
				if (t == L'E' || t == L'P' || t == L'V' || t == L'B') {
					kilp.SetTark(os, L'-');
					muuttui = true;
				}
			}
			if (aikaSec > 0 && (haeKilp || haeVa)) {
				if (korvaaKentat || !ApiAikaOk(kilp.Maali(os, 0))) {
					kilp.setMaali(os, 0, ApiViestiInboundMaali(kilp, os, aikaSec));
					muuttui = true;
				}
			}
			if (haeKilp) {
				INT32 lahtoTl = 0;
				if (ApiLueLahtoAika(o, lahtoTl)) {
					if (korvaaKentat || !ApiAikaOk(kilp.ostiet[os].ylahto)) {
						kilp.ostiet[os].ylahto = lahtoTl;
						muuttui = true;
					}
				}
			}
			if (sija > 0 && haeKilp && (korvaaKentat || kilp.Sija(os, 0) <= 0)) {
				if (kilp.Sija(os, 0) != sija) {
					kilp.setSija(os, 0, sija);
					muuttui = true;
				}
			}
			bool onEmitTieto = (aikaSec > 0);
			if (haeVa || haeKilp) {
				std::vector<UnicodeString> vas;
				if (ApiJsonExtractObjectArray(o, L"valiajat", vas) > 0) {
					for (size_t v = 0; v < vas.size(); v++) {
						int piste = 0, vasija = -1;
						__int64 va64 = 0;
						if (!ApiJsonFindInt(vas[v], L"piste", piste) || piste < 1)
							continue;
						{
							int rkoodi = 0;
							if (ApiJsonFindInt(vas[v], L"rasti_koodi", rkoodi) && rkoodi > 0)
								continue;
						}
						if (ApiJsonFindInt64(vas[v], L"aika_sec", va64) && va64 > 0) {
							onEmitTieto = true;
							if (korvaaKentat || !ApiAikaOk(kilp.Maali(os, piste))) {
								kilp.setMaali(os, piste, ApiViestiInboundMaali(kilp, os, va64));
								muuttui = true;
							}
						}
						if (ApiJsonFindInt(vas[v], L"sija", vasija) && vasija > 0) {
							if (korvaaKentat || kilp.Sija(os, piste) <= 0) {
								kilp.setSija(os, piste, vasija);
								muuttui = true;
							}
						}
					}
				}
			}
			{
				UnicodeString kesIn;
				bool kesAnnettu = ApiJsonFindString(o, L"keskhyl", kesIn);
				wchar_t ennen = kilp.wTark(os);
				ApiIlmoittautunutEmitLasna(kilp, os, kesAnnettu, kesIn, onEmitTieto);
				if (kilp.wTark(os) != ennen)
					muuttui = true;
			}

			if (!muuttui)
				continue;
			if (uusi) {
				int nd = 0;
				addtall(&kilp, &nd, 0);
			} else {
				tallenna(&kilp, d, 0, 0, 0, 0);
			}
			updated++;
		}
	} __finally {
		LeaveCriticalSection(&tall_CriticalSection);
	}
	return updated;
}

int ApiSynkkaaLahetaKaikki(void)
{
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;

	UnicodeString kilpailijat = ApiRakennaKilpailijatJson();
	UnicodeString body =
		L"{\"action\":\"synkkaa\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":\"ViestiWin\""
		+ L",\"tyyppi\":\"viesti\""
		+ L",\"luo_puuttuvat\":true"
		+ L",\"kilpailijat\":" + kilpailijat
		+ L"}";

	UnicodeString vastaus;
	if (!ApiHttpPostJson(ApiBridgeUrl(), body, vastaus))
		return -2;
	if (!ApiJsonStatusOk(vastaus))
		return -3;

	std::vector<UnicodeString> objs;
	return ApiJsonExtractObjectArray(L"{\"kilpailijat\":" + kilpailijat + L"}", L"kilpailijat", objs);
}

int ApiSynkkaaHaeKaikki(bool korvaaKentat)
{
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;

	UnicodeString body =
		L"{\"action\":\"kilpailijat\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"tyyppi\":\"viesti\"}";
	UnicodeString vastaus;
	if (!ApiHttpPostJson(ApiBridgeUrl(), body, vastaus))
		return -2;
	if (!ApiJsonStatusOk(vastaus))
		return -3;
	return ApiSovellaKilpailijatJson(vastaus, korvaaKentat);
}

static void ApiJonotaViesti(int kilpno)
{
	int d = getpos(kilpno);
	if (d <= 0)
		return;
	kilptietue kilp;
	kilp.getrec(d);
	int nosa = 1;
	if (kilp.sarja >= 0 && kilp.sarja < sarjaluku && Sarjat[kilp.sarja].osuusluku > 0)
		nosa = Sarjat[kilp.sarja].osuusluku;
	UnicodeString sarjaNimi;
	if (kilp.sarja >= 0 && kilp.sarja < sarjaluku)
		sarjaNimi = Sarjat[kilp.sarja].sarjanimi;
	for (int os = 0; os < nosa; os++) {
		wchar_t suku[80], etu[80];
		suku[0] = 0;
		etu[0] = 0;
		kilp.SukuNimi(suku, 79, os);
		kilp.EtuNimi(etu, 79, os);
		int badge = kilp.ostiet[os].badge[0];
		ApiJonotaTapahtuma(
			L"{\"tyyppi\":\"kilpailija\",\"numero\":" + IntToStr(kilpno)
			+ L",\"osuus\":" + IntToStr(os + 1)
			+ L",\"tyyppi_kisa\":\"viesti\""
			+ L",\"sukunimi\":" + ApiJsonString(suku)
			+ L",\"etunimi\":" + ApiJsonString(etu)
			+ L",\"seura\":" + ApiJsonString(kilp.seura)
			+ L",\"sarja_nimi\":" + ApiJsonString(sarjaNimi)
			+ L",\"badge\":" + IntToStr(badge)
			+ L",\"keskhyl\":" + ApiJsonString(UnicodeString(kilp.wTark(os)))
			+ L"}");
		ApiJonotaTapahtuma(
			L"{\"tyyppi\":\"status\",\"numero\":" + IntToStr(kilpno)
			+ L",\"osuus\":" + IntToStr(os + 1)
			+ L",\"keskhyl\":" + ApiJsonString(UnicodeString(kilp.wTark(os)))
			+ L"}");
	}
}

void ApiIlmoitaLasna(int kilpno)
{
	if (kilpno <= 0 || ApiSovellusKaynnissa())
		return;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return;
	if (ApiSiirtoOnJahonline())
		ApiJonotaViesti(kilpno);
	LasnaJonoAlusta();
	EnterCriticalSection(&lasnaJonoCS);
	{
		bool jo = false;
		for (int i = 0; i < lasnaJonoN; i++) {
			if (lasnaJono[i] == kilpno) {
				jo = true;
				break;
			}
		}
		if (!jo) {
			if (lasnaJonoN < (int)(sizeof(lasnaJono) / sizeof(lasnaJono[0])))
				lasnaJono[lasnaJonoN++] = kilpno;
			else
				lasnaJonoPudotettu++;
		}
	}
	LeaveCriticalSection(&lasnaJonoCS);
}

void ApiIlmoitaTapahtuma(int kilpno, int osuus, int piste, int aikaSec)
{
	if (kilpno <= 0 || ApiSovellusKaynnissa())
		return;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return;
	if (piste == 0 && apiconfig.numerotSelaimesta) {
		if (ApiSiirtoOnJahonline() && aikaSec > 0 && aikaSec != (int)TMAALI0) {
			UnicodeString avain = apiconfig.asema;
			if (avain.IsEmpty())
				avain = L"pirila";
			avain += L"-" + IntToStr(kilpno) + L"-" + IntToStr(osuus) + L"-" + IntToStr(aikaSec);
			ApiJonotaTapahtuma(
				L"{\"tyyppi\":\"leima\",\"aika_tick\":" + IntToStr(aikaSec)
				+ L",\"avain\":" + ApiJsonString(avain)
				+ L"}");
		}
		return;
	}
	if (ApiSiirtoOnJahonline() && aikaSec > 0 && aikaSec != (int)TMAALI0) {
		int tulosSec = ApiViestiTapahtumaSec(kilpno, osuus, piste, (INT32)aikaSec);
		UnicodeString obj =
			L"{\"tyyppi\":\"aika\",\"numero\":" + IntToStr(kilpno)
			+ L",\"osuus\":" + IntToStr(osuus + 1)
			+ L",\"piste\":" + IntToStr(piste)
			+ L",\"aika_tick\":" + IntToStr(aikaSec);
		if (tulosSec > 0)
			obj += L",\"aika_sec\":" + IntToStr(tulosSec);
		obj += L"}";
		ApiJonotaTapahtuma(obj);
	}
	if (!apiconfig.lahetaValiajat && piste != 0)
		return;
	int tsec = ApiViestiTapahtumaSec(kilpno, osuus, piste, (INT32)aikaSec);
	if (tsec <= 0)
		return;

	TapJonoAlusta();
	EnterCriticalSection(&tapJonoCS);
	if (tapJonoN < (int)(sizeof(tapJono) / sizeof(tapJono[0]))) {
		ApiTapahtuma t;
		t.numero = kilpno;
		t.osuus = osuus;
		t.piste = piste;
		t.aikaSec = tsec;
		tapJono[tapJonoN++] = t;
	} else
		tapJonoPudotettu++;
	LeaveCriticalSection(&tapJonoCS);
}

// C wrappers for vdat.cpp (same signatures as VDeclare.h; console has empty stubs)
void api_ilmoita_kilpailija(int kilpno)
{
	ApiIlmoitaLasna(kilpno);
}

void api_ilmoita_tapahtuma(int kilpno, int osuus, int piste, int aikaSec)
{
	ApiIlmoitaTapahtuma(kilpno, osuus, piste, aikaSec);
}

static int ApiLahetaTapahtumat(const ApiTapahtuma* ev, int n)
{
	if (n <= 0)
		return 0;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;

	UnicodeString arr = L"[";
	for (int i = 0; i < n; i++) {
		if (i)
			arr += L",";
		arr += L"{\"numero\":" + IntToStr(ev[i].numero)
			+ L",\"osuus\":" + IntToStr(ev[i].osuus + 1)
			+ L",\"piste\":" + IntToStr(ev[i].piste)
			+ L",\"aika_sec\":" + IntToStr(ev[i].aikaSec)
			+ L",\"lahde\":\"online\"}";
	}
	arr += L"]";

	UnicodeString body =
		L"{\"action\":\"tapahtuma\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":\"ViestiWin\""
		+ L",\"tyyppi\":\"viesti\""
		+ L",\"tapahtumat\":" + arr
		+ L"}";
	UnicodeString vastaus;
	if (!ApiHttpPostJson(ApiBridgeUrl(), body, vastaus))
		return -2;
	if (!ApiJsonStatusOk(vastaus))
		return -3;
	return n;
}

int ApiLahetaTapahtumatNyt(void)
{
	ApiTapahtuma ev[256];
	int n = 0;
	if (!tapJonoAlustettu)
		return 0;
	EnterCriticalSection(&tapJonoCS);
	n = tapJonoN;
	if (n > 0) {
		memcpy(ev, tapJono, n * sizeof(ApiTapahtuma));
		tapJonoN = 0;
	}
	LeaveCriticalSection(&tapJonoCS);
	return ApiLahetaTapahtumat(ev, n);
}

static int ApiSynkkaaJonosta(void)
{
	int nums[256];
	int n = 0;
	if (!lasnaJonoAlustettu)
		return 0;
	EnterCriticalSection(&lasnaJonoCS);
	n = lasnaJonoN;
	if (n > 0) {
		memcpy(nums, lasnaJono, n * sizeof(int));
		lasnaJonoN = 0;
	}
	LeaveCriticalSection(&lasnaJonoCS);
	if (n <= 0)
		return 0;
	if (datf2.numrec < 2)
		return 0;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;

	UnicodeString arr = L"[";
	bool first = true;
	for (int i = 0; i < n; i++) {
		int d = getpos(nums[i]);
		if (d <= 0)
			continue;
		kilptietue kilp;
		kilp.getrec(d);
		if (kilp.kilpstatus != 0 || kilp.KilpNo() <= 0)
			continue;
		if (kilp.sarja < 0 || kilp.sarja >= sarjaluku)
			continue;
		int nos = Sarjat[kilp.sarja].osuusluku;
		if (nos < 1) nos = 1;
		for (int os = 0; os < nos; os++) {
			if (!first)
				arr += L",";
			first = false;
			arr += ApiOsuusObj(kilp, os);
		}
	}
	arr += L"]";
	if (first)
		return 0;

	UnicodeString body =
		L"{\"action\":\"synkkaa\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":\"ViestiWin\""
		+ L",\"tyyppi\":\"viesti\""
		+ L",\"luo_puuttuvat\":true"
		+ L",\"kilpailijat\":" + arr
		+ L"}";
	UnicodeString vastaus;
	if (!ApiHttpPostJson(ApiBridgeUrl(), body, vastaus))
		return -2;
	if (!ApiJsonStatusOk(vastaus))
		return -3;
	return n;
}

void __fastcall TApiSaike::Kasittele(void)
{
	if (!apiconfig.kaynnissa)
		return;
	if (datf2.numrec < 2)
		return;

	if (lasnaJonoPudotettu || tapJonoPudotettu) {
		int lp = lasnaJonoPudotettu;
		int tp = tapJonoPudotettu;
		lasnaJonoPudotettu = 0;
		tapJonoPudotettu = 0;
		Paivita(L"Jono taynna, pudotettu kilpailijoita " + IntToStr(lp)
			+ L", tapahtumia " + IntToStr(tp), true);
	}

	ApiSynkkaaJonosta();
	int nt = ApiLahetaTapahtumatNyt();
	if (apiconfig.lahetaValiajat) {
		if (nt > 0)
			Paivita(L"L‰hetetty online-rasteja: " + IntToStr(nt));
		else if (nt < 0)
			Paivita(L"Online-rastien l‰hetys ep‰onnistui (" + IntToStr(nt) + L")", true);
	}

	if (apiconfig.lahetaKilpailijat || apiconfig.lahetaTulokset || apiconfig.lahetaValiajat) {
		int n = ApiSynkkaaLahetaKaikki();
		if (n >= 0)
			Paivita(L"L‰hetetty osuuksia: " + IntToStr(n));
		else
			Paivita(L"L‰hetys ep‰onnistui (" + IntToStr(n) + L")", true);
	}

	if (apiconfig.vastaanottaKilpailijat || apiconfig.vastaanottaValiajat) {
		int n = ApiSynkkaaHaeKaikki(false);
		if (n >= 0)
			Paivita(L"Haettu/p‰ivitetty: " + IntToStr(n));
		else
			Paivita(L"Haku ep‰onnistui (" + IntToStr(n) + L")", true);
	}
}

void __fastcall TApiSaike::Execute(void)
{
	while (!pysaytysPyynnon) {
		viiveMs = apiconfig.lahetysvali * 1000;
		if (viiveMs < 2000)
			viiveMs = 2000;
		if (apiconfig.kaynnissa)
			Kasittele();
		for (int t = 0; t < viiveMs && !pysaytysPyynnon; t += 200) {
			Sleep(200);
			if (!pysaytysPyynnon && ApiSiirtoOnJahonline()) {
				ApiLahetaTapahtumaJono();
				int pud = ApiTapahtumaPudotettuOta();
				if (pud > 0)
					Paivita(L"Jono taynna, pudotettu tapahtumia " + IntToStr(pud), true);
				ApiHaeTapahtumat();
			}
			if (!pysaytysPyynnon && apiconfig.kaynnissa) {
				ApiSynkkaaJonosta();
				ApiLahetaTapahtumatNyt();
			}
		}
	}
}

static void ApiSovellaTapahtumaJson(const UnicodeString& o)
{
	UnicodeString tyyppi;
	if (!ApiJsonFindString(o, L"tyyppi", tyyppi) || tyyppi == L"emit" || tyyppi == L"sakko")
		return;
	int numero = 0;
	if (!ApiJsonFindInt(o, L"numero", numero) || numero <= 0)
		return;
	int osuus = 1;
	if (!ApiJsonFindInt(o, L"osuus", osuus) || osuus < 1)
		osuus = 1;
	int os = osuus - 1;

	int d = getpos(numero);
	if (d <= 0)
		return;

	EnterCriticalSection(&tall_CriticalSection);
	ApiSovellusAlkaa();
	__try {
		kilptietue kilp;
		kilp.getrec(d);
		bool muuttui = false;
		bool kelpoOs = kilp.sarja >= 0 && kilp.sarja < sarjaluku
			&& os >= 0 && os < Sarjat[kilp.sarja].osuusluku;
		if (!kelpoOs) {
			muuttui = false;
		} else if (tyyppi == L"kilpailija") {
			UnicodeString sukunimi, etunimi, seura, maa, sarjaNimi;
			ApiJsonFindString(o, L"sukunimi", sukunimi);
			ApiJsonFindString(o, L"etunimi", etunimi);
			ApiJsonFindString(o, L"seura", seura);
			ApiJsonFindString(o, L"maa", maa);
			ApiJsonFindString(o, L"sarja_nimi", sarjaNimi);
			if (!sukunimi.IsEmpty()) {
				kilp.setSukuNimi(sukunimi.c_str(), os);
				muuttui = true;
			}
			if (!etunimi.IsEmpty()) {
				kilp.setEtuNimi(etunimi.c_str(), os);
				muuttui = true;
			}
			if (!seura.IsEmpty()) {
				kilp.setSeura(seura.c_str());
				muuttui = true;
			}
			if (!maa.IsEmpty()) {
				kilp.setMaa(maa.c_str());
				muuttui = true;
			}
			if (!sarjaNimi.IsEmpty()) {
				for (int s = 0; s < sarjaluku; s++) {
					if (sarjaNimi.CompareIC(UnicodeString(Sarjat[s].sarjanimi)) == 0 && kilp.sarja != s) {
						kilp.sarja = s;
						muuttui = true;
						break;
					}
				}
			}
			int badge = 0;
			if (ApiJsonFindInt(o, L"badge", badge) && badge > 0 && kilp.ostiet[os].badge[0] != badge) {
				kilp.ostiet[os].badge[0] = badge;
				muuttui = true;
			}
			UnicodeString kesKuva;
			if (ApiJsonFindString(o, L"keskhyl", kesKuva) && kesKuva.Length() > 0 && kilp.wTark(os) != kesKuva[1]) {
				kilp.SetTark(os, kesKuva[1]);
				muuttui = true;
			}
		} else if (tyyppi == L"status") {
			UnicodeString kes;
			if (ApiJsonFindString(o, L"keskhyl", kes) && kes.Length() > 0 && kilp.wTark(os) != kes[1]) {
				kilp.SetTark(os, kes[1]);
				muuttui = true;
			}
		} else if (tyyppi == L"aika") {
			int piste = -1;
			__int64 tick = 0;
			bool onTick = ApiJsonFindInt64(o, L"aika_tick", tick);
			if (!onTick)
				ApiJsonFindInt64(o, L"aika_sec", tick);
			ApiJsonFindInt(o, L"piste", piste);
			if (tick > 0 && piste >= 0) {
				INT32 tl = onTick ? (INT32)tick : ApiViestiInboundMaali(kilp, os, tick);
				if (tl != 0 && tl != TMAALI0) {
					kilp.setMaali(os, piste, tl);
					muuttui = true;
				}
			}
		}
		if (muuttui)
			tallenna(&kilp, d, 0, 0, 0, 0);
	} __finally {
		ApiSovellusLoppuu();
		LeaveCriticalSection(&tall_CriticalSection);
	}
}
