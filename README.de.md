# Paymos für CS-Cart

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles Paymos-Add-on für CS-Cart, Multi-Vendor und Ultimate. Es registriert den
Zahlungsprozessor Paymos: Die Kundschaft gibt die Bestellung auf, wird zur gehosteten
Paymos-Zahlungsseite weitergeleitet und zahlt mit einem Stablecoin aus der eigenen Wallet. Der
Bestellstatus wechselt über einen signierten Webhook, sobald die Transaktion On-Chain bestätigt
wurde.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- CS-Cart, Multi-Vendor oder Ultimate der 4.x-Reihe. `addon.xml` deklariert Add-on-Schema `3.0` und
  installiert sich automatisch in den Editionen Multi-Vendor und Ultimate
- PHP 7.4 oder neuer mit den Erweiterungen `curl`, `hash`, `json` und `openssl` — die
  Mindestanforderung des enthaltenen Paymos PHP SDK
- Eine Storefront über HTTPS; der Verbindungsablauf lehnt alles andere ab
- Ein konfiguriertes `crypt_key` in CS-Cart, da die gespeicherten Zugangsdaten damit verschlüsselt werden
- Ein Paymos-Konto, in dessen Dashboard das Projekt für diese Storefront ausgewählt ist

## Installation

Laden Sie `paymos-cscart-<version>.zip` unter
[Releases](https://github.com/Paymos-labs/cscart/releases/latest) oder über das Panel **CMS
integration** im Paymos Dashboard herunter und laden Sie das Paket unter **Add-ons → Manage add-ons
→ + → Local** hoch.

Das Archiv entpackt drei Verzeichnisbäume, und alle drei sind entscheidend:

- `app/addons/paymos` — das eigentliche Add-on inklusive gebündeltem Paymos PHP SDK
- `design/backend/…` — die Vorlage für die Prozessor-Einstellungen
- `var/langs/…` — die Sprachkataloge. CS-Cart löst `.po`-Dateien eines Add-ons immer vom
  **Shop-Wurzelverzeichnis** aus auf, niemals aus dem Add-on-Ordner heraus; geht `var/` beim
  Upload verloren, zeigt das Backend jedes Label als rohen Sprachschlüssel an

Bei der Installation werden `paymos_config`, `paymos_invoices` und `paymos_events` angelegt und
`Paymos` in die Tabelle der Zahlungsprozessoren eingetragen. Das Archiv ist für alle Händler
identisch und enthält weder API-Schlüssel noch API Secret, Projekt-ID, Webhook-Geheimnis,
OAuth-Token oder Gerätecode.

## Verbinden

1. Öffnen Sie vorab im Paymos Dashboard das richtige Projekt. Das Add-on übernimmt dieses Projekt
   und fragt nie nach einer Auswahl.
2. Gehen Sie im Adminbereich zu **Administration → Payment methods**, legen Sie eine Zahlungsart an
   oder bearbeiten Sie sie und wählen Sie **Paymos** als Prozessor.
3. Klicken Sie in den Prozessor-Einstellungen auf **Connect Paymos** und bestätigen Sie
   Storefront-URL und Projekt im geöffneten Tab. Blockiert der Browser diesen Tab, erscheinen
   Bestätigungslink und Code direkt neben der Schaltfläche.
4. Speichern Sie die Zahlungsart.

Sandbox und Live werden beide durch diese eine Bestätigung eingerichtet. Ihr einziger aktiver
Payment-Schlüssel wird weiterverwendet — oder angelegt, falls keiner existiert — und ein
Invoice-Webhook wird unter folgender Adresse registriert:

```text
https://your-store.example/index.php?dispatch=payment_notification.notify&payment=paymos&mode=webhook
```

`mode=webhook` ist wesentlicher Bestandteil der Adresse und keine Dekoration: Das Add-on erkennt
daran einen Paymos-Callback im Unterschied zu einer regulären Payment-Notification, da CS-Carts
eigenes `$mode` auf dieser Route `notify` lautet. Ein bestehender Webhook wird nur wiederverwendet,
wenn Callback-URL, Kategorie und Projekt exakt übereinstimmen.

Die Zugangsdaten werden als AES-256-GCM-Umschlag in `paymos_config` geschrieben, verschlüsselt mit
dem `crypt_key` des Shops, und niemals zurück in die Admin-Seite ausgegeben.

## Bestellstatus

Jeder Zustand wird auf einen von Ihnen in den Prozessor-Einstellungen gewählten CS-Cart-Statuscode
abgebildet. Standardmäßig gelten die integrierten Codes:

| Prozessor-Einstellung | Standard | Wird angewendet bei |
|---|---|---|
| Order status while awaiting payment | `O` — Open | Rechnung erstellt und Kundschaft zur gehosteten Zahlungsseite geleitet |
| Order status while the payment is confirming on-chain | `O` — Open | `invoice.confirming` |
| Order status when paid | `P` — Processed | `invoice.paid`, `invoice.paid_over` |
| Order status when the payment is underpaid | `F` — Failed | `invoice.underpaid` |
| Order status when the invoice is cancelled or expired | `D` — Declined | `invoice.expired`, `invoice.cancelled` |

Jeder im Shop definierte Code wird akzeptiert, einschließlich selbst angelegter Status. Als
Transaction ID wird der On-Chain-Hash der bestätigten Transaktion auf der Bestellung hinterlegt —
oder die Paymos-Rechnungs-ID, wenn die Nutzlast keine Transaktionen enthält, was in der Sandbox
immer der Fall ist.

Zwei Dinge passieren niemals. Eine Bestellung, die bereits Ihren konfigurierten Bezahlt-Status oder
den eingebauten Status `C` (Complete) trägt — auf den Händler bezahlte Bestellungen häufig
weiterstellen —, wird durch ein verspätetes `confirming`, `underpaid`, `expired` oder `cancelled`
nicht zurückgesetzt: Das Ereignis wird übersprungen und bei aktivem Debug-Logging protokolliert.
Und eine Bestellung, deren Gesamtsumme oder Währung nicht mehr zur Rechnung passt, wird überhaupt
nicht als bezahlt markiert.

## Multi-Vendor und Ultimate

Beide Editionen erhalten dasselbe Add-on mit identischem Verhalten: ein Zahlungsprozessor auf
Shop-Ebene, dessen Verbindung zur Storefront gehört und dessen Einstellungen zur Zahlungsart
gehören. Ein Shop kann mehrere Paymos-Zahlungsarten parallel mit unterschiedlichen Modi oder
Statuscodes betreiben.

Jede Zahlung wird auf das Händlerguthaben des verbundenen Paymos-Projekts gutgeschrieben. Es gibt
weder Split-Zahlungen noch Unterhändler-Konten; die Aufteilung von Bestellerlösen auf Anbieter
bleibt daher vollständig in CS-Carts eigener Anbieter-Buchhaltung.

## Vor dem Livegang testen

1. Stellen Sie **Mode** bei der Zahlungsart auf Sandbox und geben Sie in der Storefront eine Bestellung auf.
2. Öffnen Sie diese Rechnung im Paymos Dashboard (im Sandbox-Modus) und verwenden Sie **Pay Full**,
   **Pay 50%**, **Pay 150%** oder **Cancel**. Sie senden dieselben Ereignisse wie eine echte
   Zahlung; eine Blockchain wird nicht berührt.
3. Prüfen Sie den Bestellstatus und den Zahlungsinformationsblock an der Bestellung.
4. Stellen Sie **Mode** auf Live um. Beide Zugangsdatensätze kamen aus der einen Bestätigung —
   weder eine erneute Verbindung noch eine zweite Einrichtung sind erforderlich.

## Webhooks

Zustellungen sind `POST`-Requests an den oben genannten Dispatch. Der Header `X-Webhook-Signature`
bildet die Vertrauensgrenze — Hex-HMAC-SHA256 über `{timestamp}.{body}` — und eine ungültige
Signatur oder veraltete Zeitmarke antwortet mit `401`, ohne überhaupt eine Bestellung zu laden. Eine
wiederholte `event_id` antwortet mit `200` und tut nichts. Bevor ein Endereignis angewendet wird,
liest das Add-on die Rechnung erneut über die Merchant API aus und vergleicht sie mit dem Snapshot
in `paymos_invoices`: Umgebung, Projekt, externe Bestell-ID und Rechnungs-ID müssen sämtlich
übereinstimmen.

Für Rechnungsstatus-Updates gibt es keinen lokalen Abgleich-Cronjob; die Wiederholungszustellung
der Webhooks bildet den Wiederherstellungsweg. Ein Zyklus umfasst 11 Versuche über rund 16 Stunden
und fängt kurzzeitige Ausfälle der Storefront ab. Ein Rechnungsereignis, das dieses Zeitfenster
überdauert, lässt sich im Paymos Dashboard manuell erneut abspielen.

## Erstattungen

Das Add-on unterstützt native Erstattungen über die Paymos Merchant API (`/v1/refunds`) in zwei Modi:

- `full` — erstattet den vollen bezahlten Rechnungsbetrag;
- `overpayment` — erstattet ausschließlich den Überzahlungsbetrag einer `invoice.paid_over`-Bestellung.

Die Mittel fließen ausschließlich an die verifizierte Wallet-Adresse `first_sender` zurück, die
Paymos bei Bezahlung der Rechnung erfasst hat. Eine Empfängeradresse wird nicht manuell eingegeben,
und beliebige Teilbeträge werden nicht unterstützt.

Zur Verwaltung von Erstattungen im CS-Cart-Adminbereich öffnen Sie
`dispatch=paymos.refunds&order_id=<order_id>` (**Paymos refunds**, erfordert Bearbeitungsrechte für
Bestellungen). Die Ansicht zeigt erstattungsfähige Beträge sowie die verifizierte
`first_sender`-Adresse, bietet **Refund in full** (`full`) und **Refund overpayment**
(`overpayment`) (`paymos.refund`) und erlaubt das Prüfen laufender Vorgänge
(`paymos.refund_reconcile`). Ausstehende Erstattungen werden zudem über signierte Webhooks und das
CLI-Cron-Skript `php app/addons/paymos/cron/refunds.php` abgeglichen; die Bestellung wird erst
aktualisiert, nachdem Paymos `completed` bestätigt hat. Im Sandbox-Modus lassen sich Endzustände
(`completed`, `failed`, `cancelled`) simulieren.

## Fehlerbehebung

**Admin-Beschriftungen erscheinen als rohe Schlüssel** wie `paymos.mode`. Der Baum `var/langs` hat
den Upload nicht überstanden. Laden Sie das Release-Archiv vollständig neu hoch; Sprachkataloge
liegen im Shop-Wurzelverzeichnis, nicht im Add-on-Ordner.

**Bestellungen verlassen niemals den Status „Warten auf Zahlung“.** Prüfen Sie, ob der registrierte
Callback noch `mode=webhook` enthält. Ohne diesen Parameter lehnt das Add-on jede Zustellung als
ungültigen Callback-Modus ab, der Server wiederholt weiter und nichts wird abgeschlossen. Erneutes
Verbinden registriert wieder die korrekte Adresse.

**Connect startet nicht.** Die Storefront-URL wird bestätigt und für den Webhook registriert — und
sie muss mit `https://` beginnen. Korrigieren Sie die Storefront-URL und verbinden Sie erneut.

**Nach einer Konfigurationsänderung bricht alles weg.** Der Zugangsdaten-Umschlag ist mit
`crypt_key` verschlüsselt. Wird dieser geändert oder die Datenbank in einen Shop mit anderem
`crypt_key` eingespielt, wird der Umschlag unlesbar — klicken Sie erneut auf **Connect Paymos**, um
einen frischen ausstellen zu lassen.

## Links

- Dokumentation: [paymos.io/de/docs/cms-cscart](https://paymos.io/de/docs/cms-cscart)
- Quellcode und Releases: [Paymos-labs/cscart](https://github.com/Paymos-labs/cscart)
- Support: [support@paymos.io](mailto:support@paymos.io)
