#include <vcl.h>
#pragma hdrstop
#include <wininet.h>
#include <stdio.h>
#include <vector>

#include "ApiYhteydet.h"
#include "ApiJson.h"
#include "ApiSaike.h"
#include "ApiIntegration.h"

#pragma comment(lib, "wininet.lib")

#pragma package(smart_init)
#pragma resource "*.dfm"

TFormApiYhteydet *FormApiYhteydet;

apiconfigtp apiconfig;

static UnicodeString PolkuKansioon(UnicodeString dir)
{
	if (dir.IsEmpty())
		return dir;
	wchar_t last = dir[dir.Length()];
	if (last != L'\\' && last != L'/')
		dir += L"\\";
	return dir;
}

UnicodeString ApiConfigExePolku(void)
{
	wchar_t buf[MAX_PATH];
	GetModuleFileNameW(NULL, buf, MAX_PATH);
	return PolkuKansioon(ExtractFilePath(UnicodeString(buf))) + L"jahonline_api.ini";
}

UnicodeString ApiConfigPolku(void)
{
	return PolkuKansioon(GetCurrentDir()) + L"jahonline_api.ini";
}

static void ApiConfigLueTiedostosta(const UnicodeString& polku)
{
	wchar_t tmp[512];
	GetPrivateProfileStringW(L"jahonline", L"url", apiconfig.apiUrl, tmp, 512, polku.c_str());
	wcsncpy(apiconfig.apiUrl, tmp, sizeof(apiconfig.apiUrl)/2 - 1);
	apiconfig.apiUrl[sizeof(apiconfig.apiUrl)/2 - 1] = 0;
	GetPrivateProfileStringW(L"jahonline", L"api_key", L"", tmp, 512, polku.c_str());
	wcsncpy(apiconfig.apiKey, tmp, sizeof(apiconfig.apiKey)/2 - 1);
	apiconfig.apiKey[sizeof(apiconfig.apiKey)/2 - 1] = 0;
	apiconfig.apiPort = GetPrivateProfileIntW(L"jahonline", L"port", 0, polku.c_str());
	apiconfig.kilpailuId = GetPrivateProfileIntW(L"jahonline", L"kilpailu_id", 0, polku.c_str());
	GetPrivateProfileStringW(L"jahonline", L"kilpailu_nimi", L"", tmp, 160, polku.c_str());
	wcsncpy(apiconfig.kilpailuNimi, tmp, sizeof(apiconfig.kilpailuNimi)/2 - 1);
	apiconfig.kilpailuNimi[sizeof(apiconfig.kilpailuNimi)/2 - 1] = 0;
	GetPrivateProfileStringW(L"jahonline", L"kilpailu_luonne", L"", tmp, 24, polku.c_str());
	wcsncpy(apiconfig.kilpailuLuonne, tmp, sizeof(apiconfig.kilpailuLuonne)/2 - 1);
	apiconfig.kilpailuLuonne[sizeof(apiconfig.kilpailuLuonne)/2 - 1] = 0;
	apiconfig.lahetysvali = GetPrivateProfileIntW(L"jahonline", L"vali", 10, polku.c_str());
	apiconfig.lahetaKilpailijat = GetPrivateProfileIntW(L"jahonline", L"laheta_kilpailijat", 1, polku.c_str());
	apiconfig.vastaanottaKilpailijat = GetPrivateProfileIntW(L"jahonline", L"vastaanotta_kilpailijat", 1, polku.c_str());
	apiconfig.lahetaValiajat = GetPrivateProfileIntW(L"jahonline", L"laheta_valiajat", 1, polku.c_str());
	apiconfig.vastaanottaValiajat = GetPrivateProfileIntW(L"jahonline", L"vastaanotta_valiajat", 1, polku.c_str());
	apiconfig.lahetaTulokset = GetPrivateProfileIntW(L"jahonline", L"laheta_tulokset", 1, polku.c_str());
	apiconfig.vastaanottaEiLahteneet = GetPrivateProfileIntW(L"jahonline", L"vastaanotta_dns", 1, polku.c_str());
	apiconfig.kaynnissa = GetPrivateProfileIntW(L"jahonline", L"kaynnissa", 0, polku.c_str());
	apiconfig.siirtotapa = GetPrivateProfileIntW(L"jahonline", L"siirtotapa", 0, polku.c_str());
	if (apiconfig.siirtotapa != 1)
		apiconfig.siirtotapa = 0;
	apiconfig.numerotSelaimesta = GetPrivateProfileIntW(L"jahonline", L"numerot_selaimesta", 0, polku.c_str()) ? 1 : 0;
	GetPrivateProfileStringW(L"jahonline", L"asema", L"", tmp, 64, polku.c_str());
	wcsncpy(apiconfig.asema, tmp, sizeof(apiconfig.asema)/2 - 1);
	apiconfig.asema[sizeof(apiconfig.asema)/2 - 1] = 0;
	apiconfig.tapahtumaCursorKilpailu = GetPrivateProfileIntW(L"jahonline", L"tapahtuma_cursor_kilpailu", 0, polku.c_str());
	apiconfig.tapahtumaCursor = GetPrivateProfileIntW(L"jahonline", L"tapahtuma_cursor", 0, polku.c_str());
	if (apiconfig.tapahtumaCursorKilpailu != apiconfig.kilpailuId)
		apiconfig.tapahtumaCursor = 0;
	if (apiconfig.lahetysvali < 2)
		apiconfig.lahetysvali = 2;
}

void ApiConfigNollaa(void)
{
	memset(&apiconfig, 0, sizeof(apiconfig));
	wcsncpy(apiconfig.apiUrl,
		L"https://jahonline.com/public/api/kilpailijat_bridge.php",
		sizeof(apiconfig.apiUrl)/2 - 1);
	apiconfig.apiPort = 0;
	apiconfig.lahetysvali = 10;
	apiconfig.lahetaKilpailijat = 1;
	apiconfig.vastaanottaKilpailijat = 1;
	apiconfig.lahetaValiajat = 1;
	apiconfig.vastaanottaValiajat = 1;
	apiconfig.lahetaTulokset = 1;
}

void ApiConfigLataa(void)
{
	ApiConfigNollaa();
	UnicodeString polku = ApiConfigPolku();
	UnicodeString exe = ApiConfigExePolku();
	if (!FileExists(polku) && polku.CompareIC(exe) != 0 && FileExists(exe)) {
		CopyFileW(exe.c_str(), polku.c_str(), TRUE);
		if (!FileExists(polku))
			polku = exe;
	}
	if (FileExists(polku))
		ApiConfigLueTiedostosta(polku);
	if (apiconfig.apiKey[0] == 0) {
		UnicodeString muisti = ApiMuistiPolku();
		wchar_t tmp[512];
		GetPrivateProfileStringW(L"yhteys", L"api_key", L"", tmp, 512, muisti.c_str());
		if (tmp[0] != 0) {
			wcsncpy(apiconfig.apiKey, tmp, sizeof(apiconfig.apiKey)/2 - 1);
			apiconfig.apiKey[sizeof(apiconfig.apiKey)/2 - 1] = 0;
			GetPrivateProfileStringW(L"yhteys", L"url", apiconfig.apiUrl, tmp, 512, muisti.c_str());
			wcsncpy(apiconfig.apiUrl, tmp, sizeof(apiconfig.apiUrl)/2 - 1);
			apiconfig.apiUrl[sizeof(apiconfig.apiUrl)/2 - 1] = 0;
		}
	}
}

