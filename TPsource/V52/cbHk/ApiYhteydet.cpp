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
	if (!FileExists(polku))
		return;
	ApiConfigLueTiedostosta(polku);
}

void ApiConfigTallenna(void)
{
	UnicodeString polku = ApiConfigPolku();
	WritePrivateProfileStringW(L"jahonline", L"url", apiconfig.apiUrl, polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"api_key", apiconfig.apiKey, polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"port", IntToStr(apiconfig.apiPort).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"kilpailu_id", IntToStr(apiconfig.kilpailuId).c_str(), polku.c_str());
	WritePrivateProfileStringW(L"jahonline", L"kilpailu_nimi", apiconfig.kilpailuNimi, polku.c_str());
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

__fastcall TFormApiYhteydet::TFormApiYhteydet(TComponent* Owner)
	: TForm(Owner)
{
	Scaled = false;
	if (Screen->PixelsPerInch != 96)
		ScaleBy(Screen->PixelsPerInch, 96);
}

void __fastcall TFormApiYhteydet::FormShow(TObject *Sender)
{
	ApiConfigLataa();
	LueTiedot();
	PaivitaTila(L"Asetukset: " + ApiConfigPolku());
	PaivitaTila(L"Hae kilpailut listaa netin Piril\xe4-kilpailut k\xe4ytt\xe4j\xe4avaimella.");
	if (!TApiIntegration::GetInstance()->OnKilpailuAvattu())
		PaivitaTila(L"Kilpailua ei ole avattu - synkkaa ei käynnistetä ennen kuin kisa on auki.");
	else
		PaivitaTila(L"Asetukset ladattu. Testaa (ping) käynnistää synkan, Lopeta synkka pysäyttää sen.");
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
}

void __fastcall TFormApiYhteydet::PaivitaTila(UnicodeString viesti)
{
	UnicodeString aika = FormatDateTime(L"hh:nn:ss", Now());
	MemoTila->Lines->Add(L"[" + aika + L"] " + viesti);
	while (MemoTila->Lines->Count > 200)
		MemoTila->Lines->Delete(0);
	MemoTila->SelStart = MemoTila->GetTextLen();
}

void __fastcall TFormApiYhteydet::TestaaYhteys(void)
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
	UnicodeString body = L"{\"action\":\"ping\",\"kilpailu_id\":" + IntToStr(apiconfig.kilpailuId)
		+ L",\"kello\":" + ApiJsonString(ApiPaikallinenKello()) + L"}";
	UnicodeString vastaus;
	PaivitaTila(L"POST ping -> " + url);

	if (!ApiHttpPostJson(url, body, vastaus)) {
		PaivitaTila(L"VIRHE: HTTP-pyyntö epäonnistui");
		LopetaSynkka();
		return;
	}

	if (ApiJsonStatusOk(vastaus)) {
		PaivitaTila(L"OK: " + vastaus.SubString(1, 180));
		apiconfig.kaynnissa = 1;
		ApiConfigTallenna();
		TApiIntegration::GetInstance()->Alusta();
		NaytaYhteysTila();
		PaivitaTila(L"Synkka käynnissä. Lopeta synkka -nappi pysäyttää sen.");
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
		PaivitaTila(L"T\xe4yssynkka pys\xe4ytetty. JAHOnline-siirto ty\xf6asemien v\xe4lill\xe4 jatkuu.");
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

void __fastcall TFormApiYhteydet::NaytaValittuKilpailu(void)
{
	if (!ComboKilpailu)
		return;
	ComboKilpailu->Items->BeginUpdate();
	ComboKilpailu->Items->Clear();
	if (apiconfig.kilpailuId > 0) {
		UnicodeString t = IntToStr(apiconfig.kilpailuId);
		if (apiconfig.kilpailuNimi[0] != 0)
			t = t + L" - " + UnicodeString(apiconfig.kilpailuNimi);
		ComboKilpailu->Items->Add(t);
		ComboKilpailu->ItemIndex = 0;
	}
	ComboKilpailu->Items->EndUpdate();
}

void __fastcall TFormApiYhteydet::ComboKilpailuChange(TObject *Sender)
{
	int id = ComboKilpailuId();
	if (id > 0 && EditKilpailuId)
		EditKilpailuId->Text = IntToStr(id);
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
		ComboKilpailu->Items->Add(KilpailuListateksti(id, nimi, pvm));
		if (id == vanha)
			valitse = lisatty;
		lisatty++;
	}
	ComboKilpailu->Items->EndUpdate();

	if (lisatty == 0) {
		NaytaValittuKilpailu();
		PaivitaTila(L"Ei Piril\xe4-kilpailuja t\xe4lle k\xe4ytt\xe4j\xe4avaimelle");
		return;
	}
	if (valitse < 0 && lisatty == 1)
		valitse = 0;
	if (valitse >= 0) {
		ComboKilpailu->ItemIndex = valitse;
		ComboKilpailuChange(NULL);
	}
	PaivitaTila(L"Kilpailuja listassa: " + IntToStr(lisatty) + L". Valitse yhdistett\xe4v\xe4 kilpailu.");
}

void __fastcall TFormApiYhteydet::BtnYhteysTestiClick(TObject *Sender)
{
	TestaaYhteys();
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
		L"Testaa (ping) k\xe4ynnist\xe4\xe4 taustasynkan, kun kilpailu on auki.\n"
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
