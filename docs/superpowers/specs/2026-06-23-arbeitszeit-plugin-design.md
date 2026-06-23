# Design-Spec: Kimai-Plugin „Worktime" (Arbeitszeit- & Abwesenheitserfassung)

**Datum:** 2026-06-23
**Status:** Entwurf zur Review
**Kontext:** SICODA, kleines Unternehmen (1–10 MA), NRW, Kimai 2.56.0 self-hosted, produktiv.

---

## 1. Ziel & Abgrenzung

Ein eigenständiges Kimai-2-Plugin (`var/plugins/WorktimeBundle`) für **tägliche Arbeitszeiterfassung** (Punch In/Out) und **Abwesenheitsverwaltung** (Urlaub, Krankheit, Überstundenabbau) inkl. Arbeitszeitkonto, Monatsabschluss und PDF-Export.

### Leitprinzip: Updatefestigkeit
Oberstes nicht-funktionales Ziel ist **Unabhängigkeit von Kimai-Core-Updates**. Daraus folgt:
- Das Plugin besitzt **alle eigenen Datenbanktabellen** und die **komplette Rechenlogik**.
- Es koppelt an Kimai **nur über stabile, öffentliche Flächen** (siehe §4).
- Es benutzt **nicht** die nativen Funktionen `WorkingTimeService`, die nativen Contract-Felder am `User` oder die Projekt-Timesheets.

> Bewusste Entscheidung des Auftraggebers: Obwohl Kimai 2.56 nativ Vertragsfelder (Wochenstunden Mo–So, Beschäftigungsbeginn, Jahresurlaub, Feiertagsgruppe), ein Arbeitszeitkonto (`WorkingTimeService`) und Monatsabschluss (`approveMonth`/`unlockMonth`) mitbringt, wird **bewusst ein Parallelsystem** gebaut, um von internen Core-Änderungen entkoppelt zu sein.

### Nicht-Ziele (explizit ausgeschlossen)
- DATEV / Lohnbuchhaltungsexport
- Mehrere Bundesländer (nur NRW)
- Teamleiter-Genehmigung (nur Admin/Geschäftsführung)
- Excel-Export (nur PDF)
- ArbZG-Compliance-Warnungen (10h/Tag, 11h Ruhe, Pause) — bewusst weggelassen
- Benachrichtigungssystem (E-Mail / In-App) — Admin prüft Anträge selbst
- Datenmigration von Altdaten — Neustart auf Null
- Nutzung der Kimai-Projekt-Timesheets als Ist-Quelle

---

## 2. Rollen

| Rolle | Rechte |
|---|---|
| **Mitarbeiter** (`ROLE_USER`) | Eigene Zeit per Punch In/Out erfassen; eigene Zeiten korrigieren; Abwesenheits­anträge stellen; eigenen Kontostand (Saldo, Urlaubsrest, Überstunden) read-only sehen; eigene Abwesenheiten sehen. |
| **Admin/Geschäftsführung** (`ROLE_SUPER_ADMIN`) | Alles + Verträge pflegen; Anträge genehmigen/ablehnen; Monate sperren/entsperren; Korrekturbuchungen; Feiertage importieren/pflegen; Abwesenheitskalender **aller** MA sehen; alle Zeiten korrigieren. |

Mitarbeiter sehen **nur ihre eigenen** Abwesenheiten. Der teamweite Abwesenheitskalender ist **Admin-only**.

---

## 3. Architektur-Überblick

- Symfony-Bundle vom Typ `kimai-plugin` (`composer.json` mit `extra.kimai`), Klasse `KimaiPlugin\WorktimeBundle\WorktimeBundle`, Verzeichnis `var/plugins/WorktimeBundle`.
- Standard-Kimai-Plugin-Struktur (Referenz: `EasyBackupBundle`):
  `Controller/`, `Entity/`, `Repository/`, `Service/`, `EventSubscriber/`, `Command/`, `DependencyInjection/` (Extension + Configuration), `Migrations/`, `Resources/{config,views,translations,public}`, `Tests/`, eigenes `phpstan.neon` + `.php-cs-fixer.dist.php`.
