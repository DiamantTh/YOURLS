# YOURLS – Vollständige Architektur-, Sicherheits- und Modernisierungsanalyse

> **Analysedatum:** 2026-03-19  
> **Analysegegenstand:** YOURLS – Your Own URL Shortener  
> **Quellen:** Quellcode, Konfigurationsdateien, Testdateien, README, composer.json

---

## A. Kurzüberblick

YOURLS ist ein in PHP geschriebenes, selbst gehostetes URL-Kürzsystem. Das Projekt ist historisch gewachsen, zeigt aber seit Version 1.7 erkennbare Modernisierungsschritte. Es arbeitet ohne klassisches Framework im herkömmlichen Sinne – stattdessen gibt es ein eigenes, WordPress-inspiriertes Actions/Filters-Hook-System, prozedural strukturierte Funktionsbibliotheken, eine PDO-basierte Datenbankabstraktionsschicht und seit 1.7.3 vereinzelt namespaced OOP-Klassen.

**Stärken:**
- Durchgängig nutzbares, gut dokumentiertes Plugin-Hook-System (Actions/Filter)
- Solide CSRF-Absicherung über Nonces
- Moderne Passwort-Hashing-Unterstützung via `password_hash()`/`password_verify()`
- SameSite-Cookie-Attribut korrekt gesetzt
- Klare Trennung zwischen öffentlichem und privatem Bereich (`YOURLS_PRIVATE`)
- Gut gepflegte Testabdeckung für kritische Auth-Funktionen

**Schwächen / kritische Lücken:**
- **Keine Brute-Force-/Rate-Limiting-Schutz für den Login** – kein Schutz gegen wiederholte Fehlversuche
- Benutzerverwaltung vollständig in `config.php` – kein Datenbankmodell für Benutzer
- Kein MFA-/TOTP-/FIDO2-Support in Core
- Cookie-Wert ist deterministisch (HMAC des Benutzers, ohne Session-Token) – kein Invalidierungsmechanismus
- Template-Architektur: HTML direkt in PHP-Funktionen – kein echtes Template-System
- Keine CSP-Headers (`Content-Security-Policy`)
- Legacy-Passwort-Hashes (MD5, cleartext) werden noch akzeptiert

---

## B. Architektur- und Strukturüberblick

### Sprachen und Technologien
| Technologie | Rolle |
|---|---|
| PHP 7.4+ (Ziel 8.x) | Backend, gesamte Geschäftslogik |
| MySQL / MariaDB (über PDO) | Datenspeicherung |
| jQuery 3.5.1 | Frontend-Interaktivität |
| CSS | Admin-Styling |
| Composer | Dependency-Management |

### Verzeichnisstruktur
```
/
├── admin/                  # Admin-Panel-Einstiegspunkte (index.php, tools.php, plugins.php, ...)
├── includes/               # Core: Funktionsbibliotheken + Klassen
│   ├── Config/             # Namespaced: Config.php, Init.php, InitDefaults.php
│   ├── Database/           # Namespaced: YDB.php, Options.php, Logger.php, Profiler.php
│   ├── Exceptions/         # Namespaced: ConfigException.php
│   ├── Views/              # Namespaced: AdminParams.php
│   ├── auth.php            # Auth-Bootstrap (wird via require eingebunden)
│   ├── functions-auth.php  # Alle Auth-Funktionen
│   ├── functions-html.php  # HTML-Ausgabe-Funktionen (Template-Surrogat)
│   ├── functions-plugins.php # Hook-System (add_filter, do_action, ...)
│   ├── functions.php       # Allgemeine Utility-Funktionen
│   └── vendor/             # Composer-Dependencies
├── user/                   # Benutzerdaten: config.php, plugins/, themes/, pages/
│   ├── config-sample.php   # Muster-Konfiguration
│   └── plugins/            # Bundled-Plugins (sample, hyphens, random-bg, ...)
├── tests/                  # PHPUnit-Testsuite
├── js/                     # Frontend-JS (jQuery, notifybar, insert, share, ...)
├── css/                    # Admin-CSS
└── images/                 # Logos, Icons
```

