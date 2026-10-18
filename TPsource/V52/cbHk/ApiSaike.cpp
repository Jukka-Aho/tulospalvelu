#include <vcl.h>
#pragma hdrstop
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <vector>

#include "ApiSaike.h"
#include "ApiJson.h"
#include "ApiYhteydet.h"
#include "HkDeclare.h"
#include "UnitOsanottajat.h"

#pragma package(smart_init)

extern CRITICAL_SECTION tall_CriticalSection;

static int gOsanottajaLika = 0;

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
	return UnicodeString(kilpailu).Trim();
}

UnicodeString ApiPirilaPvm(void)
{
	wchar_t st[12];

	stDateNo(st, pvparam[0].Date);
	return UnicodeString(st);
}

int ApiPirilaVaiheet(void)
{
	return kilpparam.n_pv > 0 ? kilpparam.n_pv : 1;
}

static CRITICAL_SECTION lasnaJonoCS;
static bool lasnaJonoAlustettu = false;
static int lasnaJono[256];
static int lasnaJonoN = 0;

typedef struct {
	int numero;
	int piste;
	int aikaSec;
} ApiTapahtuma;

static CRITICAL_SECTION tapJonoCS;
static bool tapJonoAlustettu = false;
static ApiTapahtuma tapJono[256];
static int tapJonoN = 0;

static void TapJonoAlusta(void)
{
	if (!tapJonoAlustettu) {
		InitializeCriticalSection(&tapJonoCS);
		tapJonoAlustettu = true;
	}
}

static void LasnaJonoAlusta(void)
{
	if (!lasnaJonoAlustettu) {
		InitializeCriticalSection(&lasnaJonoCS);
		lasnaJonoAlustettu = true;
	}
}