void ApiConfigTallenna(void)
{
	UnicodeString polku = ApiConfigPolku();
	WritePrivateProfileStringW(L"jahonline", L"url", apiconfig.apiUrl, polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"api_key", apiconfig.apiKey, polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"port", IntToStr(apiconfig.apiPort).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"kilpailu_id", IntToStr(apiconfig.kilpailuId).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"kilpailu_nimi", apiconfig.kilpailuNimi, polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"kilpailu_luonne", apiconfig.kilpailuLuonne, polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"vali", IntToStr(apiconfig.lahetysvali).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"laheta_kilpailijat", IntToStr(apiconfig.lahetaKilpailijat).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"vastaanotta_kilpailijat", IntToStr(apiconfig.vastaanottaKilpailijat).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"laheta_valiajat", IntToStr(apiconfig.lahetaValiajat).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"vastaanotta_valiajat", IntToStr(apiconfig.vastaanottaValiajat).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"laheta_tulokset", IntToStr(apiconfig.lahetaTulokset).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"vastaanotta_dns", IntToStr(apiconfig.vastaanottaEiLahteneet).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"kaynnissa", IntToStr(apiconfig.kaynnissa).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"siirtotapa", IntToStr(apiconfig.siirtotapa).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"numerot_selaimesta", IntToStr(apiconfig.numerotSelaimesta).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"asema", apiconfig.asema, polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"tapahtuma_cursor", IntToStr(apiconfig.tapahtumaCursor).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"tapahtuma_cursor_kilpailu", IntToStr(apiconfig.kilpailuId).c_str(), polku.c_str());
}

UnicodeString ApiBridgeUrl(void)
{
	UnicodeString url = apiconfig.apiUrl;
	url = StringReplace(url, L" ", L"", TReplaceFlags() << rfReplaceAll);
	if (url.IsEmpty())
		url = L"https://jahonline.com/public/api/kilpailijat_bridge.php";

	// If only host given, append bridge path
	if (url.Pos(L"/api/") == 0 && url.Pos(L"pirila_bridge") == 0 && url.Pos(L"kilpailijat_bridge") == 0) {
		if (url[url.Length()] == L'/')
			url += L"public/api/kilpailijat_bridge.php";
		else
			url += L"/public/api/kilpailijat_bridge.php";
	}

	// Optional port override when URL has no explicit port and apiPort > 0
	if (apiconfig.apiPort > 0 && url.Pos(L"://") > 0) {
		// leave as-is if user already put :port in URL
		UnicodeString after = url.SubString(url.Pos(L"://") + 3, url.Length());
		if (after.Pos(L":") == 0) {
			int slash = after.Pos(L"/");
			UnicodeString host = slash > 0 ? after.SubString(1, slash - 1) : after;
			UnicodeString path = slash > 0 ? after.SubString(slash, after.Length()) : UnicodeString(L"");
			UnicodeString scheme = url.SubString(1, url.Pos(L"://") + 2);
			url = scheme + host + L":" + IntToStr(apiconfig.apiPort) + path;
		}
	}
	return url;
}

// Avoid WinINet URL_COMPONENTSW: bcc32 sees both the SDK type and
// Winapi::Wininet::URL_COMPONENTSW and reports E2015.
static bool ParseHttpUrl(const UnicodeString& url, UnicodeString& host,
	INTERNET_PORT& port, UnicodeString& path, bool& https)
{
	UnicodeString u = url.Trim();
	https = false;
	port = INTERNET_DEFAULT_HTTP_PORT;
	host = L"";
	path = L"/";

	int prefix = 0;
	if (u.Pos(L"https://") == 1) {
		https = true;
		port = INTERNET_DEFAULT_HTTPS_PORT;
		prefix = 8;
	} else if (u.Pos(L"http://") == 1) {
		prefix = 7;
	} else {
		return false;
	}

	UnicodeString rest = u.SubString(prefix + 1, u.Length() - prefix);
	int hash = rest.Pos(L"#");
	if (hash > 0)
		rest = rest.SubString(1, hash - 1);

	int slash = rest.Pos(L"/");
	int qmark = rest.Pos(L"?");
	int hostEnd = rest.Length() + 1;
	if (slash > 0 && (qmark == 0 || slash < qmark))
		hostEnd = slash;
	else if (qmark > 0)
		hostEnd = qmark;

	UnicodeString hostport = hostEnd > 1 ? rest.SubString(1, hostEnd - 1) : UnicodeString(L"");
	if (hostEnd <= rest.Length())
		path = rest.SubString(hostEnd, rest.Length() - hostEnd + 1);
	else
		path = L"/";
	if (path.IsEmpty() || path[1] == L'?')
		path = UnicodeString(L"/") + path;

	int colon = hostport.LastDelimiter(L":");
	if (colon > 0 && colon < hostport.Length()) {
		host = hostport.SubString(1, colon - 1);
		port = (INTERNET_PORT)_wtoi(hostport.SubString(colon + 1, hostport.Length() - colon).c_str());
		if (port == 0)
			port = https ? INTERNET_DEFAULT_HTTPS_PORT : INTERNET_DEFAULT_HTTP_PORT;
	} else {
		host = hostport;
	}
	return !host.IsEmpty();
}

static bool HttpRequest(const wchar_t* method, const UnicodeString& url,
	const UnicodeString& body, UnicodeString& vastaus)
{
	vastaus = L"";
	HINTERNET hInternet = InternetOpenW(L"Tulospalvelu-JAHOnline/1.0", INTERNET_OPEN_TYPE_PRECONFIG, NULL, NULL, 0);
	if (!hInternet)
		return false;

	UnicodeString host, path;
	INTERNET_PORT port = INTERNET_DEFAULT_HTTP_PORT;
	bool https = false;
	if (!ParseHttpUrl(url, host, port, path, https)) {
		InternetCloseHandle(hInternet);
		return false;
	}

	DWORD flags = INTERNET_FLAG_RELOAD | INTERNET_FLAG_NO_CACHE_WRITE;
	if (https)
		flags |= INTERNET_FLAG_SECURE;

	HINTERNET hConnect = InternetConnectW(hInternet, host.c_str(), port, NULL, NULL, INTERNET_SERVICE_HTTP, 0, 0);
	if (!hConnect) {
		InternetCloseHandle(hInternet);
		return false;
	}

	const wchar_t* acceptTypes[] = { L"*/*", NULL };
	HINTERNET hRequest = HttpOpenRequestW(hConnect, method, path.c_str(), NULL, NULL, acceptTypes, flags, 0);
	if (!hRequest) {
		InternetCloseHandle(hConnect);
		InternetCloseHandle(hInternet);
		return false;
	}

	UnicodeString headers = L"Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n";
	if (apiconfig.apiKey[0] != 0) {
		headers += L"Authorization: Bearer ";
		headers += apiconfig.apiKey;
		headers += L"\r\n";
		headers += L"X-API-Key: ";
		headers += apiconfig.apiKey;
		headers += L"\r\n";
	}
	HttpAddRequestHeadersW(hRequest, headers.c_str(), -1, HTTP_ADDREQ_FLAG_ADD | HTTP_ADDREQ_FLAG_REPLACE);

	AnsiString bodyUtf8 = ApiWideToUtf8(body);
	BOOL ok = HttpSendRequestW(hRequest, NULL, 0,
		body.IsEmpty() ? NULL : (LPVOID)bodyUtf8.c_str(),
		body.IsEmpty() ? 0 : bodyUtf8.Length());

	if (ok) {
		char buf[4096];
		DWORD n = 0;
		AnsiString raw;
		while (InternetReadFile(hRequest, buf, sizeof(buf) - 1, &n) && n > 0) {
			buf[n] = 0;
			raw += AnsiString(buf, n);
		}
		vastaus = ApiUtf8ToWide(raw);
	}

	InternetCloseHandle(hRequest);
	InternetCloseHandle(hConnect);
	InternetCloseHandle(hInternet);
	return ok != FALSE;
}

