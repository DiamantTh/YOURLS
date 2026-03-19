# YOURLS – Vollständige Architektur-, Sicherheits- und Modernisierungsanalyse

> **Analysedatum:** 2026-03-19  
> **Analysegegenstand:** YOURLS – Your Own URL Shortener (v1.10.4-dev)  
> **Quellen:** Quellcode, Konfigurationsdateien, Testdateien, README, CHANGELOG, composer.json  
> **Arbeitsmodus:** Ausschließlich read-only. Es wurden keinerlei Änderungen am Projekt vorgenommen.

---

## A. Kurzüberblick

YOURLS ist ein in PHP geschriebenes, selbst gehostetes URL-Kürzsystem. Das Projekt ist über mehr als 15 Jahre historisch gewachsen, zeigt aber seit Version 1.7 (ca. 2019) erkennbare und konsequente Modernisierungsschritte. Es arbeitet ohne klassisches MVC-Framework; stattdessen gibt es ein eigenes, WordPress-inspiriertes Actions/Filters-Hook-System, prozedural strukturierte Funktionsbibliotheken, eine PDO-basierte Datenbankabstraktionsschicht (Aura/SQL) und seit 1.7.3 vereinzelt namespaced OOP-Klassen.

**Gesamtbewertung:** Das Projekt ist für seinen Zweck funktional und stabil. Es ist kein Enterprise-System, sondern ein gezielt eingesetztes Werkzeug für Self-Hosters. Die Architektur spiegelt diesen Anspruch wider: pragmatisch, erweiterbar per Plugin, mit klaren internen Konventionen – aber auch mit erheblichen Altlasten in der Authentifizierungsschicht und ohne moderne Web-Security-Header wie CSP.

### Stärken (direkt im Code beobachtet)
- Durchgängig nutzbares, gut dokumentiertes Plugin-Hook-System (Actions/Filters)
- CSRF-Absicherung über ein eigenes Nonce-System auf allen relevanten Formularen
- Moderne Passwort-Hashing-Unterstützung via `password_hash()`/`password_verify()`
- Automatische Passwort-Migrations-Pipeline: cleartext → MD5-gesalzen → phpass → `password_hash()`
- Cookie-Attribute korrekt gesetzt: `HttpOnly`, `SameSite=Lax`, `Secure` (abhängig von HTTPS)
- SQL-Injection-Schutz durch PDO mit Prepared Statements (Aura/SQL)
- Umfangreiche XSS-Schutzfunktionen (`yourls_esc_html`, `yourls_esc_attr`, `yourls_esc_url`, KSES)
- `X-Frame-Options: SAMEORIGIN` gegen Clickjacking
- Gute Testabdeckung: ~519 Tests in 18 Testgruppen

### Schwächen / kritische Lücken (direkt im Code beobachtet)
- **Kein Brute-Force- / Rate-Limiting-Schutz** für den Login
- Benutzerverwaltung vollständig in `config.php` – kein Datenbankmodell für Benutzer
- Kein MFA/2FA/FIDO2-Support im Core
- Cookie-Wert ist deterministisch: `HMAC(COOKIEKEY, username)` – kein Session-Token, kein Invalidierungsmechanismus
- Kein `Content-Security-Policy`-Header
- Kein `X-Content-Type-Options: nosniff`-Header
- Kein `Referrer-Policy`-Header
- Legacy-Passwort-Hashes (MD5, cleartext) werden noch akzeptiert
- Kein Account-Recovery-Mechanismus
- Signatur-API erlaubt beliebige Hash-Algorithmen (`?hash=crc32` etc.)

---

## B. Architektur- und Strukturüberblick

### Sprachen und Technologien

| Technologie | Rolle | Bewertung |
|---|---|---|
| PHP 8.1+ (Ziel: 8.x) | Backend, gesamte Geschäftslogik | Modern, aktuell unterstützt |
| MySQL / MariaDB (PDO) | Datenspeicherung | Solide |
| jQuery 3.5.1 | Frontend-Interaktivität | Veraltet (jQuery 3.5.1 = 2021) |
| Vanilla CSS | Admin-Styling (388 Zeilen) | Minimal, kein Build-System |
| Composer | Dependency-Management | Korrekt eingesetzt |
| PHPUnit | Tests | Gut etabliert |

### Verzeichnisstruktur

