/**
 * EGroupware - egwmanual: the manual window (egwmanual.manual, src/Ui.php)
 *
 * @link https://www.egroupware.org
 * @package egwmanual
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

/*egw:uses
	/api/js/jsapi/egw_app.js
 */

import {EgwApp} from '../../api/js/jsapi/egw_app';

/**
 * The chapters read in the manual's dialog (a phone: EgwFramework.openPopup() opens popups as dialogs of the main
 * window there) for Back and Forward, the browser's history being the one of the main window
 */
const dialogHistory = {urls: [] as string[], index: -1, pending: false};

/**
 * The manuals already on their way to another chapter: one tap on the chapter list reports the click twice, and
 * a second dialog of the same template would load into the first one's form and stay blank on top of it
 */
const leaving = new WeakSet<object>();

export class EgwmanualApp extends EgwApp
{
	constructor()
	{
		super('egwmanual');
	}

	destroy(_app)
	{
		super.destroy(_app);
	}

	et2_ready(_et2, _name)
	{
		super.et2_ready(_et2, _name);

		if (_name === 'egwmanual.manual')
		{
			const content = _et2.widgetContainer.getArrayMgr('content');
			const dialog = this.dialogOf(_et2);
			if (dialog)
			{
				// opened from a menu or a Help button, not by a link of the manual itself: a history of its own
				if (!dialogHistory.pending)
				{
					dialogHistory.urls = [this.chapterUrl(_et2.widgetContainer, {page: content.getEntry('page') || '', lang: content.getEntry('lang') || ''})];
					dialogHistory.index = 0;
				}
				dialogHistory.pending = false;
			}
			// opened from the avatar menu: the manual of the application in front of the opener (in a dialog: of
			// the window it is shown in)
			if (content.getEntry('ask_opener'))
			{
				let active = '';
				try
				{
					active = String((dialog ? window : window.opener)?.framework?.activeApp?.appName ?? '');
				}
				catch (e) {}	// an opener of another origin
				const base = content.getEntry('page_url_base');
				if (/^[a-z0-9_]+$/.test(active) && base)
				{
					this.navigate(_et2, base + '&app=' + encodeURIComponent(active), 'replace');
					return;
				}
			}
			// a chapter opened at a heading (&anchor=)
			const anchor = _et2.widgetContainer.getWidgetById('anchor')?.value;
			if (anchor)
			{
				window.setTimeout(() => document.getElementById(anchor)?.scrollIntoView(), 50);
			}
			// the language was switched: the same place of the chapter as before
			else
			{
				this.positionRestore(_et2);
			}
			// the chapter list as the user left it, the current chapter in sight
			this.treeShow(_et2, _et2.widgetContainer.getArrayMgr('content').getEntry('tree_class') !== 'egwmanual_notree');
			const tree = _et2.widgetContainer.getWidgetById('tree');
			tree?.updateComplete?.then(() => window.setTimeout(() =>
				tree.shadowRoot?.querySelector('sl-tree-item[selected]')?.scrollIntoView({block: 'center'}), 50));
			tree?.updateComplete?.then(() => this.treeColors(tree));
			// not the tree's onclick: that gets the tree's value, which a click on an application node does not change
			tree?.addEventListener('et2-click', (_ev : CustomEvent) => this.treeClick(_ev.detail?.item?.id, tree));
			// Back from the bfcache shows the tree as it was left, with the clicked chapter selected, and the page
			// may be left again
			if (!dialog) window.addEventListener('pageshow', (_ev : PageTransitionEvent) =>
			{
				if (!_ev.persisted) return;
				leaving.delete(_et2);
				if (tree) tree.value = tree.getArrayMgr('content').getEntry('page');
			});
		}
	}

	/**
	 * The URL of a chapter: the base is built on the server, a namespaced menuaction built here gets its backslashes
	 * encoded twice
	 */
	protected chapterUrl(_widget : any, _params : Record<string, string>) : string
	{
		const base = _widget.getArrayMgr('content').getEntry('page_url_base');
		if (!base) return '';
		return base + Object.entries(_params).map(([_k, _v]) => '&' + _k + '=' + encodeURIComponent(_v)).join('');
	}