bool ApiHttpPostJson(const UnicodeString& url, const UnicodeString& jsonBody, UnicodeString& vastaus)
{
	return HttpRequest(L"POST", url, jsonBody, vastaus);
}

bool ApiHttpGetAuth(const UnicodeString& url, UnicodeString& vastaus)
{
	return HttpRequest(L"GET", url, L"", vastaus);
}


static UnicodeString ApiLahdeNimi(void)
{
#ifdef JUKOLA
	return L"ViestiWin";
#else
	return L"HkKisaWin";
#endif
}

static UnicodeString ApiOletusTyyppi(void)
{
#ifdef JUKOLA
	return L"viesti";
#else
	return L"yksilo";
#endif
}

// POST body for listing the user's Pirila competitions (no kilpailu_id).
UnicodeString ApiKilpailutPyynto(void)
{
	return UnicodeString(L"{\"action\":\"kilpailut\",\"lahde\":\"")
		+ ApiLahdeNimi()
		+ L"\",\"tyyppi\":\""
		+ ApiOletusTyyppi()
		+ L"\",\"pirila\":true}";
}

UnicodeString ApiPaikallinenKello(void)
{
	SYSTEMTIME st;
	wchar_t buf[24];

	GetLocalTime(&st);
	swprintf(buf, L"%04d-%02d-%02d %02d:%02d:%02d",
		(int) st.wYear, (int) st.wMonth, (int) st.wDay,
		(int) st.wHour, (int) st.wMinute, (int) st.wSecond);
	return UnicodeString(buf);
}

// Lajikoodit kuten Kilpailun m\xe4\xe4ritykset -kaavakkeessa (lajit "SHJAUM", muutlajit "PNB").
UnicodeString ApiLuonneLajista(wchar_t laji)
{
	switch (laji) {
		case L'S': return L"suunnistus";
		case L'H': return L"hiihto";
		case L'J': return L"juoksu";
		case L'A': return L"ampumahiihto";
		case L'U': return L"uinti";
		case L'B': return L"ampumasuunnistus";
		case L'P': return L"sotilas5ottelu";
		case L'N': return L"meri5ottelu";
	}
	return L"muu";
}

UnicodeString ApiLuonneNimi(const UnicodeString& luonne)
{
	static const wchar_t *koodit[] = {
		L"suunnistus", L"hiihto", L"juoksu", L"ampumahiihto", L"uinti",
		L"ampumasuunnistus", L"sotilas5ottelu", L"meri5ottelu", L"muu"
	};
	static const wchar_t *nimet[] = {
		L"Suunnistus", L"Hiihto", L"Juoksu/h\xf6lkk\xe4", L"Ampumahiihto", L"Uinti",
		L"Ampumasuunnistus", L"Sotilas-5-ottelu", L"Meri-5-ottelu", L"Muu"
	};
	for (int i = 0; i < (int)(sizeof(koodit)/sizeof(koodit[0])); i++)
		if (luonne.CompareIC(koodit[i]) == 0)
			return nimet[i];
	return luonne;
}

static UnicodeString LajiKirjain(wchar_t laji)
{
	wchar_t st[2] = {laji, 0};
	return UnicodeString(st);
}

UnicodeString ApiMuistiPolku(void)
{
	return ExtractFilePath(ApiConfigExePolku()) + L"jahonline_yhdistetyt.ini";
}

static UnicodeString MuistiOsio(int id)
{
	return L"kilpailu_" + IntToStr(id);
}

static void MuistiKirjoita(const UnicodeString& osio, const wchar_t *avain, const UnicodeString& arvo)
{
	if (!arvo.IsEmpty())
		WritePrivateProfileStringW(osio.c_str(), avain, arvo.c_str(), ApiMuistiPolku().c_str());
}

void ApiMuistaYhdistetty(int id, const UnicodeString& nimi, const UnicodeString& pvm,
	const UnicodeString& luonne)
{
	if (id <= 0)
		return;
	UnicodeString osio = MuistiOsio(id);
	MuistiKirjoita(osio, L"tyyppi", ApiOletusTyyppi());
	MuistiKirjoita(osio, L"nimi", nimi);
	MuistiKirjoita(osio, L"pvm", pvm);
	MuistiKirjoita(osio, L"luonne", luonne);
	MuistiKirjoita(osio, L"kansio", GetCurrentDir());
	MuistiKirjoita(osio, L"yhdistetty", ApiPaikallinenKello());
	MuistiKirjoita(L"yhteys", L"url", apiconfig.apiUrl);
	MuistiKirjoita(L"yhteys", L"api_key", apiconfig.apiKey);
}

// Server list is authoritative for names; keep the folder link of a remembered entry.
static void MuistiPaivitaJosOn(int id, const UnicodeString& nimi, const UnicodeString& pvm,
	const UnicodeString& luonne)
{
	wchar_t tmp[32];
	UnicodeString osio = MuistiOsio(id);
	GetPrivateProfileStringW(osio.c_str(), L"tyyppi", L"", tmp, 32, ApiMuistiPolku().c_str());
	if (tmp[0] == 0)
		return;
	MuistiKirjoita(osio, L"nimi", nimi);
	MuistiKirjoita(osio, L"pvm", pvm);
	MuistiKirjoita(osio, L"luonne", luonne);
}

static bool KilpailuOnPirila(const UnicodeString& obj)
{
	bool v;
	if (ApiJsonFindBool(obj, L"pirila", v))
		return v;
	if (ApiJsonFindBool(obj, L"pirila_kilpailu", v))
		return v;
	return true;
}

static bool KilpailuTyyppiSopii(const UnicodeString& tyyppi)
{
	if (tyyppi.IsEmpty())
		return true;
#ifdef JUKOLA
	return tyyppi.CompareIC(L"viesti") == 0;
#else
	return tyyppi.CompareIC(L"viesti") != 0;
#endif
}

static UnicodeString KilpailuListateksti(int id, const UnicodeString& nimi, const UnicodeString& pvm)
{
	UnicodeString t = IntToStr(id);
	if (!nimi.IsEmpty())
		t += L" - " + nimi;
	if (!pvm.IsEmpty())
		t += L" (" + pvm + L")";
	return t;
}

static int ComboIndeksi(TComboBox *cb, int id)
{
	for (int i = 0; i < cb->Items->Count; i++)
		if (_wtoi(cb->Items->Strings[i].c_str()) == id)
			return i;
	return -1;
}

__fastcall TFormApiYhteydet::TFormApiYhteydet(TComponent* Owner)
	: TForm(Owner)
{
	yhteysOk = false;
	Scaled = false;
	if (Screen->PixelsPerInch != 96)
		ScaleBy(Screen->PixelsPerInch, 96);
}

void __fastcall TFormApiYhteydet::FormShow(TObject *Sender)
{
	ApiConfigLataa();
	LueTiedot();
	PaivitaTila(L"Asetukset: " + ApiConfigPolku());
	PaivitaTila(L"Hae kilpailut listaa netin Piril\xe4-kilpailut k\xe4ytt\xe4j\xe4" L"avaimella.");
	if (!TApiIntegration::GetInstance()->OnKilpailuAvattu())
		PaivitaTila(L"Kilpailua ei ole avattu - synkkaa ei käynnistetä ennen kuin kisa on auki.");
	else
		PaivitaTila(L"Asetukset ladattu. Yhdist\xe4 k\xe4ynnist\xe4\xe4 synkan, Lopeta synkka pys\xe4ytt\xe4\xe4 sen.");
}