- **Rechenlogik framework-frei**: Saldo-/Konto-Berechnung in reinen PHP-Service-Klassen ohne Kernel-/DB-Abhängigkeit, damit unit-testbar (Plugins werden in der `test`-Env **nicht** geladen — siehe §11).

---

## 4. Integrations- / Kopplungspunkte (nur stabile APIs)

| Fläche | Nutzung | Stabilität |
|---|---|---|
| `User`-Entity | read-only Referenz (FK) für Identität/Login | hoch |
| `ConfigureMainMenuEvent` | Menüeintrag „Arbeitszeit" in der Sidebar | hoch |
| Plugin-Permissions | eigene Rechte (`worktime_*`) via Plugin-Config | hoch |
| Twig-Theme (`@theme`) | Views erben vom Kimai-Layout (Tabler/Bootstrap) | hoch |
| **Kopfzeilen-Button (Punch In/Out)** | Injektion eines Stempel-Buttons in die Topbar | **niedrig — Risiko #1** |
| `App\Pdf\MPdfConverter` / `PdfContext` | PDF-Rendering (mPDF) | mittel |
| `azuyalabs/yasumi` | Feiertagsberechnung NRW (bereits Core-Dependency) | hoch |

**Risiko #1 (Kopfzeilen-Button):** Die Topbar ist die am wenigsten stabile Fläche. Mitigation: Injektion über die offizielle Theme-/JavaScript-Erweiterung, vollständig gekapselt, sodass eine Theme-Änderung den Button **ausfallen lässt, ohne etwas zu beschädigen**. Fallback: ein immer sichtbarer, großer Punch-Button auf der Plugin-eigenen Seite. Der genaue stabile Hook ist in der Planungsphase zu verifizieren.

---

## 5. Datenmodell (eigene Tabellen, Präfix `kimai_worktime_*`)

1. **Contract** (1:1 zu User)
   - Wochenstunden je Wochentag Mo–So (in Sekunden)
   - Jahresurlaubsanspruch (Tage)
   - Beschäftigungsbeginn, Beschäftigungsende (nullable)
   - Feiertagsregion (default `NRW`)
   - Anfangssaldo Überstunden (Sekunden, Carry-in)
   - Anfangs-Urlaubsübertrag (Tage)
   - tägliche Vertrags-Endzeit (für Auto-Abschluss vergessener Blöcke)

2. **WorkBlock** (Zeitblock)
   - User, Datum, Beginn (DateTime), Ende (DateTime, nullable = offen/eingestempelt)
   - Quelle (`punch` | `manual`)
   - Flag `needsReview` (true bei Auto-Abschluss)
   - Netto-Ist/Tag = Σ (Ende − Beginn); Lücken zwischen Blöcken = Pause

3. **AbsenceType**
   - Vorkonfiguriert: *Urlaub*, *Krankheit*, *Überstundenabbau*
   - Flags: `countsAsFulfilled` (Tag soll-neutral?), `consumesVacation` (Urlaubsbudget?), `reducesOvertime` (Überstundenkonto?)
   - Phase 3: erweiterbar (Sonderurlaub, unbezahlt, Homeoffice)

4. **Absence** (Antrag)
   - User, AbsenceType, von-Datum, bis-Datum
   - `halfDay` (Phase 3)
   - Status (`offen` | `genehmigt` | `abgelehnt`)
   - genehmigtVon (User), genehmigtAm, Notiz

5. **PublicHoliday** (Phase 2)
   - Datum, Name, Region, Jahr
   - via yasumi importiert, manuell editierbar/ergänzbar

6. **BalanceCorrection** (manuelle Korrekturbuchung, Phase 2)
   - User, Datum, Konto (`überstunden` | `urlaub`), Betrag ± , Grund, erstelltVon

7. **MonthClosure** (Monatsabschluss)
   - User, Jahr, Monat, Status (`offen` | `gesperrt`)
   - gesperrtVon, gesperrtAm
   - **eingefrorener Snapshot**: Soll, Ist, Saldo, Urlaub genutzt, Überstunden-Saldo
   - Snapshot schützt abgeschlossene Monate vor rückwirkenden Vertragsänderungen