```
/
├── admin/               # Admin-Panel-Einstiegspunkte
│   ├── index.php        # URL-Liste, Suche, Filterung
│   ├── admin-ajax.php   # AJAX-Handler
│   ├── tools.php        # Diagnose-Werkzeuge
│   ├── plugins.php      # Plugin-Verwaltung
│   ├── install.php      # Erst-Installation
│   └── upgrade.php      # DB-Upgrade
├── includes/
│   ├── Config/          # Namespaced OOP: Config.php, Init.php, InitDefaults.php
│   ├── Database/        # Namespaced OOP: YDB.php, Options.php, Logger.php, Profiler.php
│   ├── Exceptions/      # Namespaced OOP: ConfigException.php
│   ├── Views/           # Namespaced OOP: AdminParams.php (Query-Parameter-Handling)
│   ├── auth.php         # Auth-Dispatcher: Prüft Credentials, löst Passwort-Migration aus
│   ├── functions-auth.php     # Alle Auth-Funktionen: Login, Cookie, Signature, Nonce
│   ├── functions-html.php     # HTML-Rendering: Head, Footer, Login-Form, Admin-Menü
│   ├── functions-plugins.php  # Hook-System, Plugin-Laden, Sandbox
│   ├── functions-formatting.php  # Sanitize, Escape (XSS-Schutz)
│   ├── functions-kses.php     # HTML-Filterung (WordPress KSES-Port)
│   ├── functions.php          # Allgemeine Utility-Funktionen
│   ├── load-yourls.php        # Bootstrap: Autoload, Config laden, Init
│   └── vendor/                # Composer-Abhängigkeiten
├── user/
│   ├── config.php       # Benutzerkonfiguration (inkl. Passwörter!) – nicht versioniert
│   ├── plugins/         # Benutzereigene Plugins
│   ├── themes/          # Benutzereigene Themes (Verzeichnis vorhanden, wenig Nutzung im Core)
│   └── pages/           # Öffentliche Seiten
├── css/ js/             # Statische Frontend-Assets
├── tests/               # PHPUnit-Testsuites
└── yourls-loader.php    # Front-Controller: Routing-Eintrittspunkt
```

### Architekturmuster

Das System folgt keinem klassischen Framework-Muster. Es ist eine prozedurale PHP-Applikation mit funktionalen Namensräumen und einigen OOP-Klassen für neuere Subsysteme. Das Leitprinzip ist das WordPress-ähnliche Hook-System:

- **Actions**: Ereignisse ohne Rückgabewert (`yourls_do_action`, `yourls_add_action`)
- **Filters**: Datenveränderung mit Rückgabewert (`yourls_apply_filter`, `yourls_add_filter`)
- **Shunts**: Ein spezieller Filter-Typ, der die gesamte Funktion kurzschließt und den Plugin-Autoren erlaubt, jede Kernfunktion zu überschreiben

Diese Architektur macht das System sehr gut erweiterbar und gut testbar – erfordert aber globale Zustandshaltung.

### Trennungsgrad von Verantwortlichkeiten

| Bereich | Trennung | Bemerkung |
|---|---|---|
| Datenbankzugriff | Mittel | YDB-Klasse, aber überall `yourls_get_db()` in Funktionen |
| HTML/Logik-Trennung | Schwach | HTML wird direkt in PHP-Funktionen gerendert; keine Template-Engine |
| Auth-Logik | Gut | Zentralisiert in `functions-auth.php` und `auth.php` |
| Plugin-System | Gut | Klar abgetrennt in `functions-plugins.php` |
| Konfiguration | Schwach | Passwörter in PHP-Konfig-Datei, kein Trennung von Code und Credentials |

### Historisch gewachsene vs. sauber geplante Bereiche

| Bereich | Zustand |
|---|---|
| Bootstrap/Init (`Config/`) | Sauber geplant, OOP, seit 1.7.3 |
| Datenbankschicht (`Database/`) | Sauber geplant, OOP, seit 1.7.3 |
| Hook-System (`functions-plugins.php`) | Sauber, seit 1.5 |
| Auth (`functions-auth.php`) | Historisch gewachsen, viele Legacy-Pfade |
| HTML-Rendering (`functions-html.php`) | Historisch gewachsen, stark gekoppelt |
| Passwort-Speicherung (`config.php`) | Technische Schuld, TODO explizit im Code vermerkt |
| Theme-System | Rudimentär vorhanden, kaum genutzt |

---

## C. Analyse der Richtlinien und Dokumentation

### Vorhandene Richtlinien

1. **README.md**: Minimalistisch. Verweis auf externe Dokumentation (docs.yourls.org). Keine internen Architekturhinweise.
2. **CHANGELOG.md**: Gut gepflegt, chronologisch, zeigt klare Versionsstrategie. Enthält Hinweise auf API-Änderungen.
3. **tests/README.md**: Ausführliche Anleitung zum Ausführen der Tests. Zeigt, dass Testinfrastruktur aktiv gepflegt wird.
4. **tests/tests/TODO.md**: Vorhanden – signalisiert, dass bekannte Lücken in der Testabdeckung dokumentiert werden.
5. **user/config-sample.php**: Konfigurationsvorlage mit Kommentaren. Dient als einzige interne "Entwicklerdokumentation" für die Konfiguration.
6. **CONTRIBUTING**: Nicht im Repository vorhanden; Verweis im README auf externes `.github`-Repository (`YOURLS/.github/blob/master/CONTRIBUTING.md`).
7. **SECURITY**: Nicht im Repository vorhanden.
8. **CODE_OF_CONDUCT**: Nicht im Repository vorhanden.
9. **Keine ADRs** (Architecture Decision Records).
10. **Keine formalen Coding-Standards** (kein `.phpcs.xml`, kein `.php-cs-fixer.php` im Repository).