	/**
	 * A chapter of the chapter list opens as a page of its own, so Back and Forward keep working; a node of an
	 * application only opens or closes (the tree does that itself)
	 */
	treeClick(_id : string, _widget : any)
	{
		if (typeof _id !== 'string' || _id === '' || _id.startsWith('app:'))
		{
			return false;
		}
		const content = _widget.getArrayMgr('content');
		// a chapter missing in the language shown opens in the one the list shows it in (Chapters::listed())
		const url = this.chapterUrl(_widget, {page: _id, lang: content.getEntry('tree_lang')?.[_id] || content.getEntry('lang')});
		if (url && _id !== content.getEntry('page'))
		{
			this.navigate(_widget.getInstanceManager?.(), url);
		}
		return false;
	}

	/**
	 * Another language: the same chapter if it exists there, else the first one of the same application (Ui::page)
	 */
	langChange(_ev : Event, _widget : any)
	{
		const content = _widget.getArrayMgr('content');
		const lang = String(_widget.value || '');
		if (!/^[a-z]{2}(-[a-z]{2})?$/.test(lang) || lang === content.getEntry('lang'))
		{
			return false;
		}
		const url = this.chapterUrl(_widget, {page: content.getEntry('page') || '', app: content.getEntry('app') || '', lang: lang});
		if (url)
		{
			this.positionRemember(_widget.getInstanceManager?.(), content.getEntry('page') || '');
			this.navigate(_widget.getInstanceManager?.(), url);
		}
		return false;
	}

	/** sessionStorage key of the place the reader was at when switching the language */
	static readonly POSITION_KEY = 'egwmanual_position';

	/**
	 * The scrolled pane of the chapter and where its headings start in it
	 */
	protected positionParts(_et2 : any) : { body : HTMLElement, starts : number[] } | null
	{
		const body : HTMLElement = _et2?.DOMContainer?.querySelector('.egwmanual_body');
		if (!body) return null;
		const top = body.getBoundingClientRect().top - body.scrollTop;
		return {
			body: body,
			starts: Array.from(body.querySelectorAll('h1,h2,h3,h4,h5,h6')).map(_h => _h.getBoundingClientRect().top - top)
		};
	}

	/**
	 * Remember the place in the chapter for its other language: the headings have other texts (and anchors) there, but
	 * are the same ones in the same order, so the place is "that far into the section below the n-th heading"
	 */
	protected positionRemember(_et2 : any, _page : string)
	{
		const parts = this.positionParts(_et2);
		if (!parts) return;
		try
		{
			window.sessionStorage.setItem(EgwmanualApp.POSITION_KEY, JSON.stringify({
				page: _page,
				...EgwmanualApp.positionOf(parts.body.scrollTop, parts.starts, parts.body.scrollHeight, parts.body.clientHeight)
			}));
		}
		catch (e) {}	// no storage: the chapter opens at its top, as before
	}

	/**
	 * A scroll position as section and fraction of it; section -1 is the text above the first heading
	 */
	static positionOf(_top : number, _starts : number[], _height : number, _visible : number)
	{
		let section = -1;
		while (section + 1 < _starts.length && _starts[section + 1] <= _top + 1) section++;
		const start = section < 0 ? 0 : _starts[section];
		const end = section + 1 < _starts.length ? _starts[section + 1] : _height;
		return {
			headings: _starts.length,
			section: section,
			fraction: end > start ? Math.min(1, Math.max(0, (_top - start) / (end - start))) : 0,
			// for a translation with other headings: how far into the whole chapter
			overall: _height > _visible ? Math.min(1, Math.max(0, _top / (_height - _visible))) : 0
		};
	}