### Architekturmuster
- **Kein MVC** im klassischen Sinne: Admin-Seiten (`admin/index.php`) enthalten direkt Logik, Datenbankabfragen und HTML-Ausgabe.
- **Hook-System** (WordPress-inspiriert): Actions und Filter über `yourls_do_action()` und `yourls_apply_filter()`. Dies ist der primäre Erweiterungspunkt.
- **Teilweise OOP** (seit 1.7.3): `Config`, `Init`, `YDB`, `Options` sind namespaced Klassen. Der Rest ist prozedural.
- **Plugin-System**: Plugins in `user/plugins/`, werden über die Options-Tabelle aktiviert, laden sich selbst via Hook in `plugins_loaded`.
- **Kein Dependency Injection Container**: Globale Variablen (`$yourls_user_passwords`, `$yourls_filters`) und Konstanten werden verwendet.

### Historisch vs. modern
| Bereich | Bewertung |
|---|---|
| Hook-System | Ausgereift, konsistent eingesetzt |
| Datenbankschicht (YDB/PDO) | Modern, Prepared Statements |
| Auth-Logik | Teils veraltet (MD5, config.php-User) |
| Template/HTML | Veraltet, hart verdrahtet |
| Passwort-Hashing | Modern (`password_hash`, `password_verify`) |
| JavaScript | Veraltet (jQuery 3.5.1, kein Bundler) |
| Tests | Vorhanden, decent coverage im Auth-Bereich |

---

## C. Analyse der projektspezifischen Richtlinien und Dokumentation

### Vorhandene Dokumentation
- `README.md`: Kurzübersicht, Verweise auf `docs.yourls.org`
- `CHANGELOG.md`: Versionierte Änderungshistorie
- `user/config-sample.php`: Kommentierte Musterkonfiguration
- `tests/README.md`: Hinweise zum Ausführen der Tests
- `tests/tests/TODO.md`: Offene Testlücken (API-Tests etc.)
- Keine `SECURITY.md`, keine `CONTRIBUTING.md`, kein `CODE_OF_CONDUCT.md` im Repository

### Aus dem Code abgeleitete Regeln
- **Kodierstandard**: Prozedural, WordPress-Stil, snake_case, `yourls_`-Präfix
- **Passwort-Policy** (implizit): Passwörter in `config.php`, automatisches Hashing via `yourls_hash_passwords_now()`; MD5 noch unterstützt (Legacy)
- **Session-Policy**: Cookie-basiert, 7-Tage-Lebensdauer, HttpOnly, SameSite=Lax
- **CSRF**: Nonce-Mechanismus für alle sensitiven Aktionen
- **Plugin-Policy**: Plugins können nahezu jeden Filter und jede Action ersetzen oder ergänzen; kein Sandbox-Mechanismus
- **Backward Compatibility**: Stark betont – Legacy-Hashes werden weiterhin unterstützt; `functions-deprecated.php` vorhanden

### Widersprüche und Lücken
| Bestand | Problem |
|---|---|
| TODO-Kommentare in `auth.php` und `functions-auth.php` | „TODO: Remove this once real user management is implemented" – deutet auf bewusste Technische Schuld hin |
| MD5-Passwort-Support | In der Dokumentation als Legacy markiert, im Code aber vollständig aktiv |
| `YOURLS_NO_HASH_PASSWORD` | Dokumentiert und im Code vorhanden, ermöglicht Klartextpasswörter in Production |
| Keine `SECURITY.md` | Kein dokumentierter Prozess für Security-Disclosure |

---

## D. Sicherheitsanalyse

### ✅ Bereits gut gelöste Bereiche

**CSRF-Schutz:**
Nonces für alle sensitiven Formulare und AJAX-Aktionen. Nonce-Lebensdauer konfigurierbar, HMAC-basiert (SHA-256 Standard). Logout-Aktion ebenfalls nonce-geschützt.

**Passwort-Hashing:**
`password_hash()` mit bcrypt (DEFAULT), konfigurierbar über `hash_algo`/`hash_options`-Filter. `password_verify()` korrekt eingesetzt. Automatisches Upgrade von Klartextpasswörtern beim ersten Login.

