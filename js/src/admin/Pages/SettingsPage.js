import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import SeoSettings from "../components/Forms/SeoSettings";
import HealthCheck from './HealthCheck';
import RegisterToSearchEngines from './RegisterToSearchEngines';
import SSLPage from './SSLPage';
import Button from 'flarum/common/components/Button';
import Sitemap from './Sitemap';

const I18N_PREFIX = 'ernestdefoe-seo.admin.settings_page.menu';

export default class SettingsPage extends ExtensionPage {
  content() {
    const page = m.route.param().page || 'health';

    return (
      <div className="ExtensionPage-settings FlarumSEO">
        <div className={"seo-menu"}>
          <div className={"container"}>
            {this.menuButtons(page)}
          </div>
        </div>

        <div className="container FlarumSeoPage-container">
          {this.pageContent(page)}
        </div>
      </div>
    );
  }

  // Return button menus. Labels go through the translator so the
  // nine locale files this extension ships actually reach the admin
  // UI — the previous build hardcoded the English strings inline,
  // which meant only English ever rendered regardless of the
  // operator's selected locale.
  menuButtons(page) {
    const t = (key) => app.translator.trans(`${I18N_PREFIX}.${key}`);

    return [
      Button.component({
        className: `Button ${page === 'health' ? 'item-selected' : ''}`,
        onclick: () => m.route.set(
          app.route('extension', {
            id: 'ernestdefoe-seo'
          })
        ),
        icon: 'fas fa-heartbeat',
      }, t('health_check')),
      Button.component({
        className: `Button ${page === 'settings' ? 'item-selected' : ''}`,
        onclick: () => m.route.set(
          app.route('extension', {
            id: 'ernestdefoe-seo',
            page: 'settings'
          })
        ),
        icon: 'fas fa-cogs',
      }, t('seo_settings')),
      Button.component({
        className: `Button ${page === 'sitemap' ? 'item-selected' : ''}`,
        onclick: () => m.route.set(
          app.route('extension', {
            id: 'ernestdefoe-seo',
            page: 'sitemap'
          })
        ),
        icon: 'fas fa-sitemap',
      }, t('sitemap_information')),
      Button.component({
        className: `Button ${page === 'search-engines' ? 'item-selected' : ''}`,
        onclick: () => m.route.set(
          app.route('extension', {
            id: 'ernestdefoe-seo',
            page: 'search-engines'
          })
        ),
        icon: 'fas fa-search',
      }, t('search_engine_information')),
      Button.component({
        className: `Button ${page === 'ssl' ? 'item-selected' : ''}`,
        onclick: () => m.route.set(
          app.route('extension', {
            id: 'ernestdefoe-seo',
            page: 'ssl'
          })
        ),
        icon: 'fas fa-shield-alt',
      }, t('set_up_ssl'))
    ];
  }


  pageContent(page) {
    if(page === 'search-engines') {
      return <RegisterToSearchEngines />
    }else if(page === "settings") {
      return <SeoSettings />
    }else if(page === "ssl") {
      return <SSLPage />
    }else if(page === "sitemap") {
      return <Sitemap />
    }

    // Default healthcheck
    return <HealthCheck />
  }
}
