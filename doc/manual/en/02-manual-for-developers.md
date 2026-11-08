# Manual chapters of an application

An application brings its manual along as Markdown files in its own folder. egwmanual finds them, shows them only to
users of the application, and adds the entry **Manual** to the application's menu. The application does not depend on
egwmanual for this, only its Help buttons need it.

## 1. Where the chapters go

```
<app>/doc/manual/<lang>/<chapter>.md
<app>/doc/manual/<lang>/images/<picture>
```

- One folder per language (`en`, `de`, `el` …). A translation has the same file name in the other language's folder.
- Chapter names: lower-case letters, digits and hyphens, starting with a number, for example
  `40-myapp-guide-00-basics`. They are listed in the order of their names.
- A first line `<!-- parent: <chapter> -->` lists the chapter below another chapter of the application (sub-chapter),
  see [Custom chapters](01-manual-custom-chapters.md#3-writing-a-chapter).
- Chapter names are global: the Help buttons link to them by name. Choose a prefix of the application, so that no other
  application uses the same name (if two do, the first application in alphabetical order wins).
- The chapters are found on the next request; there is no registration and no cache to clear.

The folder is part of the application's repository, so the chapters can also be read on GitHub. The renderer follows
GitHub's rules where it matters (section anchors), so links work in both places.

## 2. What a chapter may contain

The same Markdown as custom chapters (see [Custom chapters](01-manual-custom-chapters.md#3-writing-a-chapter)), plus:

- pictures in sub-folders of `images/` (for example `images/icons/`): a picture under `images/icons/` is shown as an
  icon in the running text;
- SVG pictures (shipped pictures are trusted, those of the custom folder are not);
- links to chapters of other applications: shown as plain text to users who do not have that application.

Links into the repository (`../../doc/…`) are shown as plain text in the manual window.

## 3. The Help button of a screen

A Help button opens a chapter, optionally at a section. In a template:

```xml
<et2-button-icon statustext="Open the user guide of this screen" label="Help" id="help" image="question-circle"
  noSubmit="true" onclick="egw.openPopup(egw.link('/index.php', {menuaction: 'egwmanual.EGroupware\\Egwmanual\\Ui.page',
  page: '40-myapp-guide-00-basics', anchor: '2-the-list'}), 920, 850, 'egwmanual'); return false;"/>
```

From PHP, `\EGroupware\Egwmanual\Manual::link($page, $anchor)` gives the URL (for example as `help_url` in the content
of a template). Leave out the language: the manual opens in the user's language, or in a language the chapter exists
in.

## 4. The chapter the menu entry opens

Without further work the entry **Manual** (in the application's menu, and in the user menu while the application is in front) opens the first chapter of the application. To open another one, register
the hook `manual_start` in `setup/setup.inc.php` and return the chapter's name:

```php
$setup_info['myapp']['hooks']['manual_start'] = 'EGroupware\\Myapp\\Hooks::manual_start';
```

```php
public static function manual_start($data = null)
{
	return '40-myapp-guide-00-basics';	// $data['lang']: the language of the manual
}
```

A new hook needs **Admin › Clear cache/register hooks** (or an update of the application).

## 5. Tables computed from the application's setup

A chapter can contain parts computed from the installation, for example the statuses the administrator set up:

```
<!-- live: myapp.statuses -->
Text shown on GitHub, and in the manual where the table cannot be computed.
<!-- /live -->
```

egwmanual then calls `myapp_manual::block_statuses(string $lang, string ...$args)` (class
`myapp/inc/class.myapp_manual.inc.php`). Arguments after the name (`<!-- live: myapp.statuses open -->`) are passed on;
they may contain letters, digits, `_` and `-` only.

Rules for such a method:

- Return Markdown, or `''` to show the written text. An exception also shows the written text (and is logged).
- Everything returned is escaped like the rest of the chapter: return Markdown, never HTML. A picture can be returned
  as `![…](data:image/svg+xml;base64,…)`.
- Use `$lang` for names that have translations. The window runs as egwmanual: load the application's phrases with
  `Api\Translation::add_app('myapp')` before calling `lang()`.
- The method runs with the rights of the reader, and only if the reader has the application. Show only what this
  reader may see.

## 6. Testing

- `egwmanual/tests/ChaptersTest.php::testChaptersOfTheInstallation` renders every shipped chapter of the installation.
- A custom folder for trying out: see the README of egwmanual.