void __fastcall TFormApiYhteydet::LueTiedot(void)
{
	EditUrl->Text = apiconfig.apiUrl;
	EditPortti->Text = IntToStr(apiconfig.apiPort);
	EditApiKey->Text = apiconfig.apiKey;
	EditKilpailuId->Text = IntToStr(apiconfig.kilpailuId);
	NaytaValittuKilpailu();
	CBLahetaKilpailijat->Checked = apiconfig.lahetaKilpailijat != 0;
	CBVastaanottaKilpailijat->Checked = apiconfig.vastaanottaKilpailijat != 0;
	CBLahetaValiajat->Checked = apiconfig.lahetaValiajat != 0;
	CBVastaanottaValiajat->Checked = apiconfig.vastaanottaValiajat != 0;
	CBLahetaTulokset->Checked = apiconfig.lahetaTulokset != 0;
	CBVastaanottaEiLahteneet->Checked = apiconfig.vastaanottaEiLahteneet != 0;
	EditLahetysvali->Text = IntToStr(apiconfig.lahetysvali);
	if (ComboSiirtotapa)
		ComboSiirtotapa->ItemIndex = apiconfig.siirtotapa == 1 ? 1 : 0;
	if (EditAsema)
		EditAsema->Text = apiconfig.asema;
	if (CBNumerotSelaimesta)
		CBNumerotSelaimesta->Checked = apiconfig.numerotSelaimesta != 0;
	NaytaYhteysTila();
	if (LabelIniPolku)
		LabelIniPolku->Caption = L"Asetustiedosto (kilpailun kansio):\r\n" + ApiConfigPolku();
}

void __fastcall TFormApiYhteydet::KirjoitaTiedot(void)
{
	wcsncpy(apiconfig.apiUrl, EditUrl->Text.c_str(), sizeof(apiconfig.apiUrl)/2 - 1);
	apiconfig.apiUrl[sizeof(apiconfig.apiUrl)/2 - 1] = 0;
	apiconfig.apiPort = _wtoi(EditPortti->Text.c_str());
	wcsncpy(apiconfig.apiKey, EditApiKey->Text.c_str(), sizeof(apiconfig.apiKey)/2 - 1);
	apiconfig.apiKey[sizeof(apiconfig.apiKey)/2 - 1] = 0;
	{
		int cid = ComboKilpailuId();
		if (cid <= 0)
			cid = _wtoi(EditKilpailuId->Text.c_str());
		if (cid != apiconfig.kilpailuId)
			apiconfig.tapahtumaCursor = 0;
		apiconfig.kilpailuId = cid;
		if (ComboKilpailu && ComboKilpailu->ItemIndex >= 0) {
			UnicodeString s = ComboKilpailu->Items->Strings[ComboKilpailu->ItemIndex];
			int dash = s.Pos(L" - ");
			UnicodeString nimi = dash > 0 ? s.SubString(dash + 3, s.Length()) : UnicodeString();
			int par = nimi.Pos(L" (");
			if (par > 0)
				nimi = nimi.SubString(1, par - 1);
			wcsncpy(apiconfig.kilpailuNimi, nimi.c_str(), sizeof(apiconfig.kilpailuNimi)/2 - 1);
			apiconfig.kilpailuNimi[sizeof(apiconfig.kilpailuNimi)/2 - 1] = 0;
			if (ComboKilpailu->ItemIndex < (int)comboLuonne.size()) {
				wcsncpy(apiconfig.kilpailuLuonne, comboLuonne[ComboKilpailu->ItemIndex].c_str(),
					sizeof(apiconfig.kilpailuLuonne)/2 - 1);
				apiconfig.kilpailuLuonne[sizeof(apiconfig.kilpailuLuonne)/2 - 1] = 0;
			}
		}
	}
	apiconfig.lahetaKilpailijat = CBLahetaKilpailijat->Checked ? 1 : 0;
	apiconfig.vastaanottaKilpailijat = CBVastaanottaKilpailijat->Checked ? 1 : 0;
	apiconfig.lahetaValiajat = CBLahetaValiajat->Checked ? 1 : 0;
	apiconfig.vastaanottaValiajat = CBVastaanottaValiajat->Checked ? 1 : 0;
	apiconfig.lahetaTulokset = CBLahetaTulokset->Checked ? 1 : 0;
	apiconfig.vastaanottaEiLahteneet = CBVastaanottaEiLahteneet->Checked ? 1 : 0;
	apiconfig.lahetysvali = _wtoi(EditLahetysvali->Text.c_str());
	if (ComboSiirtotapa)
		apiconfig.siirtotapa = ComboSiirtotapa->ItemIndex == 1 ? 1 : 0;
	if (EditAsema) {
		wcsncpy(apiconfig.asema, EditAsema->Text.c_str(), sizeof(apiconfig.asema)/2 - 1);
		apiconfig.asema[sizeof(apiconfig.asema)/2 - 1] = 0;
	}
	if (CBNumerotSelaimesta)
		apiconfig.numerotSelaimesta = CBNumerotSelaimesta->Checked ? 1 : 0;
	if (apiconfig.lahetysvali < 2)
		apiconfig.lahetysvali = 2;
}

void __fastcall TFormApiYhteydet::NaytaYhteysTila(void)
{
	bool kay = apiconfig.kaynnissa != 0
		&& TApiIntegration::GetInstance()->OnAktiivinen()
		&& TApiIntegration::GetInstance()->OnKilpailuAvattu();
	if (kay) {
		LabelYhteysTila->Caption = L"AKTIIVINEN";
		LabelYhteysTila->Font->Color = clGreen;
	} else {
		LabelYhteysTila->Caption = L"EI AKTIIVINEN";
		LabelYhteysTila->Font->Color = clRed;
	}
	if (BtnLopetaSynkka)
		BtnLopetaSynkka->Enabled = kay
			|| TApiIntegration::GetInstance()->OnAktiivinen();
	if (kay)
		yhteysOk = true;
	NaytaLuoNappi();
}

// Luo uusi -painike n\xe4kyy, kun JAHOnline on vastannut API-avaimella
// (Hae kilpailut tai Yhdist\xe4 onnistui), my\xf6s jos k\xe4ytt\xe4j\xe4ll\xe4 ei viel\xe4 ole kisoja.
void __fastcall TFormApiYhteydet::NaytaLuoNappi(void)
{
	if (BtnLuoKilpailu)
		BtnLuoKilpailu->Visible = yhteysOk;
}

void __fastcall TFormApiYhteydet::NaytaLuonne(void)
{
	if (!LabelLuonne)
		return;
	UnicodeString netti;
	if (ComboKilpailu && ComboKilpailu->ItemIndex >= 0
		&& ComboKilpailu->ItemIndex < (int)comboLuonne.size())
		netti = comboLuonne[ComboKilpailu->ItemIndex];
	UnicodeString t;
	bool ero = false;
	if (TApiIntegration::GetInstance()->OnKilpailuAvattu()) {
		UnicodeString oma = ApiLuonneLajista(ApiPirilaLaji());
		t = L"Laji Piril\xe4ss\xe4: " + ApiLuonneNimi(oma);
		ero = !netti.IsEmpty() && netti.CompareIC(oma) != 0;
	}
	if (!netti.IsEmpty()) {
		if (!t.IsEmpty())
			t += L"   ";
		t += L"Luonne netiss\xe4: " + ApiLuonneNimi(netti);
	}
	LabelLuonne->Caption = t;
	LabelLuonne->Font->Color = ero ? clRed : clWindowText;
}

