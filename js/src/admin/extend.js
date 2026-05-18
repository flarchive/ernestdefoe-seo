import Extend from 'flarum/common/extenders';
import SettingsPage from './Pages/SettingsPage';

// Flarum 2 canonical pattern: the Admin extender (mirrors PHP extenders)
// replaces v1's app.extensionData.for(...).registerPage() / registerPermission().
//
// - .page() swaps Flarum's default extension page with our SettingsPage
//   (tabbed: Health / SEO settings / Sitemap / Search Engines / SSL).
// - .permission() registers the seo.canConfigure permission row in the
//   "Moderate" column of the permissions grid. The translator key matches
//   locale/en.yml. Icon + category mirror the v1 registration.
export default [
    new Extend.Admin()
        .page(SettingsPage)
        .permission(
            () => ({
                icon: 'fas fa-search',
                label: app.translator.trans('ernestdefoe-seo.admin.permissions.configure_seo'),
                permission: 'seo.canConfigure',
            }),
            'moderate',
            90
        ),
];