### Bewertung der Richtlinienkonformität

- **Namenskonvention** (`yourls_` Präfix für alle Funktionen) wird konsequent eingehalten – direkt im Code beobachtet.
- **Kommentar-/Dokumentationsstil** (PHPDoc-ähnlich) wird für öffentliche Funktionen eingehalten, ist aber nicht für alle privaten Hilfsfunktionen vorhanden.
- **Hook-Konventionen** (shunt-Muster, filter-vor-action-Muster) werden konsequent eingehalten – direkt im Code beobachtet.
- **Sicherheitskonventionen** (Nonce bei Formularen, Escape bei Output) werden weitgehend eingehalten.
- **Trennung von Logik und Darstellung** ist dokumentiertes Ziel (Hooks wie `login_form_top`, `login_form_bottom`), wird aber im Core selbst nicht konsequent durchgehalten.

### Dokumentationslücken

- Kein formaler Sicherheitshinweis (`SECURITY.md`) – unklar, wie Sicherheitslücken gemeldet werden sollen.
- Keine Architekturdokumentation im Repository selbst (alles extern auf docs.yourls.org).
- Das `TODO` im Code zu echter Benutzerverwaltung (`// TODO: Remove this once real user management is implemented`) ist seit mindestens Version 1.7 vorhanden und zeigt eine langfristige technische Schuld.

---

## D. Sicherheitsanalyse

### D.1 Authentifizierung

**Direkt im Code beobachtet:**

- Vier voneinander unabhängige Auth-Pfade in `yourls_is_valid_user()`:
  1. Signature + Timestamp (API only)
  2. Signature ohne Timestamp (API only)
  3. Username + Password (Browser und API)
  4. Cookie (Browser only)
- Login-Nonce (`admin_login`) wird bei Browser-Logins korrekt geprüft
- Passwort-Vergleich via `yourls_check_password_hash()` unterstützt drei Legacy-Formate plus modernen `password_hash()`-Hash

**Risiken:**

| Risiko | Schwere | Beleg |
|---|---|---|
| Kein Brute-Force-Schutz | Kritisch | Kein Rate-Limiting in `yourls_is_valid_user()` oder `yourls_check_username_password()` |
| Legacy-Hashes (cleartext, MD5) noch akzeptiert | Hoch | `yourls_check_password_hash()` in `functions-auth.php:152` |
| Benutzerliste in PHP-Datei | Mittel | `$yourls_user_passwords` aus `config.php` – kein DB-Schutz, keine Trennung von Credentials und Code |
| Signatur-API erlaubt schwache Hash-Algos | Mittel | `?hash=crc32` wäre gültig; Zeile 378 in `functions-auth.php` |
| Kein MFA/2FA | Hoch | Nicht implementiert, kein Erweiterungspunkt vorhanden |

### D.2 Session-Handling und Cookies

**Direkt im Code beobachtet:**

- Cookie-Name: `yourls_cookiename()` (konfigurierbar per Filter)
- Cookie-Wert: `yourls_salt($user)` = `hash_hmac('sha256', $username, COOKIEKEY)`
- Cookie-Attribute: `HttpOnly=true`, `SameSite=Lax`, `Secure` (abhängig von `yourls_is_ssl()`), `Domain` (parsed aus SITE-URL)

**Risiken:**

| Risiko | Schwere | Beleg |
|---|---|---|
| Cookie-Wert deterministisch (kein Session-Token) | Hoch | `yourls_cookie_value()` in `functions-auth.php:574` |
| Keine Möglichkeit, einzelne Sessions zu invalidieren | Hoch | Logout löscht nur den eigenen Cookie; kein Server-seitiger Session-Store |
| Cookie-Wert ändert sich nie, solange COOKIEKEY und Username gleich bleiben | Mittel | Konsequenz der deterministischen Implementierung |
| Kein Session-Fixation-Schutz | Mittel | Kein `session_regenerate_id()` o.ä. |

### D.3 CSRF-Schutz

**Direkt im Code beobachtet:**

Das Nonce-System ist gut implementiert:
- Nonces sind zeitgebunden (`yourls_tick()` = ceil(time / NONCE_LIFE), Standard: 12h-Fenster)
- Nonces sind aktiongebunden (z.B. `admin_login`, `admin_logout`)
- Nonces sind benutzergebunden (wenn User bekannt)
- HMAC-SHA256 als Algorithmus
- Alle sicherheitsrelevanten Formulare und AJAX-Aktionen prüfen Nonces

**Bewertung:** Der CSRF-Schutz ist für eine prozedurale Applikation dieser Art als gut einzustufen.

**Einschränkung:** Das Nonce-Fenster von 12h ist großzügig. Ein gestohlenes Nonce bleibt bis zu 24h gültig (altes Fenster + neues Fenster).