void __fastcall TFormApiYhteydet::PaivitaTila(UnicodeString viesti)
{
	UnicodeString aika = FormatDateTime(L"hh:nn:ss", Now());
	MemoTila->Lines->Add(L"[" + aika + L"] " + viesti);
	while (MemoTila->Lines->Count > 200)
		MemoTila->Lines->Delete(0);
	MemoTila->SelStart = MemoTila->GetTextLen();
}

void __fastcall TFormApiYhteydet::Yhdista(void)
{
	KirjoitaTiedot();
	ApiConfigTallenna();

	if (!TApiIntegration::GetInstance()->OnKilpailuAvattu()) {
		PaivitaTila(L"VIRHE: avaa kilpailu ensin. Synkkaa ei käynnistetä ilman avattua kisaa.");
		apiconfig.kaynnissa = 0;
		ApiConfigTallenna();
		NaytaYhteysTila();
		return;
	}
	if (apiconfig.apiKey[0] == 0) {
		PaivitaTila(L"VIRHE: aseta käyttäjäkohtainen API-avain");
		return;
	}
	if (apiconfig.kilpailuId <= 0) {
		PaivitaTila(L"VIRHE: hae kilpailut ja valitse yhdistettävä kilpailu listasta");
		return;
	}

	UnicodeString url = ApiBridgeUrl();
	UnicodeString kello = ApiJsonString(ApiPaikallinenKello());
	UnicodeString body = L"{\"action\":\"yhdista\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":" + ApiJsonString(ApiLahdeNimi())
		+ L",\"tyyppi\":" + ApiJsonString(ApiOletusTyyppi())
		+ L",\"asema\":" + ApiJsonString(ApiAsemaNimi())
		+ L",\"kansio\":" + ApiJsonString(GetCurrentDir())
		+ L",\"pirila_nimi\":" + ApiJsonString(ApiPirilaKilpailuNimi())
		+ L",\"laji\":" + ApiJsonString(LajiKirjain(ApiPirilaLaji()))
		+ L",\"luonne\":" + ApiJsonString(ApiLuonneLajista(ApiPirilaLaji()))
		+ L",\"kello\":" + kello + L"}";
	UnicodeString vastaus;
	PaivitaTila(L"POST yhdista -> " + url);

	if (!ApiHttpPostJson(url, body, vastaus)) {
		PaivitaTila(L"VIRHE: HTTP-pyyntö epäonnistui");
		LopetaSynkka();
		return;
	}

	if (!ApiJsonStatusOk(vastaus)) {
		// Palvelin ilman yhdista-toimintoa: ping riitt\xe4\xe4 yhteyteen, muisti j\xe4\xe4 vain t\xe4lle koneelle.
		UnicodeString ping = L"{\"action\":\"ping\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
			+ L",\"kello\":" + kello + L"}";
		UnicodeString pingVastaus;
		if (ApiHttpPostJson(url, ping, pingVastaus) && ApiJsonStatusOk(pingVastaus)) {
			PaivitaTila(L"Palvelin ei hyv\xe4ksynyt yhdista-pyynt\xf6\xe4 (" + vastaus.SubString(1, 120)
				+ L"), yhteys toimii silti.");
			vastaus = pingVastaus;
		}
	}

	if (ApiJsonStatusOk(vastaus)) {
		PaivitaTila(L"OK: " + vastaus.SubString(1, 180));
		UnicodeString nimi, pvm, luonne;
		if (!ApiJsonFindString(vastaus, L"nimi", nimi))
			nimi = apiconfig.kilpailuNimi;
		ApiJsonFindString(vastaus, L"pvm", pvm);
		if (!ApiJsonFindString(vastaus, L"luonne", luonne))
			luonne = apiconfig.kilpailuLuonne;
		if (!nimi.IsEmpty()) {
			wcsncpy(apiconfig.kilpailuNimi, nimi.c_str(), sizeof(apiconfig.kilpailuNimi)/2 - 1);
			apiconfig.kilpailuNimi[sizeof(apiconfig.kilpailuNimi)/2 - 1] = 0;
		}
		wcsncpy(apiconfig.kilpailuLuonne, luonne.c_str(), sizeof(apiconfig.kilpailuLuonne)/2 - 1);
		apiconfig.kilpailuLuonne[sizeof(apiconfig.kilpailuLuonne)/2 - 1] = 0;
		ApiMuistaYhdistetty(apiconfig.kilpailuId, nimi, pvm, luonne);
		LisaaComboon(apiconfig.kilpailuId, nimi, pvm, luonne);
		ComboKilpailu->ItemIndex = ComboIndeksi(ComboKilpailu, apiconfig.kilpailuId);
		NaytaLuonne();
		UnicodeString oma = ApiLuonneLajista(ApiPirilaLaji());
		if (!luonne.IsEmpty() && luonne.CompareIC(oma) != 0)
			PaivitaTila(L"HUOM: netin kisan luonne on " + ApiLuonneNimi(luonne)
				+ L", Piril\xe4ss\xe4 laji on " + ApiLuonneNimi(oma) + L".");
		yhteysOk = true;
		apiconfig.kaynnissa = 1;
		ApiConfigTallenna();
		TApiIntegration::GetInstance()->Alusta();
		NaytaYhteysTila();
		PaivitaTila(L"Yhdistetty. Synkka käynnissä. Lopeta synkka -nappi pysäyttää sen.");
	} else {
		PaivitaTila(L"VIRHE vastaus: " + vastaus.SubString(1, 240));
		LopetaSynkka();
	}
}

void __fastcall TFormApiYhteydet::LopetaSynkka(void)
{
	apiconfig.kaynnissa = 0;
	ApiConfigTallenna();
	TApiIntegration::GetInstance()->Lopeta();
	bool jatkuu = false;
	if (apiconfig.siirtotapa == 1 && TApiIntegration::GetInstance()->OnKilpailuAvattu()
		&& apiconfig.kilpailuId > 0 && apiconfig.apiKey[0] != 0) {
		TApiIntegration::GetInstance()->Alusta();
		jatkuu = true;
		PaivitaTila(L"T\xe4yssynkka pys\xe4ytetty. JAHOnline-siirto ty\xf6" L"asemien v\xe4lill\xe4 jatkuu.");
	}
	NaytaYhteysTila();
	if (!jatkuu)
		PaivitaTila(L"Synkka lopetettu.");
}


int __fastcall TFormApiYhteydet::ComboKilpailuId(void)
{
	if (!ComboKilpailu || ComboKilpailu->ItemIndex < 0)
		return 0;
	return _wtoi(ComboKilpailu->Items->Strings[ComboKilpailu->ItemIndex].c_str());
}

void __fastcall TFormApiYhteydet::LisaaComboon(int id, const UnicodeString& nimi,
	const UnicodeString& pvm, const UnicodeString& luonne)
{
	UnicodeString t = KilpailuListateksti(id, nimi, pvm);
	int ix = ComboIndeksi(ComboKilpailu, id);
	if (ix >= 0) {
		if (!nimi.IsEmpty())
			ComboKilpailu->Items->Strings[ix] = t;
		if (!luonne.IsEmpty() && ix < (int)comboLuonne.size())
			comboLuonne[ix] = luonne;
		return;
	}
	ComboKilpailu->Items->Add(t);
	comboLuonne.push_back(luonne);
}

