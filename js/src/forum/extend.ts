import Extend from 'flarum/common/extenders';
import Discussion from 'flarum/common/models/Discussion';
import SeoMeta from '../common/Models/SeoMeta';

export default [
  // The store accepts seo_meta records as SeoMeta models.
  new Extend.Store().add('seo_meta', SeoMeta),

  // discussion.seoMeta()
  new Extend.Model(Discussion).hasOne('seoMeta'),
];