// k_pv is 0-based (vaihe 1 => k_pv == 0). Do not subtract 1: that reads
// the previous day, so current-stage splits go out as an empty valiajat[].
static int ApiIpv(void)
{
	int ipv = k_pv;
	if (ipv < 0)
		ipv = 0;
	if (kilpparam.n_pv_akt > 0 && ipv >= kilpparam.n_pv_akt)
		ipv = kilpparam.n_pv_akt - 1;
	else if (kilpparam.n_pv > 0 && ipv >= kilpparam.n_pv)
		ipv = kilpparam.n_pv - 1;
	return ipv;
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

// '-' means present on every open stage. Other letters stay on one stage.
static bool ApiAsetaTark(kilptietue& kilp, wchar_t ch, int vaihe)
{
	if (!kilp.pv || ch == 0)
		return false;
	bool muuttui = false;
	if (ch == L'-') {
		int n = kilpparam.n_pv_akt;
		if (n < 1)
			n = 1;
		if (kilpparam.n_pv > 0 && n > kilpparam.n_pv)
			n = kilpparam.n_pv;
		for (int i = 0; i < n; i++) {
			if (kilp.tark(i) != L'-') {
				kilp.set_tark(L'-', i);
				muuttui = true;
			}
		}
		return muuttui;
	}
	if (vaihe < 0 || (kilpparam.n_pv > 0 && vaihe >= kilpparam.n_pv))
		vaihe = 0;
	if (kilp.tark(vaihe) != ch) {
		kilp.set_tark(ch, vaihe);
		muuttui = true;
	}
	return muuttui;
}

// Start-gate emit on the web marks ilmoittautunut (N) as lasna (-).
// Do not convert on status LASNA alone: outbound synkkaa publishes N as LASNA,
// and a fetch without keskhyl would otherwise wipe N on every restart.
static void ApiIlmoittautunutEmitLasna(kilptietue& kilp, int ipv,
	bool kesAnnettu, const UnicodeString& keskhylIn, bool onEmitTieto)
{
	if (kilp.tark(ipv) != L'N')
		return;
	if (onEmitTieto || (kesAnnettu && keskhylIn.Length() > 0 && keskhylIn[1] == L'-'))
		ApiAsetaTark(kilp, L'-', ipv);
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

// JAHOnline aika_sec = whole seconds of race result.
// Piril√§ stores times as ticks (SEK = 1000 ms). Do not send ticks as aika_sec.
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
	// Values this large are leftover Pirila ticks, not seconds.
	if (sec >= (__int64)100000)
		return (INT32)sec;
	return (INT32)(sec * (__int64)SEK);
}

static bool ApiAikaTyhja(INT32 a)
{
	return a <= 0 || a == TMAALI0;
}

// Wire piste 0 = maali = va[1] = p_aika(0).
// Wire piste n (>=1) = nth split = va[n+1] = p_aika(n).
// va[0] is start; sending va[p] as piste p put the finish time in split 1.
static int ApiVaIx(int piste)
{
	return piste + 1;
}

// va[] is new vatp[kilpparam.valuku+2]: [1]=maali, [2..valuku+1]=splits.
// Series valuku[ipv] is often 0 even when kilpparam.valuku (and va[]) is filled.
static int ApiVaNva(int srj, int ipv)
{
	int nva = 0;
	if (srj >= 0 && srj < sarjaluku && ipv >= 0)
		nva = Sarjat[srj].valuku[ipv];
	if (nva < kilpparam.valuku)
		nva = kilpparam.valuku;
	if (nva < 0)
		nva = 0;
	if (kilpparam.valuku >= 0 && nva > kilpparam.valuku)
		nva = kilpparam.valuku;
	if (nva > 60)
		nva = 60;
	return nva;
}

// JAHOnline rastiv‰liajat: same valiajat[] as online splits, plus rasti_koodi
// from laskeemitvaliajat (Emit course legs). Rows without rasti_koodi update
// sarjat.valia_lkm; rows with rasti_koodi feed /public/valiajat.php.
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

static void ApiLisaaEmitValiajat(kilptietue& kilp, UnicodeString& valia, bool& vfirst)
{
	if (!emitfl)
		return;
	emittp em;
	if (getem(&em, kilp.id(), 0) < 0 || em.kilpno <= 0)
		return;

	emitvaanaltp *eva = 0;
	int nva = 0;
	laskeemitvaliajat(&em, &kilp, &eva, 0, &nva);
	if (eva && nva > 0) {
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

	emitvatp emva;
	if (tee_emva(&emva, &em) != 0)
		return;
	ratatp *rt = haerata(&kilp);
	int n = emva.rastiluku;
	if (n < 0)
		n = 0;
	if (n > MAXNRASTI)
		n = MAXNRASTI;
	int prev = 0;
	for (int i = 0; i < n; i++) {
		int koodi = (int)emva.rastit[i][0];
		int sec = (int)emva.rastit[i][1];
		if (koodi <= 0 && rt && i < MAXNRASTI)
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

static void ApiKopioiW(wchar_t *dst, int dstChars, const UnicodeString& src)
{
	if (!dst || dstChars < 2 || src.IsEmpty())
		return;
	int n = dstChars - 1;
	wcsncpy(dst, src.c_str(), n);
	dst[n] = 0;
}

static bool ApiLueLahtoAika(const UnicodeString& o, INT32& tl)
{
	UnicodeString s;
	if (ApiJsonFindString(o, L"lahto_aika", s) && !s.IsEmpty()) {
		tl = wstrtoaika_vap(s.c_str(), t0);
		return !ApiAikaTyhja(tl);
	}
	if (ApiJsonFindString(o, L"pirila_lahto_at", s) && !s.IsEmpty()) {
		tl = wstrtoaika_vap(s.c_str(), t0);
		return !ApiAikaTyhja(tl);
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

static INT32 ApiKilpailijanLahto(kilptietue& kilp, int ipv)
{
	INT32 tl = kilp.TLahto(ipv);
	if (tl == TMAALI0) {
		int srj = kilp.Sarja(ipv);
		if (srj >= 0 && srj < sarjaluku)
			tl = Sarjat[srj].enslahto[ipv];
	}
	return tl;
}

static UnicodeString ApiSarjaMatka(int srj, int ipv)
{
	wchar_t buf[24];

	buf[0] = 0;
	if (srj < 0 || srj >= sarjaluku || ipv < 0 || ipv >= N_PV)
		return UnicodeString(L"");
	wcsncpy(buf, Sarjat[srj].matka[ipv], 22);
	buf[22] = 0;
	if (buf[0] == 0 && ipv != 0) {
		wcsncpy(buf, Sarjat[srj].matka[0], 22);
		buf[22] = 0;
	}
	return UnicodeString(buf);
}

static int ApiSarjaLahtopaikka(int srj, int ipv)
{
	int n;

	if (srj < 0 || srj >= sarjaluku || ipv < 0 || ipv >= N_PV)
		return 0;
	n = Sarjat[srj].lno[ipv];
	if (n < 0)
		n = 0;
	if (n > 99)
		n = 99;
	return n;
}

static UnicodeString ApiRataTunnus(const wchar_t *src, int n)
{
	wchar_t buf[32];

	buf[0] = 0;
	if (!src || n <= 0)
		return UnicodeString(L"");
	if (n > 31)
		n = 31;
	wcsncpy(buf, src, n);
	buf[n] = 0;
	return UnicodeString(buf);
}

static UnicodeString ApiSarjaRataNimi(int srj, int ipv)
{
	UnicodeString nimi;

	if (srj < 0 || srj >= sarjaluku || ipv < 0 || ipv >= N_PV)
		return UnicodeString(L"");
	nimi = ApiRataTunnus(Sarjat[srj].rata[ipv], 19);
	if (nimi.IsEmpty())
		nimi = ApiRataTunnus(Sarjat[srj].sarjanimi, LSARJA);
	return nimi;
}

static int ApiSarjaRataOma(int srj, int ipv)
{
	if (srj < 0 || srj >= sarjaluku || ipv < 0 || ipv >= N_PV)
		return 0;
	return Sarjat[srj].rata[ipv][0] ? 1 : 0;
}

static UnicodeString ApiRakennaRadatJson(void)
{
	UnicodeString arr = L"[";
	bool first = true;
	int n;
	int i;

	if (!rata || nrata <= 0)
		return arr + L"]";
	n = nrata;
	if (n > MAXNRATA)
		n = MAXNRATA;
	for (i = 0; i < n; i++) {
		int nras;
		int r;

		if (rata[i].tunnus[0] == 0)
			continue;
		if (!first)
			arr += L",";
		first = false;
		nras = rata[i].rastiluku;
		if (nras < 0)
			nras = 0;
		if (nras > MAXNRASTI)
			nras = MAXNRASTI;
		arr += L"{\"tunnus\":" + ApiJsonString(ApiRataTunnus(rata[i].tunnus, 11));
		arr += L",\"rastiluku\":" + IntToStr(nras);
		arr += L",\"pituus_m\":" + IntToStr(rata[i].ratapit);
		arr += L",\"nousu_m\":" + IntToStr(rata[i].nousu);
		arr += L",\"maaliin_m\":" + IntToStr(rata[i].viitmatka);
		arr += L",\"rastit\":[";
		for (r = 0; r < nras; r++) {
			if (r)
				arr += L",";
			arr += L"{\"koodi\":" + IntToStr((int) rata[i].rastikoodi[r]);
			arr += L",\"kumul_m\":" + IntToStr(rata[i].matka[r]);
			arr += L"}";
		}
		arr += L"]}";
	}
	arr += L"]";
	return arr;
}

static UnicodeString ApiRakennaSarjatJson(void)
{
	UnicodeString arr = L"[";
	bool first = true;
	int srj;
	int ipv = k_pv;

	if (ipv < 0 || ipv >= N_PV)
		ipv = 0;
	for (srj = 0; srj < sarjaluku; srj++) {
		if (Sarjat[srj].sarjanimi[0] == 0 || Sarjat[srj].vakanttisarja)
			continue;
		if (!first)
			arr += L",";
		first = false;
		arr += L"{\"nimi\":" + ApiJsonString(ApiRataTunnus(Sarjat[srj].sarjanimi, LSARJA));
		arr += L",\"rata\":" + ApiJsonString(ApiSarjaRataNimi(srj, ipv));
		arr += L",\"rata_oma\":" + IntToStr(ApiSarjaRataOma(srj, ipv));
		arr += L",\"lahtopaikka\":" + IntToStr(ApiSarjaLahtopaikka(srj, ipv));
		arr += L",\"matka\":" + ApiJsonString(ApiSarjaMatka(srj, ipv));
		arr += L"}";
	}
	arr += L"]";
	return arr;
}

static UnicodeString ApiKilpailijaObj(kilptietue& kilp, int ipv)
{
	int numero = kilp.id();
	UnicodeString sarjaNimi = L"";
	int srj = kilp.Sarja(ipv);
	if (srj >= 0 && srj < sarjaluku)
		sarjaNimi = Sarjat[srj].sarjanimi;

	int badge = kilp.Badge(ipv, 0, true);
	int badge2 = kilp.Badge(ipv, 1, true);
	INT32 tls = kilp.tulos_pv(ipv, false, 1);
	wchar_t tark = kilp.tark(ipv);
	bool onLasna = kilp.lasna(ipv);

	UnicodeString valia = L"[";
	bool vfirst = true;
	int nva = ApiVaNva(srj, ipv);
	for (int p = 1; p <= nva; p++) {
		INT32 va = 0;
		INT16 sj = 0;
		if (ipv == k_pv && kilp.pv) {
			va = kilp.p_aika(p);
			sj = (INT16)kilp.p_sija(p);
		} else if (kilp.pv && kilp.pv[ipv].va) {
			int ix = ApiVaIx(p);
			va = kilp.pv[ipv].va[ix].vatulos;
			sj = kilp.pv[ipv].va[ix].vasija;
		}
		if (ApiAikaTyhja(va))
			continue;
		int vsec = ApiTulosSec(va);
		if (vsec <= 0)
			continue;
		if (!vfirst) valia += L",";
		vfirst = false;
		valia += L"{\"piste\":" + IntToStr(p)
			+ L",\"aika_sec\":" + IntToStr(vsec)
			+ L",\"sija\":" + IntToStr((int)sj)
			+ L"}";
	}
	ApiLisaaEmitValiajat(kilp, valia, vfirst);
	valia += L"]";

	UnicodeString arr = L"{";
	arr += L"\"numero\":" + IntToStr(numero);
	arr += L",\"sukunimi\":" + ApiJsonString(kilp.sukunimi);
	arr += L",\"etunimi\":" + ApiJsonString(kilp.etunimi);
	arr += L",\"nimi\":" + ApiJsonString(UnicodeString(kilp.sukunimi) + L" " + UnicodeString(kilp.etunimi));
	arr += L",\"seura\":" + ApiJsonString(kilp.seura);
	arr += L",\"maa\":" + ApiJsonString(kilp.maa);
	arr += L",\"sarja_nimi\":" + ApiJsonString(sarjaNimi);
	arr += L",\"sarja_matka\":" + ApiJsonString(ApiSarjaMatka(srj, ipv));
	arr += L",\"lahtopaikka\":" + IntToStr(ApiSarjaLahtopaikka(srj, ipv));
	arr += L",\"sarja_rata\":" + ApiJsonString(ApiSarjaRataNimi(srj, ipv));
	arr += L",\"sarja_rata_oma\":" + IntToStr(ApiSarjaRataOma(srj, ipv));
	{
		UnicodeString omaRata = L"";
		if (kilp.pv && ipv >= 0 && ipv < N_PV)
			omaRata = ApiRataTunnus(kilp.pv[ipv].rata, HAJONTA);
		arr += L",\"rata\":" + ApiJsonString(omaRata);
	}
	arr += L",\"badge\":" + IntToStr(badge);
	arr += L",\"badge2\":" + IntToStr(badge2);
	arr += L",\"emit_koodi\":" + IntToStr(badge);
	arr += L",\"emit_koodi2\":" + IntToStr(badge2);
	arr += L",\"lasna\":" + ApiJsonBool(onLasna);
	arr += L",\"status\":" + ApiJsonString(StatusMerkki(tark, onLasna, tls > 0));
	arr += L",\"keskhyl\":" + ApiJsonString(UnicodeString(tark));
	{
		wchar_t sakko[24];
		wchar_t *ps = kilp.asakkoStr(ipv);
		sakko[0] = 0;
		if (ps)
			wcsncpy(sakko, ps, 23);
		sakko[23] = 0;
		if (sakko[0] != 0)
			arr += L",\"asakot\":" + ApiJsonString(sakko)
				+ L",\"ampsakot\":" + ApiJsonString(sakko);
	}
	if (apiconfig.lahetaTulokset) {
		int tsec = ApiTulosSec(tls);
		if (tsec > 0)
			arr += L",\"aika_sec\":" + IntToStr(tsec);
		else
			arr += L",\"aika_sec\":null";
		INT16 sija = 0;
		if (kilp.pv)
			sija = kilp.pv[ipv].ysija;
		if (sija > 0)
			arr += L",\"sija\":" + IntToStr((int)sija);
		else
			arr += L",\"sija\":null";
		arr += L",\"valiajat\":" + valia;
	} else {
		arr += L",\"aika_sec\":null,\"sija\":null,\"valiajat\":[]";
	}
	arr += ApiLahtoKentat(ApiKilpailijanLahto(kilp, ipv));
	arr += L",\"tyyppi\":\"yksilo\"";
	arr += L"}";
	return arr;
}

UnicodeString ApiRakennaKilpailijatJson(void)
{
	UnicodeString arr = L"[";
	bool first = true;
	int ipv = ApiIpv();

	if (nrec < 2)
		return UnicodeString(L"[]");

	for (int d = 1; d < nrec; d++) {
		kilptietue kilp;
		kilp.GETREC(d);
		if (kilp.kilpstatus != 0)
			continue;

		int numero = kilp.id();
		if (numero <= 0)
			continue;

		if (!first) arr += L",";
		first = false;
		arr += ApiKilpailijaObj(kilp, ipv);
	}
	arr += L"]";
	return arr;
}

// getpos only indexes minkilpno..maxkilpno (9999). A bib above that is stored
// by addtall but never found again, so each poll inserts another copy.
static int ApiEtsiTietue(int numero, int badge)
{
	if (numero >= minkilpno && numero <= maxkilpno) {
		int d = getpos(numero, true);
		if (d > 0)
			return d;
	}
	if (nrec < 2 || (numero <= 0 && badge <= 0))
		return -1;
	int badgeOsuma = -1;
	for (int i = 1; i < nrec; i++) {
		kilptietue k;
		k.GETREC(i);
		if (k.kilpstatus != 0)
			continue;
		if (numero > 0 && k.id() == numero)
			return i;
		if (badge > 0 && badgeOsuma < 0 && k.pv && k.Badge(k_pv, 0, true) == badge) {
			int id = k.id();
			if (id < minkilpno || id > maxkilpno)
				badgeOsuma = i;
		}
	}
	return badgeOsuma;
}

static void ApiOtaNumero(kilptietue& kilp, int numero, bool& muuttui)
{
	if (numero < minkilpno || numero > maxkilpno || kilp.id() == numero)
		return;
	int muu = getpos(numero, true);
	if (muu > 0)
		return;
	kilp.setId(numero);
	muuttui = true;
}

// Another real bib already owns this card. Leave it there and do not ask.
// A bib outside 1..9999 is an unindexed copy, so the card may move.
static bool ApiBadgeSaaSiirtya(int badge, int omaNumero)
{
	if (badge <= 0)
		return true;
	int omistaja = bdg2kno(badge);
	if (omistaja <= 0 || omistaja == omaNumero)
		return true;
	if (omistaja < minkilpno || omistaja > maxkilpno)
		return true;
	if (getpos(omistaja, true) <= 0)
		return true;
	return false;
}

// kielto -1: the change arrived over the link, so tallenna must not pop dialogs.
static void ApiTallennaApi(kilptietue& kilp, int d)
{
	kilp.tallenna(d, 0, -1, 0, 0);
}

static void ApiVarmistaSakkoPaikat(kilptietue& kilp, int vaihe, int n)
{
	if (n < 1)
		return;
	if (n > LSAKKO)
		n = LSAKKO;
	int srj = kilp.Sarja(vaihe);
	if (srj >= 0 && srj < sarjaluku && Sarjat[srj].lsak[vaihe] < n)
		Sarjat[srj].lsak[vaihe] = n;
	if (kilpparam.lsakmax < n)
		kilpparam.lsakmax = n;
}

// Web stores one digit per bout ("24" = 2 then 4). Pirila with maxasakko > 9
// reads slash-separated bouts, so "24" would become a single value of 24.
// Palauttaa true vain, jos sakot oikeasti muuttuivat: palvelin l‰hett‰‰ samat
// sakot joka synkkakierroksella, eik‰ se saa merkit‰ kilpailijaa muuttuneeksi.
static bool ApiAsetaSakko(kilptietue& kilp, int vaihe, const wchar_t* sak)
{
	wchar_t buf[40];
	wchar_t out[40];
	wchar_t ennen[LSAKKO + 1];
	int n = 0;
	int i;

	if (!sak || vaihe < 0 || !kilp.pv)
		return false;
	wcsncpy(buf, sak, 39);
	buf[39] = 0;
	if (!wcschr(buf, L'/') && !wcschr(buf, L';')
		&& (pvparam[vaihe].asakkolaji > 1 || kilpparam.maxasakko > 9)) {
		out[0] = 0;
		for (i = 0; buf[i] && n < LSAKKO; i++) {
			if (buf[i] == L'-' || (buf[i] >= L'0' && buf[i] <= L'9')) {
				if (n)
					wcscat(out, L"/");
				wchar_t one[2];
				one[0] = buf[i];
				one[1] = 0;
				wcscat(out, one);
				n++;
			}
		}
		if (n)
			wcsncpy(buf, out, 39);
	} else {
		for (i = 0; buf[i] && n < LSAKKO; i++) {
			if (buf[i] == L'-' || (buf[i] >= L'0' && buf[i] <= L'9'))
				n++;
		}
	}
	if (!n)
		return false;
	wmemcpy(ennen, kilp.pv[vaihe].asakot, LSAKKO + 1);
	ApiVarmistaSakkoPaikat(kilp, vaihe, n);
	kilp.set_Asakot(buf, vaihe);
	return wmemcmp(ennen, kilp.pv[vaihe].asakot, LSAKKO + 1) != 0;
}

int ApiSovellaKilpailijatJson(const UnicodeString& json, bool korvaaKentat)
{
	std::vector<UnicodeString> objs;
	int n = ApiJsonExtractObjectArray(json, L"kilpailijat", objs);
	if (n <= 0)
		return 0;
	if (nrec < 2)
		return 0;

	bool haeKilp = apiconfig.vastaanottaKilpailijat != 0;
	bool haeVa = apiconfig.vastaanottaValiajat != 0;
	bool haeDns = apiconfig.vastaanottaEiLahteneet != 0;
	if (!haeKilp && !haeVa && !haeDns)
		return 0;

	int ipv = ApiIpv();
	int updated = 0;
	EnterCriticalSection(&tall_CriticalSection);
	__try {
		for (size_t i = 0; i < objs.size(); i++) {
			const UnicodeString& o = objs[i];
			int numero = 0;
			if (!ApiJsonFindInt(o, L"numero", numero) || numero <= 0)
				continue;

			UnicodeString sukunimi, etunimi, seura, maa, sarjaNimi, status;
			ApiJsonFindString(o, L"sukunimi", sukunimi);
			ApiJsonFindString(o, L"etunimi", etunimi);
			ApiJsonFindString(o, L"seura", seura);
			ApiJsonFindString(o, L"maa", maa);
			ApiJsonFindString(o, L"sarja_nimi", sarjaNimi);
			ApiJsonFindString(o, L"status", status);

			bool lasnaFlag = false;
			bool lasnaAnnettu = ApiJsonFindBool(o, L"lasna", lasnaFlag);

			int badge = 0, badge2 = 0, aikaSec = -1, sija = -1;
			ApiJsonFindInt(o, L"badge", badge);
			if (badge <= 0)
				ApiJsonFindInt(o, L"emit_koodi", badge);
			ApiJsonFindInt(o, L"badge2", badge2);
			if (badge2 <= 0)
				ApiJsonFindInt(o, L"emit_koodi2", badge2);
			__int64 a64 = 0;
			if (ApiJsonFindInt64(o, L"aika_sec", a64))
				aikaSec = (int)a64;
			ApiJsonFindInt(o, L"sija", sija);

			int d = ApiEtsiTietue(numero, badge);
			kilptietue kilp;
			bool uusi = false;
			if (d > 0) {
				kilp.GETREC(d);
			} else if (!haeKilp) {
				continue;
			} else {
				kilp.nollaa();
				kilp.setId(numero);
				uusi = true;
			}

			bool muuttui = uusi;
			if (!uusi)
				ApiOtaNumero(kilp, numero, muuttui);

			if (haeKilp) {
				if (!sukunimi.IsEmpty() && (korvaaKentat || kilp.sukunimi[0] == 0)) {
					if (UnicodeString(kilp.sukunimi) != sukunimi) {
						ApiKopioiW(kilp.sukunimi, LSNIMI + 1, sukunimi);
						muuttui = true;
					}
				}
				if (!etunimi.IsEmpty() && (korvaaKentat || kilp.etunimi[0] == 0)) {
					if (UnicodeString(kilp.etunimi) != etunimi) {
						ApiKopioiW(kilp.etunimi, LENIMI + 1, etunimi);
						muuttui = true;
					}
				}
				if (!seura.IsEmpty() && (korvaaKentat || kilp.seura[0] == 0)) {
					if (UnicodeString(kilp.seura) != seura) {
						ApiKopioiW(kilp.seura, LSEURA + 1, seura);
						muuttui = true;
					}
				}
				if (!maa.IsEmpty() && (korvaaKentat || kilp.maa[0] == 0)) {
					ApiKopioiW(kilp.maa, 4, maa);
					muuttui = true;
				}
				if (!sarjaNimi.IsEmpty()) {
					for (int s = 0; s < sarjaluku; s++) {
						if (sarjaNimi.CompareIC(Sarjat[s].sarjanimi) == 0) {
							if (kilp.Sarja() != s) {
								kilp.setSarja(s);
								muuttui = true;
							}
							break;
						}
					}
				}
			}

			if (kilp.pv) {
				if (haeKilp) {
					if (badge > 0 && (korvaaKentat || kilp.pv[ipv].badge[0] == 0)) {
						if (kilp.pv[ipv].badge[0] != badge && ApiBadgeSaaSiirtya(badge, numero)) {
							kilp.pv[ipv].badge[0] = badge;
							muuttui = true;
						}
					}
					if (badge2 > 0 && (korvaaKentat || kilp.pv[ipv].badge[1] == 0)) {
						if (kilp.pv[ipv].badge[1] != badge2 && ApiBadgeSaaSiirtya(badge2, numero)) {
							kilp.pv[ipv].badge[1] = badge2;
							muuttui = true;
						}
					}
				}
				{
					UnicodeString kesKirjain;
					bool kesKirjainAnnettu = ApiJsonFindString(o, L"keskhyl", kesKirjain);
					if (kesKirjainAnnettu && kesKirjain.Length() > 0) {
						if (ApiAsetaTark(kilp, kesKirjain[1], ipv))
							muuttui = true;
					} else if (haeDns && !status.IsEmpty()) {
						wchar_t m = StatusMerkkiin(status);
						wchar_t t = kilp.tark(ipv);
						bool onTulos = kilp.tulos_pv(ipv, false, 1) > 0;
						if (m != L' ' && !(t == L'N' && m == L'-') && t != m) {
							if (!(m == L'E' && onTulos)) {
								kilp.set_tark(m, ipv);
								muuttui = true;
							}
						}
					}
				}
				if (haeKilp && lasnaAnnettu && lasnaFlag && !kilp.lasna(ipv)) {
					wchar_t t = kilp.tark(ipv);
					if (t == L'E' || t == L'P' || t == L'V' || t == L'B') {
						if (ApiAsetaTark(kilp, L'-', ipv))
							muuttui = true;
					}
				}
				{
					UnicodeString sakkoIn;
					if (!ApiJsonFindString(o, L"ampsakot", sakkoIn))
						ApiJsonFindString(o, L"asakot", sakkoIn);
					if (!sakkoIn.IsEmpty() && ApiAsetaSakko(kilp, ipv, sakkoIn.c_str()))
						muuttui = true;
				}
				if (aikaSec > 0 && (haeKilp || haeVa)) {
					INT32 localTls = kilp.tulos_pv(ipv, false, 1);
					if (korvaaKentat || localTls <= 0) {
						kilp.tall_tulos_pv(ApiSecToTicks(aikaSec), ipv, 0);
						muuttui = true;
					}
				}
				if (haeKilp) {
					INT32 lahtoTl = 0;
					if (ApiLueLahtoAika(o, lahtoTl)) {
						INT32 localLahto = kilp.TLahto(ipv);
						if (korvaaKentat || ApiAikaTyhja(localLahto)) {
							kilp.tall_lajat_pv(lahtoTl, ipv);
							muuttui = true;
						}
					}
				}
				if (sija > 0 && haeKilp && (korvaaKentat || kilp.pv[ipv].ysija <= 0)) {
					if (kilp.pv[ipv].ysija != (INT16)sija) {
						kilp.pv[ipv].ysija = (INT16)sija;
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
							if (piste > 60 || !kilp.pv[ipv].va)
								continue;
							int ix = ApiVaIx(piste);
							if (ApiJsonFindInt64(vas[v], L"aika_sec", va64) && va64 > 0) {
								onEmitTieto = true;
								INT32 localVa = kilp.pv[ipv].va[ix].vatulos;
								if (korvaaKentat || localVa <= 0) {
									kilp.pv[ipv].va[ix].vatulos = ApiSecToTicks(va64);
									muuttui = true;
								}
							}
							if (ApiJsonFindInt(vas[v], L"sija", vasija) && vasija > 0) {
								if (korvaaKentat || kilp.pv[ipv].va[ix].vasija <= 0) {
									kilp.pv[ipv].va[ix].vasija = (INT16)vasija;
									muuttui = true;
								}
							}
						}
					}
				}
				{
					UnicodeString kesIn;
					bool kesAnnettu = ApiJsonFindString(o, L"keskhyl", kesIn);
					wchar_t ennen = kilp.tark(ipv);
					ApiIlmoittautunutEmitLasna(kilp, ipv, kesAnnettu, kesIn, onEmitTieto);
					if (kilp.tark(ipv) != ennen)
						muuttui = true;
				}
			}

			if (!muuttui)
				continue;
			if (uusi) {
				int nd = 0;
				kilp.addtall(&nd, 0);
			} else {
				ApiTallennaApi(kilp, d);
			}
			updated++;
		}
	} __finally {
		LeaveCriticalSection(&tall_CriticalSection);
	}
	if (updated > 0)
		gOsanottajaLika = 1;
	return updated;
}

int ApiSynkkaaLahetaKaikki(void)
{
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;

	UnicodeString kilpailijat = ApiRakennaKilpailijatJson();
	UnicodeString body =
		L"{\"action\":\"synkkaa\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":\"HkKisaWin\""
		+ L",\"kello\":" + ApiJsonString(ApiPaikallinenKello())
		+ L",\"luo_puuttuvat\":true"
		+ L",\"kilpailijat\":" + kilpailijat
		+ L",\"sarjat\":" + ApiRakennaSarjatJson()
		+ L",\"radat\":" + ApiRakennaRadatJson()
		+ L"}";

	UnicodeString vastaus;
	if (!ApiHttpPostJson(ApiBridgeUrl(), body, vastaus))
		return -2;
	if (!ApiJsonStatusOk(vastaus))
		return -3;

	int count = 0;
	// rough count of objects
	std::vector<UnicodeString> objs;
	count = ApiJsonExtractObjectArray(L"{\"kilpailijat\":" + kilpailijat + L"}", L"kilpailijat", objs);
	return count;
}

int ApiSynkkaaHaeKaikki(bool korvaaKentat)
{
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;

	UnicodeString body =
		L"{\"action\":\"kilpailijat\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"tyyppi\":\"yksilo\"}";
	UnicodeString vastaus;
	if (!ApiHttpPostJson(ApiBridgeUrl(), body, vastaus))
		return -2;
	if (!ApiJsonStatusOk(vastaus))
		return -3;
	return ApiSovellaKilpailijatJson(vastaus, korvaaKentat);
}

static void ApiJonotaHkKuva(int kilpno)
{
	int d = getpos(kilpno, true);
	if (d <= 0)
		return;
	kilptietue kilp;
	kilp.GETREC(d);
	if (kilp.kilpstatus != 0 || kilp.id() <= 0)
		return;
	int ipv = k_pv;
	UnicodeString sarjaNimi;
	int srj = kilp.Sarja(ipv);
	if (srj >= 0 && srj < sarjaluku)
		sarjaNimi = Sarjat[srj].sarjanimi;
	ApiJonotaTapahtuma(
		L"{\"tyyppi\":\"kilpailija\",\"numero\":" + IntToStr(kilpno)
		+ L",\"vaihe\":" + IntToStr(ipv)
		+ L",\"sukunimi\":" + ApiJsonString(kilp.sukunimi)
		+ L",\"etunimi\":" + ApiJsonString(kilp.etunimi)
		+ L",\"seura\":" + ApiJsonString(kilp.seura)
		+ L",\"maa\":" + ApiJsonString(kilp.maa)
		+ L",\"sarja_nimi\":" + ApiJsonString(sarjaNimi)
		+ L",\"sarja_matka\":" + ApiJsonString(ApiSarjaMatka(srj, ipv))
		+ L",\"lahtopaikka\":" + IntToStr(ApiSarjaLahtopaikka(srj, ipv))
		+ L",\"sarja_rata\":" + ApiJsonString(ApiSarjaRataNimi(srj, ipv))
		+ L",\"sarja_rata_oma\":" + IntToStr(ApiSarjaRataOma(srj, ipv))
		+ L",\"badge\":" + IntToStr(kilp.Badge(ipv, 0, true))
		+ L",\"badge2\":" + IntToStr(kilp.Badge(ipv, 1, true))
		+ L",\"keskhyl\":" + ApiJsonString(UnicodeString(kilp.tark(ipv)))
		+ L"}");
	ApiJonotaTapahtuma(
		L"{\"tyyppi\":\"status\",\"numero\":" + IntToStr(kilpno)
		+ L",\"vaihe\":" + IntToStr(ipv)
		+ L",\"keskhyl\":" + ApiJsonString(UnicodeString(kilp.tark(ipv)))
		+ L"}");
	wchar_t sakko[24];
	wchar_t *ps = kilp.asakkoStr(ipv);
	sakko[0] = 0;
	if (ps)
		wcsncpy(sakko, ps, 23);
	sakko[23] = 0;
	if (sakko[0] != 0) {
		ApiJonotaTapahtuma(
			L"{\"tyyppi\":\"sakko\",\"numero\":" + IntToStr(kilpno)
			+ L",\"vaihe\":" + IntToStr(ipv)
			+ L",\"asakot\":" + ApiJsonString(sakko)
			+ L"}");
	}
	if (emitfl) {
		emittp em;
		if (getem(&em, kilp.id(), 0) >= 0 && em.kilpno > 0) {
			emitvaanaltp *eva = 0;
			int nva = 0;
			laskeemitvaliajat(&em, &kilp, &eva, 0, &nva);
			if (eva && nva > 0) {
				if (nva > MAXNRASTI)
					nva = MAXNRASTI;
				UnicodeString leimat = L"[";
				bool first = true;
				for (int i = 0; i < nva; i++) {
					if (eva[i].rkoodi <= 0 && eva[i].aika <= 0)
						continue;
					if (!first)
						leimat += L",";
					first = false;
					leimat += L"{\"jarjestys\":" + IntToStr(i + 1)
						+ L",\"koodi\":" + IntToStr((int)eva[i].rkoodi)
						+ L",\"aika_sec\":" + IntToStr((int)eva[i].aika)
						+ L"}";
				}
				free(eva);
				if (!first) {
					leimat += L"]";
					ApiJonotaTapahtuma(
						L"{\"tyyppi\":\"emit\",\"numero\":" + IntToStr(kilpno)
						+ L",\"leimat\":" + leimat + L"}");
				}
			} else if (eva) {
				free(eva);
			}
		}
	}
}

void ApiIlmoitaLasna(int kilpno)
{
	if (kilpno <= 0 || ApiSovellusKaynnissa())
		return;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return;
	if (ApiSiirtoOnJahonline())
		ApiJonotaHkKuva(kilpno);

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

void ApiIlmoitaTapahtuma(int kilpno, int piste, int aikaSec)
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
			avain += L"-" + IntToStr(kilpno) + L"-" + IntToStr(aikaSec);
			ApiJonotaTapahtuma(
				L"{\"tyyppi\":\"leima\",\"aika_tick\":" + IntToStr(aikaSec)
				+ L",\"avain\":" + ApiJsonString(avain)
				+ L"}");
		}
		return;
	}
	if (ApiSiirtoOnJahonline() && aikaSec > 0 && aikaSec != (int)TMAALI0) {
		ApiJonotaTapahtuma(
			L"{\"tyyppi\":\"aika\",\"numero\":" + IntToStr(kilpno)
			+ L",\"vaihe\":" + IntToStr(k_pv)
			+ L",\"piste\":" + IntToStr(piste)
			+ L",\"aika_tick\":" + IntToStr(aikaSec)
			+ L"}");
	}
	if (!apiconfig.lahetaValiajat && piste != 0)
		return;
	int tsec = ApiTulosSec(aikaSec);
	if (tsec <= 0)
		return;

	TapJonoAlusta();
	EnterCriticalSection(&tapJonoCS);
	if (tapJonoN < (int)(sizeof(tapJono) / sizeof(tapJono[0]))) {
		ApiTapahtuma t;
		t.numero = kilpno;
		t.piste = piste;
		t.aikaSec = tsec;
		tapJono[tapJonoN++] = t;
	} else
		tapJonoPudotettu++;
	LeaveCriticalSection(&tapJonoCS);
}

// C wrappers for HkDat.cpp (same signatures as HkDeclare.h; console has empty stubs)
void api_ilmoita_kilpailija(int kilpno)
{
	ApiIlmoitaLasna(kilpno);
}

void api_ilmoita_tapahtuma(int kilpno, int piste, int aikaSec)
{
	ApiIlmoitaTapahtuma(kilpno, piste, aikaSec);
}

static UnicodeString ApiTapahtumatJson(const ApiTapahtuma* ev, int n)
{
	UnicodeString arr = L"[";
	bool first = true;
	for (int i = 0; i < n; i++) {
		if (!first)
			arr += L",";
		first = false;
		arr += L"{\"numero\":" + IntToStr(ev[i].numero)
			+ L",\"piste\":" + IntToStr(ev[i].piste)
			+ L",\"aika_sec\":" + IntToStr(ev[i].aikaSec)
			+ L",\"lahde\":\"online\"}";
	}
	arr += L"]";
	return arr;
}

static int ApiLahetaTapahtumat(const ApiTapahtuma* ev, int n)
{
	if (n <= 0)
		return 0;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;

	UnicodeString body =
		L"{\"action\":\"tapahtuma\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":\"HkKisaWin\""
		+ L",\"tyyppi\":\"yksilo\""
		+ L",\"tapahtumat\":" + ApiTapahtumatJson(ev, n)
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
	if (nrec < 2)
		return 0;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;

	int ipv = ApiIpv();
	UnicodeString arr = L"[";
	bool first = true;
	for (int i = 0; i < n; i++) {
		int d = getpos(nums[i], true);
		if (d <= 0)
			continue;
		kilptietue kilp;
		kilp.GETREC(d);
		if (kilp.kilpstatus != 0 || kilp.id() <= 0)
			continue;
		if (!first)
			arr += L",";
		first = false;
		arr += ApiKilpailijaObj(kilp, ipv);
	}
	arr += L"]";
	if (first)
		return 0;

	UnicodeString body =
		L"{\"action\":\"synkkaa\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":\"HkKisaWin\""
		+ L",\"kello\":" + ApiJsonString(ApiPaikallinenKello())
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
	if (nrec < 2)
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
			Paivita(L"L‰hetetty kilpailijoita: " + IntToStr(n));
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

void ApiPaivitaOsanottajaNakyma(void)
{
	if (!FormOsanottajat || !FormOsanottajat->Visible || !FormOsanottajat->OoGrid) {
		gOsanottajaLika = 0;
		return;
	}
	TStringGrid *g = FormOsanottajat->OoGrid;
	// Muokkaustilassa (F9) naytaTiedot hylk‰isi tallentamattomat muutokset.
	// P‰ivitys tehd‰‰n, kun muokkaus on tallennettu tai peruttu.
	if (g->Options.Contains(goEditing))
		return;
	gOsanottajaLika = 0;
	int rr = g->Row;
	int cc = g->Col;
	int top = g->TopRow;
	// naytaTiedot tyhjent‰‰ taulukon ja rakentaa sen uudelleen; ilman piirron
	// est‰mist‰ tyhj‰ taulukko n‰kyy hetken (v‰l‰hdys).
	SendMessage(g->Handle, WM_SETREDRAW, FALSE, 0);
	try {
		FormOsanottajat->naytaTiedot();
		if (rr >= g->RowCount)
			rr = g->RowCount - 1;
		if (top > g->RowCount - g->VisibleRowCount)
			top = g->RowCount - g->VisibleRowCount;
		if (top >= g->FixedRows)
			g->TopRow = top;
		if (rr >= g->FixedRows)
			g->Row = rr;
		if (cc >= g->FixedCols && cc < g->ColCount)
			g->Col = cc;
	} __finally {
		SendMessage(g->Handle, WM_SETREDRAW, TRUE, 0);
		g->Invalidate();
	}
}

void __fastcall TApiSaike::PaivitaOsanottajat(void)
{
	ApiPaivitaOsanottajaNakyma();
}

void __fastcall TApiSaike::Execute(void)
{
	while (!pysaytysPyynnon) {
		viiveMs = apiconfig.lahetysvali * 1000;
		if (viiveMs < 2000)
			viiveMs = 2000;
		if (apiconfig.kaynnissa)
			Kasittele();
		if (!pysaytysPyynnon && gOsanottajaLika)
			Synchronize(&PaivitaOsanottajat);
		for (int t = 0; t < viiveMs && !pysaytysPyynnon; t += 200) {
			Sleep(200);
			if (!pysaytysPyynnon && ApiSiirtoOnJahonline()) {
				ApiLahetaTapahtumaJono();
				int pud = ApiTapahtumaPudotettuOta();
				if (pud > 0)
					Paivita(L"Jono taynna, pudotettu tapahtumia " + IntToStr(pud), true);
				ApiHaeTapahtumat();
			}
			if (!pysaytysPyynnon && gOsanottajaLika)
				Synchronize(&PaivitaOsanottajat);
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
	if (!ApiJsonFindString(o, L"tyyppi", tyyppi) || tyyppi == L"emit")
		return;
	int numero = 0;
	if (!ApiJsonFindInt(o, L"numero", numero) || numero <= 0)
		return;
	int vaihe = k_pv;
	if (!ApiJsonFindInt(o, L"vaihe", vaihe) || vaihe < 0 || vaihe >= kilpparam.n_pv_akt)
		vaihe = k_pv;

	int badgeHaku = 0;
	if (!ApiJsonFindInt(o, L"badge", badgeHaku) || badgeHaku <= 0)
		ApiJsonFindInt(o, L"emit_koodi", badgeHaku);
	int d = ApiEtsiTietue(numero, badgeHaku);
	if (d <= 0 && tyyppi != L"kilpailija")
		return;

	bool likainen = false;
	EnterCriticalSection(&tall_CriticalSection);
	ApiSovellusAlkaa();
	__try {
		kilptietue kilp;
		bool uusi = false;
		if (d > 0)
			kilp.GETREC(d);
		else {
			kilp.nollaa();
			kilp.setId(numero);
			uusi = true;
		}
		bool muuttui = uusi;
		if (!uusi)
			ApiOtaNumero(kilp, numero, muuttui);
		if (tyyppi == L"kilpailija") {
			UnicodeString sukunimi, etunimi, seura, maa, sarjaNimi;
			ApiJsonFindString(o, L"sukunimi", sukunimi);
			ApiJsonFindString(o, L"etunimi", etunimi);
			ApiJsonFindString(o, L"seura", seura);
			ApiJsonFindString(o, L"maa", maa);
			ApiJsonFindString(o, L"sarja_nimi", sarjaNimi);
			if (!sukunimi.IsEmpty() && UnicodeString(kilp.sukunimi) != sukunimi) {
				ApiKopioiW(kilp.sukunimi, LSNIMI + 1, sukunimi);
				muuttui = true;
			}
			if (!etunimi.IsEmpty() && UnicodeString(kilp.etunimi) != etunimi) {
				ApiKopioiW(kilp.etunimi, LENIMI + 1, etunimi);
				muuttui = true;
			}
			if (!seura.IsEmpty() && UnicodeString(kilp.seura) != seura) {
				ApiKopioiW(kilp.seura, LSEURA + 1, seura);
				muuttui = true;
			}
			if (!maa.IsEmpty() && UnicodeString(kilp.maa) != maa.SubString(1, 3)) {
				ApiKopioiW(kilp.maa, 4, maa);
				muuttui = true;
			}
			if (!sarjaNimi.IsEmpty()) {
				for (int s = 0; s < sarjaluku; s++) {
					if (sarjaNimi.CompareIC(Sarjat[s].sarjanimi) == 0) {
						if (kilp.Sarja() != s) {
							kilp.setSarja(s);
							muuttui = true;
						}
						break;
					}
				}
			}
			int badge = 0, badge2 = 0;
			if (kilp.pv) {
				int oma = kilp.id() > 0 ? kilp.id() : numero;
				if (ApiJsonFindInt(o, L"badge", badge) && badge > 0 && kilp.pv[vaihe].badge[0] != badge
					&& ApiBadgeSaaSiirtya(badge, oma)) {
					kilp.pv[vaihe].badge[0] = badge;
					muuttui = true;
				}
				if (ApiJsonFindInt(o, L"badge2", badge2) && badge2 > 0 && kilp.pv[vaihe].badge[1] != badge2
					&& ApiBadgeSaaSiirtya(badge2, oma)) {
					kilp.pv[vaihe].badge[1] = badge2;
					muuttui = true;
				}
			}
			UnicodeString kesKuva;
			if (ApiJsonFindString(o, L"keskhyl", kesKuva) && kesKuva.Length() > 0) {
				if (ApiAsetaTark(kilp, kesKuva[1], vaihe))
					muuttui = true;
			}
		} else if (tyyppi == L"status") {
			UnicodeString kes;
			if (ApiJsonFindString(o, L"keskhyl", kes) && kes.Length() > 0) {
				if (ApiAsetaTark(kilp, kes[1], vaihe))
					muuttui = true;
			}
		} else if (tyyppi == L"aika" && kilp.pv) {
			int piste = -1;
			__int64 tick = 0;
			bool onTick = ApiJsonFindInt64(o, L"aika_tick", tick);
			if (!onTick)
				ApiJsonFindInt64(o, L"aika_sec", tick);
			ApiJsonFindInt(o, L"piste", piste);
			INT32 tl = 0;
			if (tick > 0)
				tl = onTick ? (INT32)tick : ApiSecToTicks(tick);
			if (tl > 0 && piste == 0) {
				kilp.tall_tulos_pv(tl, vaihe, 0);
				muuttui = true;
			} else if (tl > 0 && piste >= 1 && kilp.pv[vaihe].va) {
				int ix = ApiVaIx(piste);
				if (ix >= 1 && ix <= kilpparam.valuku + 1) {
					kilp.pv[vaihe].va[ix].vatulos = tl;
					muuttui = true;
				}
			}
		} else if (tyyppi == L"sakko") {
			UnicodeString sak;
			if (!ApiJsonFindString(o, L"asakot", sak))
				ApiJsonFindString(o, L"ampsakot", sak);
			if (!sak.IsEmpty() && ApiAsetaSakko(kilp, vaihe, sak.c_str()))
				muuttui = true;
		}
		if (muuttui) {
			if (uusi) {
				int nd = 0;
				kilp.addtall(&nd, 0);
			} else {
				ApiTallennaApi(kilp, d);
			}
			likainen = true;
		}
	} __finally {
		ApiSovellusLoppuu();
		LeaveCriticalSection(&tall_CriticalSection);
	}
	if (likainen)
		gOsanottajaLika = 1;
}
