import app from "flarum/forum/app";
import DiscussionControls from "flarum/forum/utils/DiscussionControls";
import Button from "flarum/common/components/Button";
import { extend } from "flarum/common/extend";
import SeoMeta from "../common/Models/SeoMeta";
import Discussion from "flarum/common/models/Discussion";
import Model from "flarum/common/Model";

app.initializers.add("ernestdefoe-seo", () => {
  extend(
    DiscussionControls,
    "moderationControls",
    function (items, discussion) {
      if (!app.forum.attribute('canConfigureSeo')) return;

      items.add(
        "manageSeo",
        Button.component(
          {
            icon: "fas fa-search",
            onclick: () =>
              app.modal.show(() => import("./components/MetaSeoModal"), {
                objectType: "discussions",
                objectId: discussion.id(),
              }),
          },
          app.translator.trans(
            "ernestdefoe-seo.forum.controls.configure_seo"
          )
        ),
        -1000
      );
    }
  );

  // Register SeoMeta model under its API type, so the store accepts seo_meta records
  app.store.models.seo_meta = SeoMeta;

  // Register SeoMeta relations
  Discussion.prototype.seoMeta = Model.hasOne("seoMeta");
});