	/**
	 * The scroll position of a remembered place in this chapter
	 */
	static positionTop(_pos : any, _starts : number[], _height : number, _visible : number) : number
	{
		if (_pos.headings !== _starts.length || !(_pos.section >= -1 && _pos.section < _starts.length))
		{
			return Math.round((Number(_pos.overall) || 0) * Math.max(0, _height - _visible));
		}
		const start = _pos.section < 0 ? 0 : _starts[_pos.section];
		const end = _pos.section + 1 < _starts.length ? _starts[_pos.section + 1] : _height;
		return Math.round(start + (Number(_pos.fraction) || 0) * Math.max(0, end - start));
	}

	/**
	 * After a switch of the language: scroll to the remembered place, if this is still that chapter (a chapter missing
	 * in the language is replaced by another one, which opens at its top). The pictures above the place load later and
	 * move it, so it is set again as they arrive, until the reader moves on.
	 */
	protected positionRestore(_et2 : any)
	{
		let pos : any = null;
		try
		{
			pos = JSON.parse(window.sessionStorage.getItem(EgwmanualApp.POSITION_KEY) || 'null');
			window.sessionStorage.removeItem(EgwmanualApp.POSITION_KEY);
		}
		catch (e) {}
		if (!pos || typeof pos !== 'object' || pos.page !== _et2.widgetContainer.getArrayMgr('content').getEntry('page'))
		{
			return;
		}
		const apply = () =>
		{
			const parts = this.positionParts(_et2);
			if (parts)
			{
				parts.body.scrollTop = EgwmanualApp.positionTop(pos, parts.starts, parts.body.scrollHeight, parts.body.clientHeight);
			}
		};
		const body : HTMLElement = _et2.DOMContainer?.querySelector('.egwmanual_body');
		if (!body) return;
		const done = new AbortController();
		const images = Array.from(body.querySelectorAll('img')).filter(_img => !_img.complete);
		let pending = images.length;
		images.forEach(_img => ['load', 'error'].forEach(_type => _img.addEventListener(_type, () =>
		{
			apply();
			if (--pending <= 0) done.abort();
		}, {once: true, signal: done.signal})));
		['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(_type =>
			body.addEventListener(_type, () => done.abort(), {once: true, passive: true, signal: done.signal}));
		window.setTimeout(apply, 50);
	}

	/**
	 * Open another chapter: nothing of the window is ever saved, so no "Leave site?" prompt (the language selector
	 * and the tree count as changed widgets for etemplate2's close prompt)
	 *
	 * In a dialog of the main window (a phone) the chapter opens as a new dialog, which replaces this one: loading
	 * the URL would replace the whole EGroupware window by the bare chapter, without the dialog's bar to close it.
	 *
	 * @param _history push: a new chapter read, replace: instead of the current one, none: Back or Forward
	 */
	protected navigate(_et2 : any, _url : string, _history : 'push' | 'replace' | 'none' = 'push')
	{
		if (!_et2 || leaving.has(_et2)) return;
		leaving.add(_et2);
		_et2.skip_close_prompt?.(true);
		const dialog : any = this.dialogOf(_et2);
		if (!dialog)
		{
			if (_history === 'replace')
			{
				window.location.replace(_url);
			}
			else
			{
				window.location.href = _url;
			}
			return;
		}
		if (_history === 'push')
		{
			dialogHistory.urls.splice(dialogHistory.index + 1);
			dialogHistory.urls.push(_url);
			dialogHistory.index = dialogHistory.urls.length - 1;
		}
		else if (_history === 'replace')
		{
			dialogHistory.index = Math.max(0, dialogHistory.index);
			dialogHistory.urls.splice(dialogHistory.index, dialogHistory.urls.length, _url);
		}
		dialogHistory.pending = true;
		// the new dialog's form gets the same id (Etemplate: the template's name) and the template is loaded into
		// the first form of that id: the old form gives up its id, else the chapter lands in the dialog closed below
		const form : HTMLElement = _et2.DOMContainer;
		const id = form.id;
		form.id = id + '-closing';
		// the old dialog goes once the new one is there, so the window below does not flash up meanwhile
		Promise.resolve(this.egw.openPopup(_url, 920, 850, 'egwmanual', 'egwmanual', true, 'yes'))
			.then(() => dialog.close?.(), () =>
			{
				form.id = id;
				dialogHistory.pending = false;
				leaving.delete(_et2);
			});
	}

