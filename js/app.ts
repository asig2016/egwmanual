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
			// a chapter opened at a heading (&anchor=)
			const anchor = _et2.widgetContainer.getWidgetById('anchor')?.value;
			if (anchor)
			{
				window.setTimeout(() => document.getElementById(anchor)?.scrollIntoView(), 50);
			}
			// the chapter list as the user left it, the current chapter in sight
			this.treeShow(_et2, _et2.widgetContainer.getArrayMgr('content').getEntry('tree_class') !== 'egwmanual_notree');
			const tree = _et2.widgetContainer.getWidgetById('tree');
			tree?.updateComplete?.then(() => window.setTimeout(() =>
				tree.shadowRoot?.querySelector('sl-tree-item[selected]')?.scrollIntoView({block: 'center'}), 50));
			tree?.updateComplete?.then(() => this.treeColors(tree));
			// not the tree's onclick: that gets the tree's value, which a click on an application node does not change
			tree?.addEventListener('et2-click', (_ev : CustomEvent) => this.treeClick(_ev.detail?.item?.id, tree));
			// Back from the bfcache shows the tree as it was left, with the clicked chapter selected
			window.addEventListener('pageshow', (_ev : PageTransitionEvent) =>
			{
				if (_ev.persisted && tree) tree.value = tree.getArrayMgr('content').getEntry('page');
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
		const url = this.chapterUrl(_widget, {page: _id, lang: content.getEntry('lang')});
		if (url && _id !== content.getEntry('page'))
		{
			this.navigate(_widget, url);
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
			this.navigate(_widget, url);
		}
		return false;
	}

	/**
	 * Open another chapter: nothing of the window is ever saved, so no "Leave site?" prompt (the language selector
	 * and the tree count as changed widgets for etemplate2's close prompt)
	 */
	protected navigate(_widget : any, _url : string)
	{
		_widget.getInstanceManager?.()?.skip_close_prompt?.(true);
		window.location.href = _url;
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
			${apps.map(_app => `sl-tree-item.egwmanual_app_${_app} { --application-color: var(--${_app}-color, var(--default-color, var(--sl-color-neutral-600))); }`).join('\n')}
		`);
		root.adoptedStyleSheets = [...root.adoptedStyleSheets, sheet];
	}
}

app.classes.egwmanual = EgwmanualApp;