// Combo shows the competitions this machine has connected to. A new local
// competition folder picks the JAHOnline kisa last connected from that folder.
void __fastcall TFormApiYhteydet::NaytaValittuKilpailu(void)
{
	if (!ComboKilpailu)
		return;
	ComboKilpailu->Items->BeginUpdate();
	ComboKilpailu->Items->Clear();
	comboLuonne.clear();

	UnicodeString polku = ApiMuistiPolku();
	UnicodeString kansio = GetCurrentDir();
	int kansionKisa = 0;
	if (FileExists(polku)) {
		std::vector<wchar_t> osiot(32768);
		DWORD n = GetPrivateProfileSectionNamesW(&osiot[0], (DWORD)osiot.size(), polku.c_str());
		for (DWORD i = 0; i < n && osiot[i] != 0; i += wcslen(&osiot[i]) + 1) {
			UnicodeString osio = &osiot[i];
			if (osio.Pos(L"kilpailu_") != 1)
				continue;
			int id = _wtoi(osio.SubString(10, osio.Length()).c_str());
			if (id <= 0)
				continue;
			wchar_t tmp[512];
			GetPrivateProfileStringW(osio.c_str(), L"tyyppi", L"", tmp, 512, polku.c_str());
			if (!KilpailuTyyppiSopii(tmp))
				continue;
			GetPrivateProfileStringW(osio.c_str(), L"nimi", L"", tmp, 512, polku.c_str());
			UnicodeString nimi = tmp;
			GetPrivateProfileStringW(osio.c_str(), L"pvm", L"", tmp, 512, polku.c_str());
			UnicodeString pvm = tmp;
			GetPrivateProfileStringW(osio.c_str(), L"luonne", L"", tmp, 512, polku.c_str());
			UnicodeString luonne = tmp;
			GetPrivateProfileStringW(osio.c_str(), L"kansio", L"", tmp, 512, polku.c_str());
			if (kansio.CompareIC(tmp) == 0)
				kansionKisa = id;
			LisaaComboon(id, nimi, pvm, luonne);
		}
	}
	if (apiconfig.kilpailuId <= 0 && kansionKisa > 0) {
		apiconfig.kilpailuId = kansionKisa;
		if (EditKilpailuId)
			EditKilpailuId->Text = IntToStr(kansionKisa);
	}
	if (apiconfig.kilpailuId > 0 && ComboIndeksi(ComboKilpailu, apiconfig.kilpailuId) < 0)
		LisaaComboon(apiconfig.kilpailuId, apiconfig.kilpailuNimi, L"", apiconfig.kilpailuLuonne);
	ComboKilpailu->ItemIndex = ComboIndeksi(ComboKilpailu, apiconfig.kilpailuId);
	ComboKilpailu->Items->EndUpdate();
	NaytaLuonne();
}

void __fastcall TFormApiYhteydet::ComboKilpailuChange(TObject *Sender)
{
	int id = ComboKilpailuId();
	if (id > 0 && EditKilpailuId)
		EditKilpailuId->Text = IntToStr(id);
	NaytaLuonne();
}

void __fastcall TFormApiYhteydet::BtnHaeKilpailutClick(TObject *Sender)
{
	HaeKilpailuLista();
}

// Fetch online Pirila competitions with the user API key and fill the combo.
void __fastcall TFormApiYhteydet::HaeKilpailuLista(void)
{
	KirjoitaTiedot();
	if (apiconfig.apiKey[0] == 0) {
		PaivitaTila(L"VIRHE: aseta k\xe4ytt\xe4j\xe4kohtainen API-avain (JAHOnline-k\xe4ytt\xe4j\xe4)");
		return;
	}

	UnicodeString url = ApiBridgeUrl();
	UnicodeString body = ApiKilpailutPyynto();
	UnicodeString vastaus;
	PaivitaTila(L"POST kilpailut -> " + url);

	if (!ApiHttpPostJson(url, body, vastaus)) {
		PaivitaTila(L"VIRHE: kilpailulistan haku ep\xe4onnistui");
		return;
	}
	if (!ApiJsonStatusOk(vastaus)) {
		PaivitaTila(L"VIRHE vastaus: " + vastaus.SubString(1, 240));
		return;
	}
	yhteysOk = true;
	NaytaLuoNappi();

	std::vector<UnicodeString> objects;
	int n = ApiJsonExtractObjectArray(vastaus, L"kilpailut", objects);
	if (n == 0)
		n = ApiJsonExtractObjectArray(vastaus, L"competitions", objects);

	if (!ComboKilpailu)
		return;
	int vanha = apiconfig.kilpailuId;
	if (vanha <= 0)
		vanha = _wtoi(EditKilpailuId->Text.c_str());

	ComboKilpailu->Items->BeginUpdate();
	ComboKilpailu->Items->Clear();
	comboLuonne.clear();
	int valitse = -1;
	int lisatty = 0;
	for (int i = 0; i < (int)objects.size(); i++) {
		UnicodeString obj = objects[i];
		if (!KilpailuOnPirila(obj))
			continue;
		UnicodeString tyyppi;
		ApiJsonFindString(obj, L"tyyppi", tyyppi);
		if (!KilpailuTyyppiSopii(tyyppi))
			continue;
		int id = 0;
		if (!ApiJsonFindInt(obj, L"id", id))
			ApiJsonFindInt(obj, L"kilpailu_id", id);
		if (id <= 0)
			continue;
		UnicodeString nimi, pvm;
		if (!ApiJsonFindString(obj, L"nimi", nimi)) {
			if (!ApiJsonFindString(obj, L"name", nimi))
				ApiJsonFindString(obj, L"otsikko", nimi);
		}
		if (!ApiJsonFindString(obj, L"pvm", pvm)) {
			if (!ApiJsonFindString(obj, L"pvm_str", pvm))
				ApiJsonFindString(obj, L"date", pvm);
		}
		UnicodeString luonne;
		ApiJsonFindString(obj, L"luonne", luonne);
		LisaaComboon(id, nimi, pvm, luonne);
		MuistiPaivitaJosOn(id, nimi, pvm, luonne);
		if (id == vanha)
			valitse = lisatty;
		lisatty++;
	}
	ComboKilpailu->Items->EndUpdate();

	if (lisatty == 0) {
		NaytaValittuKilpailu();
		PaivitaTila(L"Ei Piril\xe4-kilpailuja t\xe4lle k\xe4ytt\xe4j\xe4" L"avaimelle."
			L" Luo uusi JAHOnline-kisa -painike luo kisan netiss\xe4.");
		return;
	}
	if (valitse < 0 && lisatty == 1)
		valitse = 0;
	if (valitse >= 0) {
		ComboKilpailu->ItemIndex = valitse;
		ComboKilpailuChange(NULL);
	}
	NaytaLuonne();
	PaivitaTila(L"Kilpailuja listassa: " + IntToStr(lisatty) + L". Valitse yhdistett\xe4v\xe4 kilpailu.");
}

void __fastcall TFormApiYhteydet::BtnYhteysTestiClick(TObject *Sender)
{
	Yhdista();
}

void __fastcall TFormApiYhteydet::BtnLuoKilpailuClick(TObject *Sender)
{
	LuoKilpailu();
}