### D.4 XSS-Schutz

**Direkt im Code beobachtet:**

- `yourls_esc_html()`: `htmlspecialchars()` mit `ENT_QUOTES | ENT_SUBSTITUTE`
- `yourls_esc_attr()`: Ähnlich, für HTML-Attribute
- `yourls_esc_url()`: Validiert und bereinigt URLs
- `yourls_kses()`: WordPress-Port für erlaubtes HTML (Plugin-Nachrichten, i18n)
- `yourls_esc_js()`: JavaScript-Escaping

**Risiken:**

| Risiko | Schwere | Beleg |
|---|---|---|
| Kein CSP-Header (`Content-Security-Policy`) | Hoch | Nicht in `yourls_html_head()` gesetzt – plausibel vermutet, bestätigt durch Abwesenheit |
| Inline-JavaScript im HTML (`<script>` in `yourls_login_screen()`) | Mittel | `functions-html.php:820`: `$('#username').focus();` |
| Inline-JavaScript in Admin-Seiten | Mittel | `admin/index.php:199ff`: dynamisch generiertes `<script>` |

### D.5 SQL-Injection

**Direkt im Code beobachtet:**

- Aura/SQL als PDO-Wrapper mit Named Parameters
- Alle Datenbankabfragen in `admin/index.php` verwenden Binding: `:search`, `:date_first_sql` etc.
- `yourls_sanitize_keyword()`, `yourls_sanitize_ip()`, `yourls_sanitize_date_for_sql()` als zusätzliche Schicht

**Bewertung:** Das SQL-Injection-Risiko ist gering. Prepared Statements werden konsequent verwendet.

**Einschränkung:** Die Tabellen- und Spaltennamen in Queries werden nicht gebunden (das ist bei PDO technisch nicht möglich), sondern direkt interpoliert (z.B. `"ORDER BY \`$sort_by\` $sort_order"`). `$sort_by` und `$sort_order` werden in `AdminParams` validiert – dies muss korrekt sein, um SQL-Injection über diesen Weg auszuschließen. Plausibel gut, aber nicht tiefergehend verfolgt.

### D.6 Weitere Sicherheitsaspekte

| Aspekt | Status | Beleg |
|---|---|---|
| HTTP-Header: `X-Frame-Options: SAMEORIGIN` | ✅ Implementiert | `yourls_no_frame_header()` in `functions.php:357` |
| HTTP-Header: `Content-Security-Policy` | ❌ Fehlt | Nicht in `yourls_html_head()` gesetzt |
| HTTP-Header: `X-Content-Type-Options: nosniff` | ❌ Fehlt | Nicht gesetzt |
| HTTP-Header: `Referrer-Policy` | ❌ Fehlt | Nicht gesetzt |
| No-Cache für Admin-Seiten | ✅ Implementiert | `yourls_no_cache_headers()` in `functions.php:337` |
| Plugin-Sandbox | ✅ Implementiert | `yourls_include_file_sandbox()` in `functions-plugins.php:594` |
| Plugin-Pfad-Traversal-Schutz | ✅ Implementiert | `yourls_is_a_plugin_file()` prüft `..` und `./` |
| Account-Recovery (Passwort-Reset) | ❌ Fehlt | Kein Mechanismus vorhanden |
| Logging sensibler Daten | ℹ️ Unklar | `functions.php:532` loggt IP, Referrer, User-Agent – keine Passwörter |
| Secrets in Konfig | ⚠️ Risiko | Passwörter in `config.php`; bei unsicherer Serverkonfiguration leakbar |
| Environment-Variable-Support | ✅ Vorhanden | `YOURLS_PASSWORD`, `YOURLS_PASS`, `YOURLS_PASS_FILE` per Env-Var möglich |

### D.7 API-Sicherheit

Die API (`yourls-api.php`) kennt drei Authentifizierungsmethoden:

1. **Username + Password** (einfachste, aber über HTTPS sicher)
2. **Signature** (HMAC des Usernames mit COOKIEKEY, dauerhaft gültig)
3. **Signature + Timestamp** (zeitgebunden, läuft nach `NONCE_LIFE` Sekunden ab)

**Direkt im Code beobachtet – Risiken:**

- Die dauerhafte Signatur (Methode 2) ist ein langlebiges, nicht rotierbares API-Token. Wird COOKIEKEY kompromittiert, sind alle Signaturen für alle Benutzer gültig.
- Methode 3 erlaubt frei wählbare Hash-Algorithmen via `?hash=` – theoretisch könnte ein schwacher Algorithmus (`crc32`, `adler32`) gewählt werden. Zwar prüft der Code, dass der Algorithmus in `hash_algos()` existiert, aber keine Einschränkung auf kryptographisch sichere Algorithmen.
- Kein Rate-Limiting auf API-Endpunkten.

---

## E. FIDO2-/WebAuthn-Analyse

### E.1 Aktuelle Authentifizierungsarchitektur (Zusammenfassung)