**Cookie-Sicherheit:**
`HttpOnly: true`, `SameSite: Lax`, `Secure: true` wenn SSL aktiv. Cookie-Name eindeutig je Installation (HMAC-basiert).

**SQL-Injection:**
PDO mit Prepared Statements (`:parameter`-Syntax) konsequent eingesetzt. In `admin/index.php` werden Spaltenname/Sortierreihenfolge durch Whitelist-Validierung über `AdminParams` abgesichert.

**XSS:**
Ausgabe-Escaping über `yourls_esc_html()`, `yourls_esc_attr()`. KSES-Filter vorhanden. `X-Frame-Options: SAMEORIGIN` gesetzt.

**Clickjacking:**
`X-Frame-Options: SAMEORIGIN` über `yourls_no_frame_header()`.

**Flood-Schutz (URL-Erstellung):**
`yourls_check_IP_flood()` verhindert zu schnelles Kürzen durch nicht-eingeloggte Nutzer.

---

### ⚠️ Problematische Bereiche

**Keine Brute-Force-Schutz für den Login:**
`yourls_check_username_password()` hat kein Rate-Limiting. Ein Angreifer kann beliebig viele Passwortversuche in schneller Folge durchführen. Es gibt keinerlei Tracking von fehlgeschlagenen Anmeldeversuchen.
> **Schweregrad: Hoch**

**Cookie-Wert deterministisch:**
`yourls_cookie_value($user)` liefert immer denselben Wert: `yourls_salt($user)`. Das bedeutet: Kennt ein Angreifer den COOKIEKEY, kann er ein gültiges Cookie für jeden bekannten Benutzernamen erzeugen. Kein serverseitiger Invalidierungsmechanismus.
> **Schweregrad: Mittel-Hoch**

**Fehlende Security Headers:**
- Kein `Content-Security-Policy`
- Kein `X-Content-Type-Options: nosniff`
- Kein `Referrer-Policy`
> **Schweregrad: Mittel**

**Legacy-Passwort-Support:**
MD5-Passwörter (`md5:<salt>:<hash>`) und sogar Klartextpasswörter werden weiterhin akzeptiert. Dies ist ein Migrationsweg, der enden sollte.
> **Schweregrad: Mittel**

**Plugin-Vertrauensgrenze:**
Plugins haben vollständigen Zugriff auf alle Filter und Actions, inklusive `shunt_is_valid_user` – ein böswilliges Plugin kann die gesamte Auth umgehen. Es gibt keine Plugin-Signing oder Sandbox.
> **Schweregrad: Mittel (trusted-admin-only Kontext)**

---

### 🔴 Potenziell kritische Bereiche

**Keine MFA/2FA:**
Weder TOTP, noch FIDO2, noch E-Mail-Codes. Einmaliger Passwortangriff führt direkt zu vollem Zugriff.

**API-Signatur-Mechanismus (statische Signatur):**
`?signature=md5(user_secret)` – diese Signatur ist permanent gültig und läuft nie ab. Wenn eine Signatur einmal kompromittiert ist, gibt es keine Möglichkeit, sie serverseitig zu invalidieren ohne das Passwort zu ändern. Das zeitbasierte Verfahren (`?timestamp=...&signature=...`) ist besser, ist aber nur optional.

**Passwörter in Konfigurationsdatei:**
`$yourls_user_passwords` in `user/config.php` – wenn diese Datei durch eine Directory-Traversal-Lücke, einen Webserver-Fehler oder eine Backup-Lücke exponiert wird, sind alle Zugangsdaten kompromittiert. Dies ist kein aktiver Code-Fehler, aber ein inhärentes Risiko der Architektur.

---

### 🔍 Bereiche, die weitere Laufzeitanalyse erfordern

- Verhältnis zwischen Frontend-Output und tatsächlicher CSP-Kompatibilität
- Verhalten bei parallelen Sessions (mehrere Browser/Geräte mit gleichem Cookie-Wert)
- Verhalten bei aktivierten Plugins, die `shunt_is_valid_user` modifizieren