// Creates the JAHOnline competition from the open local one. The web menus
// follow luonne, so it comes from the Pirila sport (kilpparam.kilplaji).
void __fastcall TFormApiYhteydet::LuoKilpailu(void)
{
	KirjoitaTiedot();
	if (!TApiIntegration::GetInstance()->OnKilpailuAvattu()) {
		PaivitaTila(L"VIRHE: avaa ensin kilpailu, josta netin kisa luodaan.");
		return;
	}
	if (apiconfig.apiKey[0] == 0) {
		PaivitaTila(L"VIRHE: aseta k\xe4ytt\xe4j\xe4kohtainen API-avain");
		return;
	}
	UnicodeString nimi = ApiPirilaKilpailuNimi();
	if (!InputQuery(L"Luo uusi JAHOnline-kisa", L"Kilpailun nimi netiss\xe4:", nimi))
		return;
	nimi = nimi.Trim();
	if (nimi.IsEmpty()) {
		PaivitaTila(L"VIRHE: kilpailun nimi puuttuu");
		return;
	}
	wchar_t laji = ApiPirilaLaji();
	UnicodeString luonne = ApiLuonneLajista(laji);
	UnicodeString pvm = ApiPirilaPvm();
	UnicodeString kysymys = L"Luodaanko JAHOnlineen uusi kisa?\n\n"
		L"Nimi: " + nimi + L"\n"
		L"P\xe4iv\xe4m\xe4\xe4r\xe4: " + (pvm.IsEmpty() ? UnicodeString(L"-") : pvm) + L"\n"
		L"Luonne: " + ApiLuonneNimi(luonne) + L" (Piril\xe4n kilpailulaji)\n\n"
		L"Luonne m\xe4\xe4r\xe4\xe4, mitk\xe4 valikot netiss\xe4 n\xe4kyv\xe4t.";
	if (Application->MessageBoxW(kysymys.c_str(), L"Luo uusi JAHOnline-kisa", MB_YESNO) != IDYES)
		return;

	UnicodeString body = L"{\"action\":\"luo_kilpailu\""
		L",\"lahde\":" + ApiJsonString(ApiLahdeNimi())
		+ L",\"tyyppi\":" + ApiJsonString(ApiOletusTyyppi())
		+ L",\"pirila\":true"
		+ L",\"nimi\":" + ApiJsonString(nimi)
		+ L",\"pvm\":" + (pvm.IsEmpty() ? ApiJsonNull() : ApiJsonString(pvm))
		+ L",\"luonne\":" + ApiJsonString(luonne)
		+ L",\"laji\":" + ApiJsonString(LajiKirjain(laji))
		+ L",\"alalaji\":" + ApiJsonString(LajiKirjain(ApiPirilaAlalaji()).Trim())
		+ L",\"vaiheet\":" + IntToStr(ApiPirilaVaiheet())
		+ L"}";
	UnicodeString url = ApiBridgeUrl();
	UnicodeString vastaus;
	PaivitaTila(L"POST luo_kilpailu -> " + url);
	if (!ApiHttpPostJson(url, body, vastaus)) {
		PaivitaTila(L"VIRHE: HTTP-pyynt\xf6 ep\xe4onnistui");
		return;
	}
	if (!ApiJsonStatusOk(vastaus)) {
		PaivitaTila(L"VIRHE vastaus: " + vastaus.SubString(1, 240));
		return;
	}
	int id = 0;
	if (!ApiJsonFindInt(vastaus, L"kilpailu_id", id))
		ApiJsonFindInt(vastaus, L"id", id);
	if (id <= 0) {
		PaivitaTila(L"VIRHE: vastauksessa ei ole kilpailu_id:t\xe4: " + vastaus.SubString(1, 240));
		return;
	}
	UnicodeString vNimi, vLuonne;
	if (ApiJsonFindString(vastaus, L"nimi", vNimi) && !vNimi.IsEmpty())
		nimi = vNimi;
	if (ApiJsonFindString(vastaus, L"luonne", vLuonne) && !vLuonne.IsEmpty())
		luonne = vLuonne;
	PaivitaTila(L"Kisa luotu netiss\xe4: " + IntToStr(id) + L" - " + nimi
		+ L" (" + ApiLuonneNimi(luonne) + L")");

	LisaaComboon(id, nimi, pvm, luonne);
	ComboKilpailu->ItemIndex = ComboIndeksi(ComboKilpailu, id);
	ComboKilpailuChange(NULL);
	Yhdista();
}

void __fastcall TFormApiYhteydet::BtnLopetaSynkkaClick(TObject *Sender)
{
	KirjoitaTiedot();
	LopetaSynkka();
}

void __fastcall TFormApiYhteydet::BtnLahetaNytClick(TObject *Sender)
{
	KirjoitaTiedot();
	ApiConfigTallenna();
	if (!TApiIntegration::GetInstance()->OnKilpailuAvattu()) {
		PaivitaTila(L"VIRHE: avaa kilpailu ensin.");
		return;
	}
	int n = ApiSynkkaaLahetaKaikki();
	PaivitaTila(L"Lähetys valmis, rivejä: " + IntToStr(n));
}

void __fastcall TFormApiYhteydet::BtnHaeNytClick(TObject *Sender)
{
	KirjoitaTiedot();
	ApiConfigTallenna();
	if (!TApiIntegration::GetInstance()->OnKilpailuAvattu()) {
		PaivitaTila(L"VIRHE: avaa kilpailu ensin.");
		return;
	}
	int n = ApiSynkkaaHaeKaikki(true);
	ApiPaivitaOsanottajaNakyma();
	PaivitaTila(L"Haku valmis, päivitetty: " + IntToStr(n));
}

void __fastcall TFormApiYhteydet::BtnPaivitaTilaClick(TObject *Sender)
{
	PaivitaTila(L"URL=" + ApiBridgeUrl() + L" kilpailu_id=" + IntToStr(apiconfig.kilpailuId));
}

void __fastcall TFormApiYhteydet::BtnTyhjennaMemoClick(TObject *Sender)
{
	MemoTila->Clear();
}

void __fastcall TFormApiYhteydet::BtnVieInternetClick(TObject *Sender)
{
	ShellExecuteW(NULL, L"open",
		L"https://github.com/jaho81-sys/tulospalvelu/blob/main/docs/api-jahonline.md",
		NULL, NULL, SW_SHOW);
}

void __fastcall TFormApiYhteydet::BtnOhjeClick(TObject *Sender)
{
	ShowMessage(
		L"JAHOnline API (kaksisuuntainen)\n\n"
		L"URL: https://jahonline.com/public/api/kilpailijat_bridge.php\n"
		L"API-avain: k\xe4ytt\xe4j\xe4kohtainen token (JAHOnline-k\xe4ytt\xe4j\xe4)\n"
		L"Hae kilpailut: listaa netin Piril\xe4-kilpailut\n"
		L"Valitse listasta yhdistett\xe4v\xe4 kilpailu\n\n"
		L"Lähetä kilpailijat -> action=synkkaa (osanottajat, ajat, läsnäolo, väliajat)\n"
		L"Hae kilpailijat -> action=kilpailijat (päivitys paikalliseen KILP.DAT)\n"
		L"Online-rasti / ajanotto -> action=tapahtuma (piste, aika_sec = tuloksen sekunnit)\n"
		L"ViestiWin: sama protokolla + kenttä osuus (1-pohjainen).\n"
		L"Emit-luenta merkitsee lähtijän läsnäolevaksi ja synkkaa heti.\n"
		L"Yhdist\xe4 k\xe4ynnist\xe4\xe4 taustasynkan, kun kilpailu on auki. Yhdistetyt kisat muistetaan.\n"
		L"Luo uusi JAHOnline-kisa (n\xe4kyy, kun yhteys on luotu) luo netin kisan avatusta kilpailusta.\n"
		L"Kisan luonne tulee Piril\xe4n kilpailulajista ja m\xe4\xe4r\xe4\xe4 netin valikot.\n"
		L"L\xe4hiverkko (laheta) toimii aina, kun se on m\xe4\xe4ritetty. Synkka ei katkaise sit\xe4.\n"
		L"Yksi kone voi olla ainoa, joka puhuu nettiin. Muut koneet ovat samassa l\xe4hiverkossa.\n"
		L"Tapahtumaloki t\xe4ll\xe4 koneella tuo lis\xe4ksi puhelimen ja verkottomat asemat, ja l\xe4hiverkko vie ne eteenp\xe4in.\n"
		L"Aseman nimi erottaa l\xe4hett\xe4j\xe4n, jotta oma rivi ei tule takaisin.\n"
		L"Lopeta synkka pys\xe4ytt\xe4\xe4 t\xe4yssynkan. JAHOnline-siirto j\xe4\xe4 p\xe4\xe4lle.\n"
		L"Synkkaa ei käynnistetä, jos kisaa ei ole avattu.\n"
		L"Asetukset tallennetaan kilpailun kansion jahonline_api.ini -tiedostoon\n"
		L"ja luetaan sieltä, kun kilpailu avataan.\n"
		L"Auth: Authorization: Bearer <kayttajan api_token>\n"
	);
}