	/**
	 * The dialog the manual is shown in, null in a window of its own
	 */
	protected dialogOf(_et2 : any) : HTMLElement | null
	{
		return _et2?.DOMContainer?.closest?.('et2-dialog') ?? null;
	}

	/**
	 * Back and Forward: the browser's history in a window of its own, the dialog's own one in a dialog
	 */
	back(_ev : Event, _widget : any)
	{
		return this.historyGo(_widget, -1);
	}

	forward(_ev : Event, _widget : any)
	{
		return this.historyGo(_widget, 1);
	}

	protected historyGo(_widget : any, _step : number)
	{
		const et2 = _widget?.getInstanceManager?.();
		if (!this.dialogOf(et2))
		{
			window.history.go(_step);
			return false;
		}
		const index = dialogHistory.index + _step;
		if (index >= 0 && index < dialogHistory.urls.length)
		{
			dialogHistory.index = index;
			this.navigate(et2, dialogHistory.urls[index], 'none');
		}
		return false;
	}

	/**
	 * Hide or show the chapter list, remembered for the next chapters
	 */
	treeToggle(_ev : Event, _widget : any)
	{
		const et2 = _widget.getInstanceManager();
		const show = !!et2?.DOMContainer?.classList.contains('egwmanual_notree');
		this.treeShow(et2, show);
		this.egw.set_preference('egwmanual', 'tree', show ? '' : 'hidden');
		return false;
	}

	protected treeShow(_et2 : any, _show : boolean)
	{
		_et2?.DOMContainer?.classList.toggle('egwmanual_notree', !_show);
	}

	/**
	 * The icons of the applications in the chapter list as the application list of the framework draws them, in the
	 * application's colour (--<app>-color): a "kdots-navbar" icon (Chapters::tree() adds egwmanual_kdots) in it, any
	 * other white on a circle of it. The tree items live in the tree's shadow root, app.css does not reach them.
	 */
	protected treeColors(_tree : any)
	{
		const root : ShadowRoot = _tree?.shadowRoot;
		if (!root || typeof CSSStyleSheet === 'undefined' || !('adoptedStyleSheets' in root)) return;
		const apps = (_tree.getArrayMgr?.('sel_options')?.getEntry?.('tree')?.children ?? [])
			.map((_node : any) => String(_node?.value ?? '').replace(/^app:/, '').replace(/^general$/, 'egwmanual'))
			.filter((_app : string) => /^[a-z0-9_]+$/.test(_app));
		const sheet = new CSSStyleSheet();
		sheet.replaceSync(`
			sl-tree-item.egwmanual_app > et2-image {
				box-sizing: content-box; width: 1em; height: 1em; flex: 0 0 auto;
				padding: .25em; margin-right: .4em; border-radius: 50%;
				background-color: var(--application-color, var(--default-color, var(--sl-color-neutral-600)));
			}
			sl-tree-item.egwmanual_app > et2-image svg, sl-tree-item.egwmanual_app > et2-image img {
				display: block; filter: brightness(0) invert(1);
			}
			sl-tree-item.egwmanual_app.egwmanual_kdots > et2-image {
				width: 1.5em; height: 1.5em; padding: 0; background: none;
				color: var(--application-color, var(--default-color, var(--sl-color-neutral-600)));
			}
			sl-tree-item.egwmanual_app.egwmanual_kdots > et2-image svg, sl-tree-item.egwmanual_app.egwmanual_kdots > et2-image img {
				width: 1.5em !important; height: 1.5em !important; filter: none;
			}
			sl-tree-item.egwmanual_local { font-style: italic; }
			sl-tree-item.egwmanual_foreign::part(label) { color: var(--sl-color-neutral-600); }
			${apps.map(_app => `sl-tree-item.egwmanual_app_${_app} { --application-color: var(--${_app}-color, var(--default-color, var(--sl-color-neutral-600))); }`).join('\n')}
		`);
		root.adoptedStyleSheets = [...root.adoptedStyleSheets, sheet];
	}
}

app.classes.egwmanual = EgwmanualApp;