---

## E. FIDO2-/WebAuthn-Analyse

### Aktueller Auth-Flow
```
GET/POST → yourls_is_valid_user()
    ├── shunt_is_valid_user (Filter – erlaubt vollständigen Ersatz)
    ├── Logout (Nonce-geprüft)
    ├── API: ?timestamp+?signature → yourls_check_signature_timestamp()
    ├── API: ?signature → yourls_check_signature()
    ├── Formular: ?username+?password → yourls_check_username_password()
    └── Cookie → yourls_check_auth_cookie()
```

Es gibt **keine** TOTP-, FIDO2- oder andere MFA-Unterstützung. Es gibt **keinen** Benutzer-Datenbankdatensatz – Benutzer sind nur in `config.php` definiert.

### Bewertung der Integrationsfähigkeit

**Architektonisch verfügbare Ansatzpunkte:**
- `shunt_is_valid_user` (Filter): Vollständiger Ersatz der Auth-Logik – ein Plugin könnte hier WebAuthn-only implementieren
- `login_form_top`, `login_form_bottom`, `login_form_end` (Actions): Ein Plugin kann UI-Elemente in das Login-Formular injizieren
- `pre_login` (Action): Hook direkt vor dem eigentlichen Login-Check
- `login`, `login_failed` (Actions): Nachgelagerte Hooks für Post-Login-Logik

**Fehlende Voraussetzungen für saubere FIDO2-Integration:**

| Voraussetzung | Status |
|---|---|
| Datenbankmodell für FIDO2-Credentials (credential_id, public_key, user_handle, counter) | ❌ Fehlt vollständig |
| Benutzer-ID als stabiler Identifier (nicht nur Username in config.php) | ❌ Fehlt |
| Challenge-Generierung und -Speicherung (Server-seitig, Session oder DB) | ❌ Fehlt |
| Frontend JavaScript (WebAuthn API) | ❌ Fehlt |
| Server-Side Verification Library (z.B. `web-auth/webauthn-framework`) | ❌ Fehlt |
| Session-Persistenz für Challenges zwischen Registration und Verification | ❌ Fehlt |

### Mögliche Integrationsszenarien

**Szenario 1: FIDO2 als Plugin (optionaler 2. Faktor)**
*Eignung: Mittel*  
Ein Plugin könnte nach erfolgreichem Passwort-Login (`login`-Action) einen zweiten Schritt einfordern. Das würde vorläufig ohne Datenbankänderungen auskommen (Challenge per PHP-Session). Hürde: YOURLS hat keine eigene Session-Verwaltung (`$_SESSION`), nur Cookies. Eine Plugin-seitige Nutzung von `$_SESSION` wäre möglich aber inkonsistent.

**Szenario 2: FIDO2 als Kernfeature (Ersatz des Passwort-Logins)**
*Eignung: Niedrig ohne größere Vorarbeiten*  
Würde erfordern: neue DB-Tabelle `yourls_webauthn_credentials`, Erweiterung der Benutzeridentität (User-Handle), neue Admin-UI für Schlüsselverwaltung, komplett neuer Login-Flow. Nicht als inkrementelle Erweiterung möglich.

**Szenario 3: FIDO2 nur für den Admin-Bereich via Plugin**
*Eignung: Hoch (empfohlen als erster Schritt)*  
Das Plugin-System ist ausdrücklich erweiterbar für Auth-Änderungen. Mit dem `shunt_is_valid_user`-Filter kann ein Plugin die gesamte Admin-Auth ersetzen. Challenges könnten in der Options-Tabelle gespeichert werden. Credential-Daten bräuchten eine eigene Tabelle (Plugin-Install kann diese anlegen).

### Empfohlene Zielarchitektur für FIDO2