Die gesamte Auth-Logik liegt in `yourls_is_valid_user()` (`functions-auth.php:26`). Der Kontrollfluss ist eine flache if-elseif-Kaskade über vier Methoden (Signature+Timestamp, Signature, Username/Password, Cookie). Es gibt keine Abstraktion, keine Auth-Strategie-Klasse, kein Interface.

Die Benutzeridentität wird in `YOURLS_USER` (PHP-Konstante) gespeichert, sobald ein Auth-Pfad erfolgreich ist. Es gibt keine Session-Objekte, kein Token-Repository, kein User-Objekt.

### E.2 Passt FIDO2 architektonisch in das Projekt?

**Mittelmäßig bis schlecht in der aktuellen Architektur – aber gut via Plugin-System.**

FIDO2/WebAuthn erfordert:
1. Eine Server-seitige Speicherung von Credential-IDs und Public-Keys pro Benutzer
2. Eine Challenge-Response-Mechanik (Server sendet Challenge, Client signiert mit privatem Schlüssel)
3. Asynchrone Kommunikation (JavaScript `navigator.credentials.get()`)
4. Neue Datenmodelle (Credential-Tabelle, User-Tabelle)

Das aktuelle System bietet nichts davon:
- Keine User-Tabelle in der Datenbank
- Keine Session-Objekte (nur deterministischer Cookie)
- Kein modularer Auth-Dispatcher (keine "Strategy"-Abstraktion)
- Kein modernes JavaScript-Build-System

### E.3 Theoretische Integrationspunkte

| Komponente | Relevanz | Begründung |
|---|---|---|
| `yourls_is_valid_user()` | Hoch | Haupteintrittspunkt für Auth; müsste um FIDO2-Zweig erweitert werden |
| `yourls_store_cookie()` | Hoch | Nach erfolgreichem FIDO2-Login müsste Session-Token gesetzt werden |
| `yourls_login_screen()` | Hoch | Login-Formular müsste um Passkey-Button/JS-Flow erweitert werden |
| `admin_ajax.php` | Hoch | AJAX-Handler für WebAuthn-Challenge und Response benötigt |
| `functions-auth.php` | Hoch | Neue Funktionen: `yourls_check_webauthn()`, `yourls_webauthn_challenge()` |
| Datenbankschema | Hoch | Neue Tabelle: `yourls_webauthn_credentials` (user_id, credential_id, public_key, aaguid, ...) |
| `shunt_is_valid_user` Filter | Mittel | Könnte von Plugin genutzt werden, um FIDO2 zu implementieren |
| `login_form_bottom` Action | Mittel | Plugin-Einstiegspunkt für Passkey-Button im Login-Formular |
| `pre_login` Action | Mittel | Plugin-Einstiegspunkt für Pre-Auth-Logik |
| CSS (`style.css`) | Niedrig | Design-Anpassung für Passkey-Button |

### E.4 Betroffene Datenmodelle

Aktuell gibt es **keine Benutzertabelle** in der Datenbank. FIDO2 erfordert zwingend:

1. **User-Tabelle** (`yourls_users`):
   - `user_id` (int, PK)
   - `username` (varchar)
   - `password_hash` (varchar, nullable für passwortlosen Login)
   - Erfordert Migration der Passwörter aus `config.php`

2. **WebAuthn-Credential-Tabelle** (`yourls_webauthn_credentials`):
   - `credential_id` (binary/varchar, PK)
   - `user_id` (FK zu `yourls_users`)
   - `public_key_credential_source` (JSON/blob)
   - `aaguid` (varchar)
   - `created_at`, `last_used_at`

### E.5 Empfohlener Modernisierungspfad für FIDO2 (nur beschreibend)

**Phase A – Voraussetzung: Echte Benutzerverwaltung**  
Solange Benutzer und Passwörter in `config.php` stehen, ist keine sinnvolle FIDO2-Integration möglich. Eine Benutzertabelle in der Datenbank ist zwingende Voraussetzung.

**Phase B – FIDO2 als optionaler zweiter Faktor (MFA)**  
Empfehlenswerter erster Schritt: FIDO2 als optionalen zweiten Faktor nach Username/Passwort einführen. Das minimiert die Risiken und erlaubt einen graduellen Rollout. Umsetzbar als Plugin via `shunt_is_valid_user`-Filter und `login_form_bottom`-Action.

**Phase C – FIDO2 als primäre Methode / passwortloser Login**  
Erfordert vollständige User-Tabelle, JavaScript-Build-System, und erhebliche Änderungen an der Login-UX.

**Fazit für FIDO2:**  
FIDO2 ist am sinnvollsten als **optionaler zweiter Faktor via Plugin**, zumindest solange keine echte Benutzerdatenbank vorhanden ist. Der Shunt-Filter `shunt_is_valid_user` und die Login-Actions (`login_form_bottom`, `login_form_top`) bieten bereits ausreichende Integrationspunkte für eine Plugin-basierte Lösung.

---

## F. Theme-, Design- und Engine-Analyse