void __fastcall TFormApiYhteydet::EditUrlChange(TObject *Sender)
{
}

void __fastcall TFormApiYhteydet::BtnOKClick(TObject *Sender)
{
	KirjoitaTiedot();
	ApiConfigTallenna();
	if (TApiIntegration::GetInstance()->OnKilpailuAvattu()
		&& apiconfig.kilpailuId > 0 && apiconfig.apiKey[0] != 0
		&& (apiconfig.kaynnissa || apiconfig.siirtotapa == 1))
		TApiIntegration::GetInstance()->Alusta();
	PaivitaTila(L"Asetukset tallennettu: " + ApiConfigPolku());
	Close();
}

void __fastcall TFormApiYhteydet::BtnPeruutaClick(TObject *Sender)
{
	Close();
}

static CRITICAL_SECTION gTapahtumaCs;
static bool gTapahtumaCsOk = false;
static std::vector<UnicodeString> gTapahtumaJono;
static int gTapahtumaPudotettu = 0;
static int gTapahtumaPudotettuIlmoitus = 0;
static ApiTapahtumaSovellaFn gTapahtumaSovella = NULL;
static DWORD gSovellusSaie = 0;

static void TapahtumaCsAlusta(void)
{
	if (!gTapahtumaCsOk) {
		InitializeCriticalSection(&gTapahtumaCs);
		gTapahtumaCsOk = true;
	}
}

bool ApiSiirtoOnJahonline(void)
{
	return apiconfig.siirtotapa == 1;
}

int api_siirto_jahonline(void)
{
	return ApiSiirtoOnJahonline() ? 1 : 0;
}

UnicodeString ApiAsemaNimi(void)
{
	if (apiconfig.asema[0] != 0)
		return UnicodeString(apiconfig.asema);
	wchar_t nimi[64];
	DWORD n = 64;
	if (GetComputerNameW(nimi, &n) && nimi[0] != 0)
		return UnicodeString(nimi);
	return UnicodeString(L"pirila");
}

void ApiAsetaTapahtumaSovella(ApiTapahtumaSovellaFn fn)
{
	gTapahtumaSovella = fn;
}

void ApiSovellusAlkaa(void)
{
	gSovellusSaie = GetCurrentThreadId();
}

void ApiSovellusLoppuu(void)
{
	gSovellusSaie = 0;
}

bool ApiSovellusKaynnissa(void)
{
	return gSovellusSaie != 0 && gSovellusSaie == GetCurrentThreadId();
}

void ApiJonotaTapahtuma(const UnicodeString& objJson)
{
	if (!ApiSiirtoOnJahonline() || ApiSovellusKaynnissa())
		return;
	if (objJson.IsEmpty())
		return;
	TapahtumaCsAlusta();
	EnterCriticalSection(&gTapahtumaCs);
	if ((int)gTapahtumaJono.size() < 256)
		gTapahtumaJono.push_back(objJson);
	else {
		gTapahtumaPudotettu++;
		gTapahtumaPudotettuIlmoitus++;
	}
	LeaveCriticalSection(&gTapahtumaCs);
}

int ApiTapahtumaPudotettuOta(void)
{
	TapahtumaCsAlusta();
	EnterCriticalSection(&gTapahtumaCs);
	int n = gTapahtumaPudotettuIlmoitus;
	gTapahtumaPudotettuIlmoitus = 0;
	LeaveCriticalSection(&gTapahtumaCs);
	return n;
}

int ApiLahetaTapahtumaJono(void)
{
	if (!ApiSiirtoOnJahonline())
		return 0;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;
	TapahtumaCsAlusta();
	std::vector<UnicodeString> era;
	int pudotettu = 0;
	EnterCriticalSection(&gTapahtumaCs);
	era.swap(gTapahtumaJono);
	pudotettu = gTapahtumaPudotettu;
	gTapahtumaPudotettu = 0;
	LeaveCriticalSection(&gTapahtumaCs);
	if (era.empty())
		return 0;

	UnicodeString arr = L"[";
	for (size_t i = 0; i < era.size(); i++) {
		if (i > 0)
			arr += L",";
		arr += era[i];
	}
	arr += L"]";
	UnicodeString body =
		L"{\"action\":\"tapahtumat\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":" + ApiJsonString(ApiAsemaNimi())
		+ L",\"tapahtumat\":" + arr
		+ L"}";
	UnicodeString vastaus;
	if (!ApiHttpPostJson(ApiBridgeUrl(), body, vastaus) || !ApiJsonStatusOk(vastaus)) {
		EnterCriticalSection(&gTapahtumaCs);
		if ((int)gTapahtumaJono.size() + (int)era.size() <= 256)
			gTapahtumaJono.insert(gTapahtumaJono.begin(), era.begin(), era.end());
		else {
			gTapahtumaPudotettu += (int)era.size();
			gTapahtumaPudotettuIlmoitus += (int)era.size();
		}
		gTapahtumaPudotettu += pudotettu;
		LeaveCriticalSection(&gTapahtumaCs);
		return -2;
	}
	return (int)era.size();
}

int ApiHaeTapahtumat(void)
{
	if (!ApiSiirtoOnJahonline() || gTapahtumaSovella == NULL)
		return 0;
	if (apiconfig.kilpailuId <= 0 || apiconfig.apiKey[0] == 0)
		return -1;
	if (apiconfig.tapahtumaCursorKilpailu != apiconfig.kilpailuId) {
		apiconfig.tapahtumaCursor = 0;
		apiconfig.tapahtumaCursorKilpailu = apiconfig.kilpailuId;
	}
	UnicodeString body =
		L"{\"action\":\"tapahtumat_hae\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"lahde\":" + ApiJsonString(ApiAsemaNimi())
		+ L",\"jalkeen\":" + IntToStr(apiconfig.tapahtumaCursor)
		+ L",\"raja\":100}";
	UnicodeString vastaus;
	if (!ApiHttpPostJson(ApiBridgeUrl(), body, vastaus))
		return -2;
	if (!ApiJsonStatusOk(vastaus))
		return -3;

	std::vector<UnicodeString> objs;
	int n = ApiJsonExtractObjectArray(vastaus, L"tapahtumat", objs);
	int viimeinen = apiconfig.tapahtumaCursor;
	for (int i = 0; i < n; i++) {
		int id = 0;
		ApiJsonFindInt(objs[i], L"id", id);
		gTapahtumaSovella(objs[i]);
		if (id > viimeinen)
			viimeinen = id;
	}
	if (viimeinen != apiconfig.tapahtumaCursor) {
		apiconfig.tapahtumaCursor = viimeinen;
		apiconfig.tapahtumaCursorKilpailu = apiconfig.kilpailuId;
		ApiConfigTallenna();
	}
	return n;
}
