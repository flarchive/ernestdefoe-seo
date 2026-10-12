import Model from 'flarum/common/Model';

export default class SeoMeta extends Model {
  apiEndpoint() {
    return '/seo_meta' + (this.exists ? '/' + this.data.id : '');
  }
}

// The attribute accessors, as flarum/common/utils/mixin would add them, but
// on SeoMeta itself so it type-checks as the Model it is.
Object.assign(SeoMeta.prototype, {
  // Object info
  objectType: Model.attribute('objectType'),
  objectId: Model.attribute('objectId'),

  // Auto update data
  autoUpdateData: Model.attribute('autoUpdateData'),

  // Default HTML Tags
  title: Model.attribute('title'),
  description: Model.attribute('description'),
  keywords: Model.attribute('keywords'),

  // Robots
  robotsNoindex: Model.attribute('robotsNoindex'),
  robotsNofollow: Model.attribute('robotsNofollow'),
  robotsNoarchive: Model.attribute('robotsNoarchive'),
  robotsNoimageindex: Model.attribute('robotsNoimageindex'),
  robotsNosnippet: Model.attribute('robotsNosnippet'),

  // Twitter tags
  twitterTitle: Model.attribute('twitterTitle'),
  twitterDescription: Model.attribute('twitterDescription'),
  twitterImage: Model.attribute('twitterImage'),
  twitterImageSource: Model.attribute('twitterImageSource'),

  // Open Graph tags
  openGraphTitle: Model.attribute('openGraphTitle'),
  openGraphDescription: Model.attribute('openGraphDescription'),
  openGraphImage: Model.attribute('openGraphImage'),
  openGraphImageSource: Model.attribute('openGraphImageSource'),

  // Extra
  estimatedReadingTime: Model.attribute('estimatedReadingTime'),

  // Row info
  createdAt: Model.attribute('createdAt'),
  updatedAt: Model.attribute('updatedAt'),
});
