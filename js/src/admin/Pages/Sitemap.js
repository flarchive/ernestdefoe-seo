import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import saveSettings from 'flarum/admin/utils/saveSettings';

/**
 * Sitemap mode chooser.
 *
 * Three runtime states the admin can be in:
 *   - fof installed         → fof/sitemap owns /sitemap.xml; this page is read-only.
 *                             Our extend.php's class_exists gate skips our route
 *                             so fof doesn't conflict.
 *   - fof not installed,
 *     seo_sitemap_mode = '' or 'bundled'   → our bundled SitemapController serves.
 *   - fof not installed,
 *     seo_sitemap_mode = 'off'             → we 404 our own route so the admin can
 *                             handle /sitemap.xml externally (CDN, manual file).
 *
 * Default `seo_sitemap_mode` is 'bundled' — admins who install this extension and
 * don't touch the setting get a working sitemap immediately. Switching to 'off'
 * is a deliberate opt-out, not a state we land in by accident.
 */
export default class Sitemap extends Page {
  oninit(vnode) {
    super.oninit(vnode);

    this.saving = false;
    this.expanded = false;

    // Current saved value — '' (legacy/unset), 'bundled', or 'off'.
    const stored = app.data.settings.seo_sitemap_mode;
    this.mode = stored === 'off' ? 'off' : 'bundled';
  }

  // Is fof/sitemap installed (composer-present) AND enabled?
  fofActive() {
    const enabled = app.data.settings.extensions_enabled || '[]';
    try {
      return JSON.parse(enabled).indexOf('fof-sitemap') !== -1;
    } catch (e) {
      return false;
    }
  }

  // What's actually serving /sitemap.xml right now?
  activeProvider() {
    if (this.fofActive()) return 'fof';
    if (this.mode === 'off') return 'none';
    return 'bundled';
  }

  view() {
    const provider = this.activeProvider();
    const fofInstalled = this.fofActive();

    return (
      <div>
        <h2>Sitemap</h2>
        <p>
          A sitemap is an XML file that lists every public page on your forum so search
          engines can crawl and index them. Without one, new discussions take longer to
          appear in search results.
        </p>

        {this.statusBanner(provider)}

        {!fofInstalled && this.modeChooser()}

        <p>
          <a
            href="javascript:void(0)"
            onclick={() => {
              this.expanded = !this.expanded;
              m.redraw();
            }}
          >
            {this.expanded ? 'Hide details' : 'Read more'} <i className={`fas fa-chevron-${this.expanded ? 'up' : 'down'}`} />
          </a>
        </p>

        {this.expanded && this.comparison(fofInstalled)}
      </div>
    );
  }

  // Status card at the top — green/yellow/red depending on what's serving.
  statusBanner(provider) {
    const sitemapUrl = app.forum.attribute('baseUrl') + '/sitemap.xml';

    if (provider === 'fof') {
      return (
        <div className="row-not-passed-error" style="background:#e8f5e9;color:#1b5e20;border-color:#a5d6a7;">
          <i className="fas fa-check-circle" /> <b>FoF Sitemap is active.</b>{' '}
          The friendsofflarum/sitemap extension is installed and serving{' '}
          <a href={sitemapUrl} target="_blank">/sitemap.xml <i className="fas fa-external-link-alt" /></a>.{' '}
          This extension's bundled sitemap is automatically deferred — no conflict.
        </div>
      );
    }

    if (provider === 'bundled') {
      return (
        <div className="row-not-passed-error" style="background:#e8f5e9;color:#1b5e20;border-color:#a5d6a7;">
          <i className="fas fa-check-circle" /> <b>Bundled sitemap is active.</b>{' '}
          This extension is serving{' '}
          <a href={sitemapUrl} target="_blank">/sitemap.xml <i className="fas fa-external-link-alt" /></a>.{' '}
          Generated from your guest-visible discussions, capped at 50,000 URLs, cached for 6 hours.
        </div>
      );
    }

    // provider === 'none'
    return (
      <div className="row-not-passed-error">
        <i className="fas fa-exclamation-circle" /> <b>No sitemap is being served.</b>{' '}
        You've turned off the bundled sitemap and FoF Sitemap isn't installed.
        Search engines will have a harder time finding your content. Pick an option below
        to fix this.
      </div>
    );
  }