```
user/plugins/webauthn/
├── plugin.php                  # Hook-Registrierung
├── includes/
│   ├── class-webauthn.php      # Wrapper um webauthn-framework
│   ├── class-credential-store.php  # DB-Abstraktion für Credentials
│   └── functions.php           # Plugin-spezifische Hilfsfunktionen
├── admin/
│   ├── register.php            # UI: Neuen Schlüssel registrieren
│   └── manage.php              # UI: Vorhandene Schlüssel verwalten
└── js/
    └── webauthn.js             # WebAuthn Browser API
```

**DB-Tabelle (Plugin-Install):**
```sql
CREATE TABLE yourls_webauthn_credentials (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_login   VARCHAR(100)    NOT NULL,
    credential_id BLOB           NOT NULL,
    public_key   TEXT            NOT NULL,
    counter      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME        NOT NULL,
    last_used    DATETIME,
    display_name VARCHAR(255),
    PRIMARY KEY (id),
    KEY user_login (user_login)
);
```

**Challenge-Speicherung**: In der Options-Tabelle mit kurzem TTL (`webauthn_challenge_<ip_hash>`), analog zum bestehenden Nonce-Mechanismus.

### Technische Hürden
1. **Kein Benutzer-Datenbank-Record**: Ohne stabilen User-Handle (UUID oder BIGINT ID) ist die WebAuthn-Benutzer-Zuordnung umständlich. Für ein Plugin reicht vorerst `sha256(username)`.
2. **Kein PHP-Session-Mechanismus**: YOURLS selbst nutzt `$_SESSION` nicht. Challenges müssen in der Options-Tabelle oder einer eigenen Tabelle gespeichert werden.
3. **Kein Composer-Abhängigkeits-Workflow für Plugins**: Das Webauthn-Framework `web-auth/webauthn-framework` muss manuell oder per eigenem Composer in das Plugin gebracht werden.
4. **UX-Redesign für Login**: Das aktuelle Login-Formular hat keinen "Passkey"-Button. Dies ist ein kleiner, aber notwendiger Frontend-Eingriff.

---

## F. Theme-/Design-/Engine-Analyse

### Aktueller Zustand: Kein Template-System

YOURLS hat **kein separates Template-System**. HTML wird direkt in PHP-Funktionen ausgegeben:

```php
// includes/functions-html.php
function yourls_html_head( $context = 'index', $title = '' ) {
    // ...
    ?>
    <!DOCTYPE html>
    <html ...>
    <head>
    <?php
}
```

Alle Views sind hart in Funktionen wie `yourls_html_head()`, `yourls_html_footer()`, `yourls_login_screen()`, `yourls_html_addnew()`, `yourls_table_head()` etc. eingebettet.

### Hooks als Erweiterungsmechanismus

Das Hook-System wird auch für die UI eingesetzt:
- `pre_html_head`, `html_head` – Erweiterung des `<head>`-Bereichs
- `html_logo`, `pre_html_logo` – Logo-Bereich
- `html_footer` – Footer-Erweiterung
- `admin_links`, `admin_sublinks` – Menü-Erweiterung
- `login_form_top`, `login_form_bottom`, `login_form_end` – Login-Form-Erweiterung

Dies ermöglicht Plugins, Layout-Elemente einzufügen, ist aber kein vollständiges Theming.

### Theme-Verzeichnis vorhanden
```
YOURLS_THEMEDIR = user/themes/
YOURLS_THEMEURL = (site)/user/themes/
```
Die Konstanten existieren, aber es gibt **kein Theming-Framework** im Core. Das Theme-Verzeichnis ist leer; keine `ThemesTest.php`-Testklasse enthält derzeit konkrete Themes.

### Flexibilitätsbewertung

| Aspekt | Bewertung |
|---|---|
| Theming-Fähigkeit | ❌ Gering – kein Theme-Mechanismus im Core |
| Komponenten-Trennung (Logik/Darstellung) | ❌ Gemischt – HTML direkt in Funktionen |
| Alternative Designs | ⚠️ Möglich über CSS-Override (kein Theming) |
| Accessibility | ⚠️ Grundlegende ARIA-Rollen vorhanden (`role="main"`, `role="navigation"`) |
| Mobile | ⚠️ Basisunterstützung (mobile CSS-Klasse gesetzt) |
| FIDO2-/Passkey-UX | ❌ Keine UI-Grundlage vorhanden |
| Design-Modernisierung | Aufwendig – erfordert Umbau von `functions-html.php` |

