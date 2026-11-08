# Die Kapitel einer Anwendung

Eine Anwendung bringt ihr Handbuch als Markdown-Dateien in ihrem eigenen Ordner mit. egwmanual findet sie, zeigt sie
nur den Benutzern der Anwendung und fügt dem Menü der Anwendung den Eintrag **Handbuch** hinzu. Die Anwendung hängt
dafür nicht von egwmanual ab, nur ihre Hilfe-Knöpfe brauchen es.

## 1. Wohin die Kapitel gehören

```
<app>/doc/manual/<lang>/<kapitel>.md
<app>/doc/manual/<lang>/images/<bild>
```

- Ein Ordner je Sprache (`de`, `en`, `el` …). Eine Übersetzung hat im Ordner der anderen Sprache denselben Dateinamen.
- Namen der Kapitel: Kleinbuchstaben, Ziffern und Bindestriche, mit einer Zahl am Anfang, zum Beispiel
  `40-myapp-anleitung-00-grundlagen`. Sie stehen in der Reihenfolge ihrer Namen.
- Die Namen gelten für alle Anwendungen gemeinsam, denn die Hilfe-Knöpfe öffnen ein Kapitel über seinen Namen. Wählen
  Sie ein Präfix der Anwendung, damit keine andere Anwendung denselben Namen verwendet (verwenden ihn zwei, gilt das
  Kapitel der in alphabetischer Reihenfolge ersten).
- Die Kapitel werden ab der nächsten Anfrage gefunden; es gibt keine Registrierung und keinen Cache zu leeren.

Der Ordner gehört zum Repository der Anwendung, so lassen sich die Kapitel auch auf GitHub lesen. Die Anker der
Abschnitte entstehen wie auf GitHub, Links funktionieren daher an beiden Stellen.

## 2. Was ein Kapitel enthalten darf

Dasselbe wie eigene Kapitel (siehe [Eigene Kapitel](01-manual-custom-chapters.md#3-ein-kapitel-schreiben)) und
zusätzlich:

- Bilder in Unterordnern von `images/` (zum Beispiel `images/icons/`): ein Bild unter `images/icons/` erscheint als
  Symbol im laufenden Text;
- SVG-Bilder (die Bilder der Anwendung gelten als vertrauenswürdig, die des Ordners der eigenen Kapitel nicht);
- Links auf Kapitel anderer Anwendungen: Wer die andere Anwendung nicht hat, sieht einfachen Text.

Links ins Repository (`../../doc/…`) erscheinen im Fenster des Handbuchs als einfacher Text.

## 3. Der Hilfe-Knopf einer Maske

Ein Hilfe-Knopf öffnet ein Kapitel, wahlweise bei einem Abschnitt. In einem Template:

```xml
<et2-button-icon statustext="Open the user guide of this screen" label="Help" id="help" image="question-circle"
  noSubmit="true" onclick="egw.openPopup(egw.link('/index.php', {menuaction: 'egwmanual.EGroupware\\Egwmanual\\Ui.page',
  page: '40-myapp-anleitung-00-grundlagen', anchor: '2-die-liste'}), 920, 850, 'egwmanual'); return false;"/>
```

Aus PHP liefert `\EGroupware\Egwmanual\Manual::link($page, $anchor)` die Adresse (zum Beispiel als `help_url` im Inhalt
eines Templates). Geben Sie keine Sprache an: Das Handbuch öffnet sich dann in der Sprache des Benutzers oder in einer
Sprache, in der das Kapitel vorhanden ist.

## 4. Das Kapitel, das der Menüeintrag öffnet

Ohne weiteres öffnet der Eintrag **Handbuch** (im Menü der Anwendung, und im Benutzermenü, solange die Anwendung vorne ist)
das erste Kapitel der Anwendung. Für ein anderes registrieren Sie den
Hook `manual_start` in `setup/setup.inc.php` und geben den Namen des Kapitels zurück:

```php
$setup_info['myapp']['hooks']['manual_start'] = 'EGroupware\\Myapp\\Hooks::manual_start';
```

```php
public static function manual_start($data = null)
{
	return '40-myapp-anleitung-00-grundlagen';	// $data['lang']: die Sprache des Handbuchs
}
```

Ein neuer Hook braucht **Admin › Cache löschen/Hooks registrieren** (oder ein Update der Anwendung).

## 5. Tabellen aus der Einrichtung der Anwendung

Ein Kapitel kann Teile enthalten, die aus der Installation berechnet werden, zum Beispiel die Status, die der
Administrator eingerichtet hat:

```
<!-- live: myapp.statuses -->
Text, der auf GitHub erscheint, und im Handbuch, wenn die Tabelle nicht berechnet werden kann.
<!-- /live -->
```

egwmanual ruft dann `myapp_manual::block_statuses(string $lang, string ...$args)` auf (Klasse in
`myapp/inc/class.myapp_manual.inc.php`). Wörter nach dem Namen (`<!-- live: myapp.statuses open -->`) werden als
Argumente übergeben; sie dürfen nur Buchstaben, Ziffern, `_` und `-` enthalten.

Regeln für eine solche Methode:

- Sie gibt Markdown zurück, oder `''`, damit der geschriebene Text erscheint. Auch eine Exception zeigt den geschriebenen
  Text (und wird protokolliert).
- Alles, was sie zurückgibt, wird wie das übrige Kapitel maskiert: Geben Sie Markdown zurück, nie HTML. Ein Bild lässt
  sich als `![…](data:image/svg+xml;base64,…)` zurückgeben.
- Verwenden Sie `$lang` für Namen, die Übersetzungen haben. Das Fenster läuft als egwmanual: Laden Sie die Texte der
  Anwendung mit `Api\Translation::add_app('myapp')`, bevor Sie `lang()` aufrufen.
- Die Methode läuft mit den Rechten des Lesers und nur, wenn der Leser die Anwendung hat. Zeigen Sie nur, was dieser
  Leser sehen darf.

## 6. Testen

- `egwmanual/tests/ChaptersTest.php::testChaptersOfTheInstallation` stellt jedes Kapitel der Installation dar.
- Einen Ordner mit eigenen Kapiteln zum Ausprobieren: siehe das README von egwmanual.
