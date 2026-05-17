import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import DashboardPage from 'flarum/admin/components/DashboardPage';
import SeoWidget from './components/SeoWidget';

// Page registration + permission registration moved to ./extend.js using
// the canonical Flarum 2 `Extend.Admin` extender. Anything that doesn't
// have a corresponding extender (dashboard widget injection) stays here
// as a plain initializer.
app.initializers.add('ernestdefoe-seo', () => {
    extend(DashboardPage.prototype, 'availableWidgets', (widgets) => {
        widgets.add('seo-widget', <SeoWidget />, 500);
    });
});

export { default as extend } from './extend';

export * from './components';
export * from './Pages';