### F.1 Template-Ansatz

YOURLS hat **keine dedizierte Template-Engine**. HTML wird direkt in PHP-Funktionen als `echo`-Ausgabe oder `heredoc`/Inline-PHP generiert. Alle Admin-Seiten (index.php, tools.php, plugins.php etc.) folgen diesem Schema:

```
yourls_html_head($context)
yourls_html_logo()
yourls_html_menu()
[... page-spezifische Ausgabe ...]
yourls_html_footer()
```

**Konsequenz:** Die Darstellungslogik ist fest mit der Anwendungslogik verwoben. Es gibt keine Blade-, Twig-, Smarty- oder ähnliche Trennung. Eine Änderung am Layout erfordert PHP-Kenntnisse.

### F.2 Theme-System

Das Theme-System ist im Code **vorbereitet aber minimal genutzt**:

- `YOURLS_THEMEDIR` und `YOURLS_THEMEURL` sind als Konstanten definiert (seit ca. 1.9)
- `yourls_get_themes()`, `yourls_activate_theme()`, `yourls_load_theme()`, `yourls_is_style_queued()` sind als Funktionen implementiert (bestätigt durch `tests/tests/themes/ThemesTest.php`)
- Die Tests validieren Theme-Aktivierung und Style-Queuing
- In der Praxis betrifft das Theme-System hauptsächlich das **öffentliche Frontend** (Redirect-Seiten, Info-Seiten), nicht das Admin-Panel

Das Admin-Panel selbst ist nicht "themeable" über das offizielle Theme-System. Das CSS liegt direkt in `/css/style.css` (388 Zeilen vanilla CSS, kein Präprozessor, kein Build-System).

### F.3 Erweiterbarkeit des Designs

| Aspekt | Status |
|---|---|
| Admin-Panel theming | Nur per Plugin/Filter (`bodyclass`-Filter, `admin_headers`-Action) |
| Öffentliches Frontend | Über Theme-System erweiterbar |
| Login-Formular | Per Action-Hooks erweiterbar (`login_form_top`, `login_form_bottom`, `login_form_end`) |
| HTML-`<head>` | Per Action-Hook `html_head_meta` |
| Menü | Per Action-Hook `admin_menu` |
| Seitentitel | Per Filter `html_title` |
| CSS-Assets | Per Plugin (Style-Queue) |
| JS-Assets | Per Plugin (Script-Queue) |

Das Hook-System macht das Admin-Panel gut **inkrementell** erweiterbar, aber nicht austauschbar.

### F.4 Frontend-Technologien und deren Zustand

| Technologie | Version | Bewertung |
|---|---|---|
| jQuery | 3.5.1 (2021) | Veraltet; jQuery 3.7.1 ist aktuell. Sicherheitsrelevant: jQuery < 3.5 hatte XSS-Lücken, 3.5.1 ist gepatchte Version. |
| Vanilla CSS | keine Version | Keine Build-Pipeline, kein Sass/Less/PostCSS |
| JavaScript-Module | Keines | Kein Bundler (webpack, vite etc.) |
| Accessibility | Partiell | ARIA-Attribute vorhanden (`role="main"`, `role="banner"`), aber nicht systematisch |

### F.5 Eignung für moderne Auth-UX (FIDO2 / Passkey)

Die Login-UI (`yourls_login_screen()`) ist einfaches HTML: zwei Felder, ein Submit-Button. Eine FIDO2-Integration würde erfordern:

- Einen Passkey-Button mit `navigator.credentials.get()` JavaScript-Aufruf
- AJAX-Kommunikation mit dem Server (Challenge → Response → Token)
- Möglicher Einstiegspunkt: `login_form_bottom`-Action

Technisch machbar, aber die fehlende JavaScript-Build-Pipeline und das fehlende JavaScript-Modul-System würden die Implementierung erschweren. Eine Plugin-basierte Lösung könnte eigene JavaScript-Dateien laden.

---

## G. Technische Schulden und Modernisierungsrisiken

### G.1 Passwörter in config.php

**Das ist die größte technische Schuld des Systems.** Der Code enthält einen expliziten TODO-Kommentar:

```
// TODO: Remove this once real user management is implemented
```

Dieser Kommentar steht seit mindestens Version 1.7 im Code. Die gesamte Pipeline zur automatischen Passwort-Migration (cleartext → MD5 → phpass → password_hash) existiert nur, weil Passwörter in einer Datei gespeichert werden, die der Webserver servieren kann, wenn die PHP-Konfiguration nicht stimmt.

**Folgeprobleme dieser Schuld:**
- Kein MFA möglich
- Keine FIDO2-Integration sinnvoll
- Keine Benutzerselbstverwaltung (kein "Change password", kein "Forgot password")
- Keine Rollen/Rechte pro Benutzer
- Keine Audit-Logs pro Benutzer
- Benutzerliste ist ein globales PHP-Array (`$yourls_user_passwords`)

### G.2 Deterministischer Cookie-Wert