  // Radio + Save — only shown when fof/sitemap isn't installed (so the choice is meaningful).
  modeChooser() {
    return (
      <div className="seo-sitemap-chooser" style="margin:18px 0;padding:14px 18px;border:1px solid var(--control-bg, #ddd);border-radius:6px;">
        <h4 style="margin-top:0;">Which sitemap should this extension use?</h4>

        <label style="display:block;margin:10px 0;cursor:pointer;">
          <input
            type="radio"
            name="seo_sitemap_mode"
            checked={this.mode === 'bundled'}
            onchange={() => { this.mode = 'bundled'; }}
            style="margin-right:8px;"
          />
          <b>Use the bundled sitemap</b> <span style="opacity:0.75;">(recommended for most forums)</span>
          <div style="font-size:13px;opacity:0.8;margin-left:24px;">
            Zero install. Generated on demand from your discussions, capped at 50k URLs.
          </div>
        </label>

        <label style="display:block;margin:10px 0;cursor:pointer;">
          <input
            type="radio"
            name="seo_sitemap_mode"
            checked={this.mode === 'off'}
            onchange={() => { this.mode = 'off'; }}
            style="margin-right:8px;"
          />
          <b>Turn off the bundled sitemap</b>
          <div style="font-size:13px;opacity:0.8;margin-left:24px;">
            Pick this if you plan to install <a href="https://discuss.flarum.org/d/14941-fof-sitemap" target="_blank">FoF Sitemap <i className="fas fa-external-link-alt" /></a>{' '}
            (it auto-takes over once installed), or you handle <code>/sitemap.xml</code> yourself via CDN / static file.
          </div>
        </label>

        {Button.component({
          className: 'Button Button--primary',
          onclick: () => this.save(),
          loading: this.saving,
          disabled: this.mode === (app.data.settings.seo_sitemap_mode === 'off' ? 'off' : 'bundled'),
        }, 'Save preference')}
      </div>
    );
  }

  // Side-by-side trade-off matrix shown when "Read more" is expanded.
  comparison(fofInstalled) {
    return (
      <div style="margin:18px 0;">
        <h4>Bundled sitemap vs. FoF Sitemap</h4>

        <table className="seo-check-table" style="width:100%;">
          <thead>
            <tr>
              <td>Feature</td>
              <td>Bundled (this extension)</td>
              <td>FoF Sitemap</td>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Install</td>
              <td>Zero — already here</td>
              <td><code>composer require fof/sitemap</code> + enable</td>
            </tr>
            <tr>
              <td>URL cap</td>
              <td>50,000 (single-file, per sitemaps.org limit)</td>
              <td>Unlimited via sitemap-index pagination</td>
            </tr>
            <tr>
              <td>Resources covered</td>
              <td>Discussions only</td>
              <td>Discussions, Users, Tags, Pages (if installed)</td>
            </tr>
            <tr>
              <td>Generation</td>
              <td>On-demand, 6-hour cache</td>
              <td>Scheduled rebuild, written to disk</td>
            </tr>
            <tr>
              <td>Memory profile</td>
              <td>Streamed string-builder (~30 MB at cap)</td>
              <td>Disk-backed sitemap files; lower runtime memory</td>
            </tr>
            <tr>
              <td>Configuration</td>
              <td>None — just works</td>
              <td>Priority, frequency, per-resource thresholds</td>
            </tr>
            <tr>
              <td>Best for</td>
              <td>Forums under ~50k discussions wanting a one-click sitemap</td>
              <td>Larger forums, multi-resource crawl coverage, custom tuning</td>
            </tr>
          </tbody>
        </table>

        {fofInstalled ? (
          <p>
            <i className="fas fa-info-circle" /> FoF Sitemap is installed, so it owns{' '}
            <code>/sitemap.xml</code> automatically. This extension's bundled sitemap
            is deferred to avoid a route conflict — no action needed from you.
          </p>
        ) : (
          <p>
            <i className="fas fa-info-circle" /> If you install FoF Sitemap later, this
            extension will automatically defer to it. You don't need to change the setting
            above first.
          </p>
        )}
      </div>
    );
  }

  save() {
    if (this.saving) return;
    this.saving = true;

    saveSettings({ seo_sitemap_mode: this.mode })
      .then(() => {
        app.data.settings.seo_sitemap_mode = this.mode;
        app.alerts.show({ type: 'success' }, app.translator.trans('core.admin.settings.saved_message'));
      })
      .catch((e) => {
        app.alerts.show({ type: 'error' }, 'Failed to save sitemap preference.');
      })
      .then(() => {
        this.saving = false;
        m.redraw();
      });
  }
}