### Empfehlung für Theme-System

Als minimale, richtlinienkonforme Evolution könnte ein **Theme-Loader** eingeführt werden, der:
1. `user/themes/<theme_name>/theme.php` lädt (analog zu Plugins)
2. Alle bestehenden HTML-Funktionen via Filter überschreibbar macht
3. Die `yourls_html_head()` etc. als fallback behält

Dies würde keine Breaking Changes einführen, aber erstmals echtes Theming ermöglichen.

---

## G. Technische Schulden und Modernisierungsrisiken

| Schuld | Risiko bei Umbau | Priorität |
|---|---|---|
| Benutzer in `config.php` statt DB | Alle Auth-Änderungen sind workarounds | Hoch |
| MD5/Cleartext-Passwort-Support | Sicherheitsrisiko solange aktiv | Hoch |
| Kein Brute-Force-Schutz | Direktes Angriffsvektor | Hoch (behoben in diesem PR) |
| Cookie-Wert nicht invalidierbar | Session-Hijacking-Risiko | Mittel |
| HTML in PHP-Funktionen | Redesign sehr aufwendig | Mittel |
| jQuery 3.5.1 (outdated) | CVE-Risiko, Kompatibilität | Mittel |
| Keine CSP-Headers | XSS-Mitigierung fehlt | Mittel (teilweise behoben in diesem PR) |
| Kein Composer-Autoloading für Plugins | Plugin-Entwicklung inkonsistent | Niedrig |
| `eval`-freies Plugin-Loading (require) | Kein aktives Risiko | Niedrig |

---

## H. Konkrete Empfehlungen

### Priorität 1 – Sofortmaßnahmen (Sicherheit)

1. **Brute-Force-Schutz für Login** (implementiert in diesem PR)  
   Fehlgeschlagene Login-Versuche pro IP tracken, nach `YOURLS_LOGIN_MAX_ATTEMPTS` Versuchen für `YOURLS_LOGIN_LOCKOUT_DURATION` Sekunden sperren.

2. **Security Headers erweitern** (implementiert in diesem PR)  
   `X-Content-Type-Options: nosniff` und `Referrer-Policy: strict-origin-when-cross-origin` hinzufügen.

3. **Statische API-Signatur deprecaten**  
   `?signature=md5(secret)` ohne Zeitstempel ist permanent gültig. Nutzer sollten zur zeitbasierten Signatur migriert werden.

4. **MD5-Passwort-Migration**  
   Nach hinreichend langer Übergangszeit sollte die Unterstützung für MD5-Passwörter enden und Nutzer zum Re-Hashing mit bcrypt gezwungen werden.

### Priorität 2 – Mittelfristig (Architektur)

5. **Benutzermodell in die Datenbank verlagern**  
   Eine `yourls_users`-Tabelle mit `user_login`, `user_pass`, `user_registered`, `user_status` einführen. Dies ist die Voraussetzung für FIDO2, echte Rollenverwaltung und Session-Invalidierung.

6. **Session-Tokens einführen**  
   Cookie-Wert auf zufälligen Token umstellen, der in der DB gespeichert und serverseitig invalidiert werden kann.

7. **Content-Security-Policy**  
   Ein klares CSP würde XSS-Risiken erheblich reduzieren. Derzeit nicht möglich ohne den Inline-JS-Code zu refaktorieren.

### Priorität 3 – Langfristig (FIDO2/Modernisierung)

8. **FIDO2-Plugin entwickeln**  
   Als optionaler zweiter Faktor nach Passwort-Login, zunächst nur für den Admin-Bereich. Dabei `web-auth/webauthn-framework` als Library einsetzen.

9. **Theme-System einführen**  
   Ein minimales Theme-Loader-System würde White-Labeling und Design-Erweiterungen ermöglichen, ohne Breaking Changes.

10. **JavaScript-Modernisierung**  
    jQuery auf aktuelle Version aktualisieren oder durch native JS ersetzen.