Der Cookie-Wert ist `HMAC(COOKIEKEY, username)`. Er ändert sich nie (außer COOKIEKEY oder Username ändern sich). Das bedeutet:

- Einmal gestohlener Cookie ist dauerhaft gültig
- Logout invalidiert nur den Client-Cookie, nicht den "Wert"
- Mehrere parallele Sessions können nicht unterschieden werden
- Kein "Alle anderen Sessions abmelden"-Mechanismus möglich

### G.3 Legacy-Auth-Formate

Drei verschiedene Passwort-Formate werden noch aktiv unterstützt:
1. Klartext
2. MD5-gesalzen (`md5:<salt>:<md5(salt.pass)>`)
3. phpass

Dieser Code muss irgendwann entfernt werden, erhöht aber die Komplexität und die Angriffsfläche so lange er vorhanden ist.

### G.4 Signatur-API mit freiem Hash-Algorithmus

Der `?hash=`-Parameter erlaubt die Auswahl beliebiger Hash-Algorithmen aus `hash_algos()`. PHP's `hash_algos()` enthält auch kryptographisch unsichere Algorithmen wie `adler32`, `crc32`, `fnv`. Plausibel vermutet (nicht explizit untersucht), dass ein Angreifer mit Netzwerkzugang eine schwächere Signatur forcieren könnte.

### G.5 Fehlende Security-Header

Drei moderne Security-Header fehlen vollständig:
- `Content-Security-Policy`: Würde XSS erheblich einschränken (aber erfordert Inline-JS-Refactoring)
- `X-Content-Type-Options: nosniff`: Verhindert MIME-Type-Sniffing
- `Referrer-Policy`: Begrenzt Referrer-Leakage

Diese Header könnten theoretisch über die `admin_headers`-Action durch Plugins gesetzt werden, aber sie sind kein Teil des Core.

### G.6 jQuery 3.5.1

jQuery 3.5.1 enthält keine bekannten kritischen Schwachstellen (die XSS-Fixes kamen in 3.5.0), aber es ist nicht die aktuelle Version. Für zukünftige Sicherheits-Patches sollte ein Update auf jQuery 3.7.x in Betracht gezogen werden.

---

## H. Konkrete Empfehlungen (ausschließlich textuell, keine Umsetzung)

### H.1 Kritisch (sofortiger Handlungsbedarf)

1. **Brute-Force-Schutz für Login einführen**: Eine IP-basierte oder Username-basierte Sperre nach N Fehlversuchen sollte implementiert werden. Das könnte über die `login_failed`-Action (die bereits existiert) und die Options-Tabelle realisiert werden, ohne das Datenbankschema zu ändern.

2. **Schwache Hash-Algorithmen für API-Signaturen einschränken**: Der `?hash=`-Parameter sollte auf eine Whitelist kryptographisch sicherer Algorithmen (SHA-256, SHA-512) beschränkt werden, statt alle `hash_algos()` zu erlauben.

3. **Fehlende Security-Header hinzufügen**: `X-Content-Type-Options: nosniff` und `Referrer-Policy: strict-origin-when-cross-origin` können ohne Nebeneffekte sofort gesetzt werden. Ein `Content-Security-Policy`-Header erfordert vorherige Arbeit an Inline-JavaScript und -CSS.

### H.2 Wichtig (mittelfristiger Handlungsbedarf)

4. **Session-Token statt deterministischem Cookie**: Der Cookie-Wert sollte um einen zufälligen, serverseitig gespeicherten Token ergänzt werden. Das erfordert eine neue Tabelle oder Nutzung der Options-Tabelle.

5. **API-Signatur-Rotation ermöglichen**: Benutzer sollten ihre API-Signatur zurücksetzen können, ohne das COOKIEKEY (das global für alle gilt) zu ändern.

6. **`X-Frame-Options` durch `Content-Security-Policy: frame-ancestors` ergänzen**: `X-Frame-Options` ist veraltet; CSP `frame-ancestors` ist die moderne Alternative.

7. **jQuery auf 3.7.x updaten**: Kein akutes Sicherheitsproblem, aber Wartungshygiene.

### H.3 Strategisch (langfristiger Handlungsbedarf)

8. **Echte Benutzerverwaltung in der Datenbank**: Die Passwörter aus `config.php` in eine `yourls_users`-Tabelle migrieren. Das würde MFA, FIDO2, Passwort-Reset und Rollenverwaltung ermöglichen.

9. **Template-Engine oder View-Schicht einführen**: HTML aus PHP-Logik trennen. Eine leichtgewichtige PHP-Template-Schicht würde das Theming vereinfachen und die Einführung von CSP erleichtern (kein Inline-JS mehr nötig).

10. **MFA als erstem Schritt Richtung FIDO2**: Zunächst TOTP (RFC 6238) als optionalen zweiten Faktor einführen. Das ist einfacher als FIDO2 und nutzt die gleichen Erweiterungspunkte.

---