8. **AuditLog**
   - Zeitstempel, handelnder User, Aktion (`punch_in`, `punch_out`, `block_create`, `block_edit`, `block_delete`, `auto_close`, `absence_request`, `absence_approve`, `absence_reject`, `correction`, `month_lock`, `month_unlock`)
   - betroffener User, Entity-Referenz, alt → neu (JSON), optionaler Grund
   - **protokolliert alle Buchungen und Korrekturen**

---

## 6. Erfassung (so einfach wie möglich)

**Punch In/Out ist der Standardweg:**
- **Einstempeln** → öffnet einen `WorkBlock` (Beginn = jetzt, Ende = null), Quelle `punch`.
- **Ausstempeln** → schließt den offenen Block (Ende = jetzt).
- Mehrmals/Tag möglich → mehrere Blöcke; Zeit dazwischen = automatisch Pause.
- Button in der Kopfzeile (siehe Risiko #1) + Fallback-Button auf der Plugin-Seite. Responsive (mobil bedienbar).

**Korrekturen:**
- Zeiten dürfen **jederzeit nachträglich korrigiert** werden (Beginn/Ende ändern, Block anlegen/löschen) — solange der Monat **nicht gesperrt** ist.
- Gesperrte Monate sind eingefroren; Korrektur erfordert vorheriges Entsperren durch den Admin.
- **Jede** Buchung und Korrektur landet im AuditLog (alt → neu, wer, wann).

**Vergessenes Ausstempeln:**
- Ein **nächtlicher Cron-Command** schließt offene Blöcke (Ende = tägliche Vertrags-Endzeit) und setzt `needsReview = true`.
- Diese Blöcke werden in der Ansicht sichtbar als **„zu prüfen"** markiert (kein separates Benachrichtigungssystem).
- MA/Admin korrigieren sie anschließend manuell.

---

## 7. Rechenlogik (Arbeitszeitkonto)

**Pro Tag:**
- **Soll** = Vertrags-Wochenstunden des Wochentags, **aber 0 an Feiertagen**.
- **Ist** = Σ Netto-Dauer der WorkBlocks dieses Tages.
- Genehmigte, soll-neutrale Abwesenheiten **schreiben den Tag gut** (Urlaub/Krank/Abbau ⇒ Tag zählt als erfüllt).

**Überstundensaldo** =
Anfangssaldo
+ Σ über alle Tage ( Ist + Abwesenheits-Gutschrift − Soll )
+ Σ Korrekturen (Konto Überstunden)
− Σ Überstundenabbau-Stunden.

> Effekt: Ein „Überstundenabbau"-Tag ist soll-neutral (Gutschrift = Soll), aber das Überstundenkonto sinkt um die Tages-Soll-Stunden ⇒ sauberer Freizeitausgleich.

**Urlaubssaldo (Tage)** =
Jahresanspruch + Übertrag − genehmigte Urlaubstage + Korrekturen (Konto Urlaub).
(Halbe Tage: Phase 3.)

**Snapshot:** Beim Sperren eines Monats werden Soll/Ist/Saldo/Urlaub des Monats berechnet und in `MonthClosure` eingefroren. Offene Monate werden stets live mit dem **aktuellen** Vertrag gerechnet.

---

## 8. PDF-Monatsabschluss

- Pro Mitarbeiter und Monat, gerendert über `App\Pdf\MPdfConverter` / `PdfContext` (mPDF).
- **SICODA „Marine & Mint"** Branding (navy `#2C4A73`, mint `#2EC4A9`, mint-700 `#15756B`, Fonts Space Grotesk / Inter).
- **Fonts werden im Plugin getrackt mitgeliefert** (`Resources/public/fonts/` o. ä.) und dem mPDF-fontDir bekanntgemacht — *nicht* aus dem gitignorierten `var/data/fonts/`, damit das PDF deploysicher ist.
- Inhalt: Arbeitsstunden (Tagesliste), Soll/Ist, Saldo, Abwesenheiten, Feiertage.

---

## 9. Feiertage (Phase 2)

- Import via yasumi `Yasumi::create('Germany\\NorthRhineWestphalia', $jahr, 'de_DE')`.
- Import-Command + Button im Admin; Ergebnis in `PublicHoliday` gespeichert.
- Manuelle Pflege/Korrektur möglich.
- Feiertage setzen das Tages-Soll auf 0 (kein Urlaubsverbrauch).

---

## 10. Phasen

### Phase 1 — MVP (Implementierungsziel des ersten Plans)
1. Contract-Entity + Admin-UI (Wochenstunden, Urlaubsanspruch, Beschäftigungszeitraum).
2. Punch In/Out (Kopfzeile + Fallback) → WorkBlocks.
3. Nächtlicher Cron: Auto-Abschluss offener Blöcke + „zu prüfen"-Flag.
4. Manuelle/nachträgliche Zeitkorrektur (offene Monate).
5. Abwesenheits-Workflow (MA beantragt → Admin genehmigt/lehnt ab), Typen Urlaub/Krank/Überstundenabbau.
6. Arbeitszeitkonto (Soll/Ist/Saldo, Überstunden, Urlaubsrest).
7. Monatsabschluss (sperren/entsperren) + Snapshot.
8. PDF-Monatsabschluss (SICODA-Branding).
9. MA-Read-only-Ansicht (eigener Kontostand).
10. AuditLog (alle Buchungen + Korrekturen + Genehmigungen + Sperren).

### Phase 2 — kurzfristig
- yasumi-NRW-Feiertagsimport + manuelle Pflege.
- Manuelle Korrekturbuchungen (BalanceCorrection).
- Abwesenheitskalender (alle MA, Monatsansicht, Admin-only).
- „Wer ist gerade da?" (Live-Anwesenheit aus offenen Punch-Blöcken).
- Resturlaub-Jahresübertrag.

### Phase 3 — nice-to-have
- Halbe Urlaubstage.
- Weitere Abwesenheitstypen (Sonderurlaub, unbezahlt, Homeoffice).
- Dashboard-Widgets (Urlaubsstand, Überstunden).
- iCal-Export.

---

## 11. Test-Strategie

- **Kritisch:** In der `test`-Env lädt Kimai Plugins **nicht** (`Kernel::getBundleClasses()`). Daher:
  - Die gesamte **Saldo-/Konto-Rechenlogik** liegt in framework-freien Klassen (keine DB/Kernel-Abhängigkeit) und wird mit reinem PHPUnit unit-getestet (Tag-Soll, Ist-Summe, Überstunden, Urlaub, Feiertags-Soll-0, Abbau-Logik, Snapshot).
  - Plugin-eigene Tests laufen über das mitgelieferte `Tests/`-Setup des Bundles (eigene phpunit-Konfiguration), nicht über die Core-Suite.
- PHPStan (Plugin-eigenes `phpstan.neon`) + PHP-CS-Fixer (Plugin-eigenes Dist) müssen grün sein.

---

## 12. Technische Risiken / offene Punkte für die Planung

1. **Kopfzeilen-Button** — stabilen Theme-/JS-Hook für die Topbar-Injektion verifizieren; Fallback definieren.
2. **Plugin-Migrationen** — Mechanismus, wie Kimai die Doctrine-Migrationen des Plugins ausführt, in der Planung festnageln (eigener Migrations-Pfad / Install-Command).
3. **Cron-Betrieb** — der nächtliche Auto-Abschluss braucht einen System-Cron, der `bin/console` aufruft; Doku im Plugin-Readme.
4. **Test-Setup** des Bundles (eigener Kernel/phpunit) konkretisieren.

---

## 13. Entscheidungs-Log (aus dem Brainstorming)

- **Strategie:** Eigenständiges Parallel-Plugin, eigene Tabellen + Logik. *Grund:* Unabhängigkeit von Core-Updates.
- **Ist-Erfassung:** Eigene Tageserfassung im Plugin (keine Kimai-Timesheets) — primär **Punch In/Out**.
- **Tages-Granularität:** Mehrere Blöcke (Beginn–Ende), Lücken = Pause.
- **Vertrag:** Ein aktueller Vertrag pro MA (überschreibend); gesperrte Monate via Snapshot eingefroren.
- **Abwesenheiten:** Antrags-Workflow (MA → Admin). Sichtbarkeit: Admin alle, MA nur eigene.
- **Vergessenes Ausstempeln:** Nächtlicher Auto-Abschluss + „zu prüfen"-Flag.
- **ArbZG-Warnungen:** keine.
- **Benachrichtigungen:** keine.
- **Korrekturen:** jederzeit nachträglich (offene Monate), vollständig im AuditLog protokolliert.