---

## I. Möglicher Migrationspfad

### Phase 1 – Sicherheits-Baseline (direkt umsetzbar)
- ✅ Brute-Force-Schutz (Login-Lockout)
- ✅ Security Headers (`X-Content-Type-Options`, `Referrer-Policy`)
- Deprecation-Warnung für statische API-Signatur ohne Zeitstempel
- Deprecation-Warnung für MD5-Passwörter

### Phase 2 – Datenbankmodell für Benutzer
- Neue Tabelle `yourls_users` in Core einführen
- Migration der bestehenden `$yourls_user_passwords`-Einträge in die DB
- Cookie-Wert auf DB-gespeicherte Session-Tokens umstellen
- API-Tokens in DB verwalten

### Phase 3 – FIDO2 als Plugin (optionaler 2. Faktor)
- Plugin `user/plugins/webauthn/` entwickeln
- `web-auth/webauthn-framework` als Plugin-Dependency
- Registrierung und Verwaltung von FIDO2-Credentials in eigener DB-Tabelle
- Login-Flow: nach Passwort → WebAuthn-Challenge → vollständiger Login
- Admin-Seite für Schlüsselverwaltung

### Phase 4 – Passwortloser Login
- Nach Phase 2+3: FIDO2 als primären Login-Faktor ermöglichen
- Passkey-Only-Option (kein Passwort mehr nötig)
- Deprecation von Passwort-Auth für Nutzer mit registrierten FIDO2-Credentials

### Phase 5 – Theme-System und UX
- Theme-Loader einführen
- Standardtheme refaktorieren (aus `functions-html.php` auslagern)
- Modernes Login-UI mit Passkey-Button

---

## J. Offene Fragen / Prüfbedarf

### Auth-Logik
- [ ] Wo genau werden fehlerhafte `$_SESSION`-Daten behandelt? (YOURLS nutzt kein `session_start()` – unklar für Plugins)
- [ ] Ist das `shunt_is_valid_user`-Muster ausreichend dokumentiert für Plugin-Entwickler?
- [ ] Verhalten bei gleichzeitigem Login mehrerer Benutzer mit identischem Cookie-Wert?

### Datenbankmodell
- [ ] Gibt es Migrationsskripte (Upgrade-Funktionen in `functions-upgrade.php`)?
- [ ] Können Plugins eigene Tabellen zuverlässig bei Deinstallation aufräumen?

### Template/Theme
- [ ] Existiert das `user/themes/`-Verzeichnis bereits und wird es je ausgewertet?
- [ ] Welche Tests decken Theme-Funktionalität ab? (`tests/tests/themes/ThemesTest.php` – Inhalt prüfen)

### Sicherheit
- [ ] Wird die `YOURLS_COOKIEKEY`-Konstante gegen schwache Standardwerte geprüft?
- [ ] Wird `YOURLS_ADMIN_SSL` in Production-Deployments standardmäßig aktiviert?
- [ ] Gibt es ein Responsible-Disclosure-Verfahren? (keine `SECURITY.md` vorhanden)
- [ ] Werden externe JavaScript-Ressourcen (Google Charts in `functions-html.php`) mit Subresource Integrity gesichert?

### API
- [ ] Wann wird die statische Signatur (`?signature=...` ohne Zeitstempel) depreciert?
- [ ] Gibt es eine dokumentierte API-Versionierung?

### FIDO2
- [ ] Welche PHP-Versionen sollen bei FIDO2-Integration unterstützt werden? (`web-auth/webauthn-framework` erfordert PHP 8.1+)
- [ ] Soll FIDO2 per Plugin oder per Core-Feature eingeführt werden?
- [ ] Wie sollen bestehende Nutzer (ohne DB-ID) beim Übergang zu Phase 2 migriert werden?

---

*Dieser Bericht basiert auf statischer Codeanalyse. Dynamische Sicherheitstests (z. B. Fuzzing, Penetrationstests, Header-Scans in einer Live-Umgebung) sind empfohlen, um die hier gemachten Einschätzungen zu verifizieren.*