## I. Möglicher Migrationspfad (strategisch, ohne Code, ohne Änderungen)

### Phase 1: Sofortige Sicherheitsverbesserungen (ohne Architekturänderungen)
- Brute-Force-Schutz für Login via Options-Tabelle
- Fehlende Security-Header (`X-Content-Type-Options`, `Referrer-Policy`)
- Einschränkung des `?hash=`-Parameters auf sichere Algorithmen
- jQuery-Update

### Phase 2: Session-Modernisierung
- Cookie auf zufälligen Server-seitigen Session-Token umstellen
- Logout auf Server-Seite implementieren (Token-Invalidierung)
- Dies erfordert eine neue kleine Datenbanktabelle oder eine Erweiterung der Options-Tabelle

### Phase 3: Echte Benutzerverwaltung
- Migration der Passwörter aus `config.php` in eine `yourls_users`-Tabelle
- DB-Migration über das bestehende Upgrade-System (`functions-upgrade.php`)
- Kompatibilitätsschicht: `config.php`-Passwörter weiterhin unterstützen mit Migrationshinweis
- Passwort-Reset-Mechanismus implementieren

### Phase 4: MFA
- TOTP als optionaler zweiter Faktor (per Plugin oder Core-Feature)
- Datenbank-Tabelle für TOTP-Secrets pro Benutzer
- Login-UX-Erweiterung (zweiter Schritt nach Passwort)

### Phase 5: FIDO2/WebAuthn
- Nur sinnvoll nach Phase 3
- Empfohlene Variante: FIDO2 als optionaler zweiter Faktor (neben oder statt TOTP)
- Später: Passkeys als primäre Methode / passwortloser Login
- Technische Voraussetzungen: User-Tabelle, Credential-Tabelle, WebAuthn-Server-Bibliothek (z.B. `web-auth/webauthn-framework`), AJAX-Endpunkte, JavaScript-Build-System

---

## J. Offene Fragen / Prüfbedarf

### J.1 Authentifizierung
- [ ] Wo genau werden `$yourls_user_passwords` im Code zuerst gelesen und welche Scope-Risiken bestehen?
- [ ] Ist der Schutz gegen Timing-Attacks bei `yourls_check_password_hash()` ausreichend? (cleartext-Vergleich `===` vs. `password_verify()` – timing-safe nur beim letzteren)
- [ ] Wie verhält sich der Cookie bei mehreren parallelen Browser-Tabs oder Geräten?
- [ ] Welche Plugins nutzen `shunt_is_valid_user` produktiv – und könnten diese einen sicheren Auth-Bypass einführen?

### J.2 Datenbankschema und -migration
- [ ] Welche DB-Version (`YOURLS_DB_VERSION = '507'`) ist die aktuelle und welche Änderungen gab es zuletzt?
- [ ] Wie skaliert das Options-Table-basierte Speichermodell bei einer Brute-Force-Schutztabelle mit vielen Einträgen?
- [ ] Gibt es bestehende Drittanbieter-Plugins, die bereits eine User-Tabelle einführen?

### J.3 API-Sicherheit
- [ ] Ist der `?hash=`-Parameter in der Dokumentation (docs.yourls.org) öffentlich dokumentiert – und damit bekannt für Angreifer?
- [ ] Gibt es Nutzungsstatistiken, welche API-Auth-Methode am häufigsten eingesetzt wird?
- [ ] Ist ein Audit der Signatur-Berechnung auf Timing-Attacken gemacht worden?

### J.4 Plugin-Sicherheit
- [ ] Welche Sicherheitsprüfungen durchläuft ein Plugin-Code, bevor er in `yourls_include_file_sandbox()` geladen wird?
- [ ] Kann ein Plugin die Nonce-Prüfung (`shunt_verify_nonce`) deaktivieren?
- [ ] Gibt es eine offizielle Plugin-Verzeichnisdokumentation, die Sicherheitsanforderungen für Plugin-Autoren formuliert?

### J.5 Frontend / CSP
- [ ] An wie vielen Stellen im Core wird Inline-JavaScript verwendet? (Eine CSP-Einführung müsste alle diese Stellen identifizieren)
- [ ] Gibt es bereits Pläne oder Issues für das Entfernen von Inline-JavaScript?
- [ ] Kann das bestehende Theme-System für Admin-Bereich-Theming erweitert werden, ohne Breaking Changes?

### J.6 Dokumentation
- [ ] Existiert eine SECURITY.md oder ein equivalenter Prozess für verantwortungsvolle Offenlegung von Sicherheitslücken?
- [ ] Sind die auf docs.yourls.org beschriebenen Sicherheitshinweise (z.B. `YOURLS_COOKIEKEY`, `YOURLS_PRIVATE`) noch aktuell mit dem Code-Stand?

---

*Hinweis: Diese Analyse ist eine reine Bestandsaufnahme. Es wurden keinerlei Änderungen am Projekt vorgenommen. Alle Empfehlungen sind ausschließlich textuell formuliert.*
