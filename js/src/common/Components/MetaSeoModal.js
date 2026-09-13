import FormModal from "flarum/common/components/FormModal";
import Button from "flarum/common/components/Button";
import Switch from "flarum/common/components/Switch";
import Stream from "flarum/common/utils/Stream";
import Alert from "flarum/common/components/Alert";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import FieldSet from "flarum/common/components/FieldSet";
import countKeywords from "../../admin/utils/countKeywords";
import clsx from "clsx";

// Translator prefix for every UI string in this modal. All visible
// strings live under `ernestdefoe-seo.admin.meta_seo_modal.*` in the
// locale files. Previously every label was hardcoded English inline,
// which silently made the eight non-English locale files this
// extension ships unreachable for the admin meta-editing surface.
const I18N_PREFIX = "ernestdefoe-seo.admin.meta_seo_modal";
const t = (key, vars) =>
  app.translator.trans(`${I18N_PREFIX}.${key}`, vars);

// Flarum 2 split Modal: bare Modal no longer wraps its body in <form>,
// so onsubmit + <Button type="submit"> are dead. FormModal restores
// the form wrapper. className() is already implemented below.
export default class MetaSeoModal extends FormModal {
  initialized = true;
  initialLoading = false;

  oninit(vnode) {
    super.oninit(vnode);

    // Open dialog
    if (this.attrs.object) {
      // Get SeoMeta relationship
      if (!this.attrs.object.seoMeta) {
        this.initialized = false;

        app.alerts.show(
          Alert,
          {
            type: "error",
            title: t("not_supported.alert_title"),
            controls: [
              <a
                class="Button Button--link"
                href="https://community.v17.dev/knowledgebase/46"
                target={"_blank"}
              >
                {t("not_supported.documentation_link")}
              </a>,
            ],
          },
          t("not_supported.alert_body")
        );

        setTimeout(() => {
          this.hide();
        }, 100);
        return;
      }

      this.meta = this.attrs.object.seoMeta();
    } else {
      this.initializeLoad();
    }

    this.hasChanges = false;
    this.closeText = t("buttons.close");
    this.closeInfoText = null;
    this.loading = false;

    this.enableCustomTwitter = false;
    this.enableCustomOpenGraph = false;
    this.wasManaged = true;
    this.seoTagsOpened = false;

    // Define options
    this.initializeData();
  }

  initializeData() {
    if (!this.meta) return;

    this.autoUpdateData = Stream(this.meta.autoUpdateData());
    this.wasManaged = this.meta.autoUpdateData() === true;

    this.metaTitle = Stream(this.meta.title());
    this.description = Stream(this.meta.description());
    this.keywords = Stream(this.meta.keywords());
    this.robotsNoindex = Stream(this.meta.robotsNoindex());
    this.robotsNofollow = Stream(this.meta.robotsNofollow());
    this.robotsNoarchive = Stream(this.meta.robotsNoarchive());
    this.robotsNoimageindex = Stream(this.meta.robotsNoimageindex());
    this.robotsNosnippet = Stream(this.meta.robotsNosnippet());
    this.twitterTitle = Stream(this.meta.twitterTitle());
    this.twitterDescription = Stream(this.meta.twitterDescription());
    this.twitterImage = Stream(this.meta.twitterImage());
    this.twitterImageSource = Stream(this.meta.twitterImageSource());
    this.openGraphTitle = Stream(this.meta.openGraphTitle());
    this.openGraphDescription = Stream(this.meta.openGraphDescription());
    this.openGraphImage = Stream(this.meta.openGraphImage());
    this.openGraphImageSource = Stream(this.meta.openGraphImageSource());
    this.estimatedReadingTime = Stream(this.meta.estimatedReadingTime());
    this.createdAt = Stream(this.meta.createdAt());
    this.updatedAt = Stream(this.meta.updatedAt());

    this.enableCustomTwitter =
      this.twitterTitle() !== null ||
      this.twitterDescription() !== null ||
      this.twitterImageSource() !== "auto"
        ? true
        : false;

    this.enableCustomOpenGraph =
      this.openGraphTitle() !== null || this.openGraphDescription() !== null
        ? true
        : false;
  }

  title() {
    return t("title");
  }

  className() {
    return "Modal Modal-SEO-settings";
  }

  initializeLoad() {
    this.initialLoading = true;

    app.store
      .find("seo_meta", `${this.attrs.objectType}-${this.attrs.objectId}`)
      .then((data) => {
        this.isLoading = false;
        this.meta = data;
        this.initialLoading = false;

        this.initializeData();
      })
      .then(() => {
        m.redraw();
      });
  }

  // Compact helper for the "✓ Managed" indicator that appears on every
  // managed-field column. The label needs the translator wrap; the
  // icon + container stay inline.
  managedTag() {
    return (
      <div className="ManagedText">
        <i className="fas fa-check" /> {t("managed_label")}
      </div>
    );
  }

  content() {
    // Hide due to invalid relationship or loading data
    if (!this.initialized || this.initialLoading) {
      return <div>{LoadingIndicator.component({})}</div>;
    }

    return (
      <div>
        <div className="Modal-body" onkeyup={() => this.updateHasChanges()}>
          <div className="Form">
            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("auto_update.title")}</div>
                <div className="helpText">{t("auto_update.help")}</div>
              </div>
              <div className="SeoItemContent">
                <div className="ManagedContainer">
                  {Switch.component(
                    {
                      state: this.autoUpdateData(),
                      onchange: (value) => {
                        this.autoUpdateData(value);
                        this.updateHasChanges();
                      },
                    },
                    t("auto_update.switch_label")
                  )}
                </div>
              </div>
            </div>

            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("meta_title.title")}</div>
                <div className="helpText">{t("meta_title.help")}</div>
              </div>

              <div className="SeoItemContent">
                <div className="ManagedContainer">
                  <input
                    className="FormControl"
                    bidi={this.metaTitle}
                    placeholder={t("meta_title.placeholder")}
                    disabled={this.autoUpdateData()}
                  />

                  {this.autoUpdateData() && this.managedTag()}
                </div>
              </div>
            </div>

            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("meta_description.title")}</div>
                <div className="helpText">{t("meta_description.help")}</div>
              </div>

              <div className="SeoItemContent">
                <div className="ManagedContainer">
                  <textarea
                    className="FormControl"
                    bidi={this.description}
                    placeholder={t("meta_description.placeholder")}
                    disabled={this.autoUpdateData()}
                  />

                  {this.autoUpdateData() && this.managedTag()}
                </div>
              </div>
            </div>

            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("keywords.title")}</div>
                <div className="helpText">{t("keywords.help")}</div>
              </div>

              <div className="SeoItemContent">
                <textarea
                  className="FormControl"
                  bidi={this.keywords}
                  placeholder={t("keywords.placeholder")}
                />
                <div
                  className={clsx(
                    "SeoItemContent-helpertext",
                    countKeywords(this.keywords() ?? "") == false && "invalid"
                  )}
                >
                  <b>{t("keywords.note")}</b> {t("keywords.example_prefix")}{" "}
                  <i>{t("keywords.example")}</i>
                </div>
              </div>
            </div>

            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("meta_image.title")}</div>
                <div className="helpText">{t("meta_image.help")}</div>
              </div>

              <div className="SeoItemContent">
                <div className="ManagedContainer">
                  <input
                    className="FormControl"
                    bidi={this.openGraphImage}
                    placeholder={t("meta_image.placeholder")}
                    disabled={
                      this.autoUpdateData() &&
                      this.openGraphImageSource() === "auto"
                    }
                  />

                  {/* Show managed tag */}
                  {this.autoUpdateData() &&
                    this.openGraphImageSource() !== "custom" &&
                    this.managedTag()}

                  {!this.autoUpdateData() &&
                    this.returnFoFUploadButton((fileUrl) => {
                      this.openGraphImage(fileUrl);
                      this.openGraphImageSource("fof-upload");
                    })}

                  {/* Show managed by message */}
                  {this.openGraphImageSource() !== "auto" &&
                    this.openGraphImageSource() !== "custom" && (
                      <div className="SeoItemContent-helpertext">
                        {t("meta_image.managed_by", {
                          source: this.openGraphImageSource(),
                        })}
                      </div>
                    )}
                </div>
              </div>
            </div>

            {/* Robots */}
            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("robots.title")}</div>
                <div className="helpText">{t("robots.help")}</div>
              </div>

              <div className="SeoItemContent">
                <div
                  class={clsx(
                    "SeoTags-dropdown-container",
                    this.seoTagsOpened && "SeoTags-dropdown-open"
                  )}
                >
                  <div
                    className="SeoTags"
                    onclick={() => (this.seoTagsOpened = !this.seoTagsOpened)}
                  >
                    {this.returnTag(
                      !this.robotsNoindex(),
                      t("robots.tag_index_allowed"),
                      t("robots.tag_index_disallowed")
                    )}
                    {this.returnTag(
                      !this.robotsNofollow(),
                      t("robots.tag_follow_allowed"),
                      t("robots.tag_follow_disallowed")
                    )}
                    {this.robotsNoarchive() &&
                      this.returnTag(false, "", t("robots.tag_noarchive"))}
                    {this.robotsNoimageindex() &&
                      this.returnTag(false, "", t("robots.tag_noimageindex"))}
                    {this.robotsNosnippet() &&
                      this.returnTag(false, "", t("robots.tag_nosnippet"))}
                  </div>

                  <div className={"SeoTags-dropdown"}>
                    {Switch.component(
                      {
                        state: !this.robotsNoindex(),
                        onchange: (value) => {
                          this.robotsNoindex(!value);
                          this.updateHasChanges();
                        },
                      },
                      t("robots.switch_noindex")
                    )}
                    {Switch.component(
                      {
                        state: !this.robotsNofollow(),
                        onchange: (value) => {
                          this.robotsNofollow(!value);
                          this.updateHasChanges();
                        },
                      },
                      t("robots.switch_nofollow")
                    )}
                    {Switch.component(
                      {
                        state: this.robotsNoarchive(),
                        onchange: (value) => {
                          this.robotsNoarchive(value);
                          this.updateHasChanges();
                        },
                      },
                      t("robots.switch_noarchive")
                    )}
                    {Switch.component(
                      {
                        state: this.robotsNoimageindex(),
                        onchange: (value) => {
                          this.robotsNoimageindex(value);
                          this.updateHasChanges();
                        },
                      },
                      t("robots.switch_noimageindex")
                    )}
                    {Switch.component(
                      {
                        state: this.robotsNosnippet(),
                        onchange: (value) => {
                          this.robotsNosnippet(value);
                          this.updateHasChanges();
                        },
                      },
                      t("robots.switch_nosnippet")
                    )}
                  </div>
                </div>
              </div>
            </div>

            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("reading_time.title")}</div>
                <div className="helpText">{t("reading_time.help")}</div>
              </div>

              <div className="SeoItemContent">
                <div className="ManagedContainer">
                  <input
                    className="FormControl"
                    bidi={this.estimatedReadingTime}
                    placeholder={t("reading_time.placeholder")}
                    type="number"
                    disabled={this.autoUpdateData()}
                  />

                  {this.autoUpdateData() && this.managedTag()}
                </div>
              </div>
            </div>

            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("twitter.card_title")}</div>
              </div>

              <div className="SeoItemContent">
                <div className="ManagedContainer">
                  {Switch.component(
                    {
                      state: !this.enableCustomTwitter,
                      onchange: (value) => (this.enableCustomTwitter = !value),
                      disabled: this.autoUpdateData(),
                    },
                    t("twitter.switch_auto")
                  )}

                  {this.autoUpdateData() && this.managedTag()}
                </div>
              </div>
            </div>

            {this.enableCustomTwitter && (
              <div className="SeoItemContainer">
                <div className="SeoItemInfo">
                  <div class={"SeoItemInfo-title"}>{t("twitter.title")}</div>
                </div>

                <div className="SeoItemContent">
                  <div className="ManagedContainer">
                    <input
                      className="FormControl"
                      bidi={this.twitterTitle}
                      placeholder={this.metaTitle()}
                      disabled={this.autoUpdateData()}
                    />
                  </div>
                </div>
              </div>
            )}

            {this.enableCustomTwitter && (
              <div className="SeoItemContainer">
                <div className="SeoItemInfo">
                  <div class={"SeoItemInfo-title"}>{t("twitter.description")}</div>
                </div>

                <div className="SeoItemContent">
                  <div className="ManagedContainer">
                    <textarea
                      className="FormControl"
                      bidi={this.twitterDescription}
                      placeholder={this.description()}
                      disabled={this.autoUpdateData()}
                    />
                  </div>
                </div>
              </div>
            )}

            {this.enableCustomTwitter && (
              <div className="SeoItemContainer">
                <div className="SeoItemInfo">
                  <div class={"SeoItemInfo-title"}>{t("twitter.image_title")}</div>
                  <div className="helpText">{t("twitter.image_help")}</div>
                </div>

                <div className="SeoItemContent">
                  <div className="ManagedContainer">
                    <input
                      className="FormControl"
                      bidi={this.twitterImage}
                      placeholder={
                        this.openGraphImage() ?? t("twitter.image_placeholder")
                      }
                      disabled={
                        this.autoUpdateData() && this.twitterImage() === "auto"
                      }
                    />

                    {this.returnFoFUploadButton((fileUrl) => {
                      this.twitterImage(fileUrl);
                      this.twitterImageSource("fof-upload");
                    })}

                    {/* Show managed by message */}
                    {this.twitterImageSource() !== "auto" &&
                      this.twitterImageSource() !== "custom" && (
                        <div className="SeoItemContent-helpertext">
                          {t("twitter.image_managed_by", {
                            source: this.twitterImageSource(),
                          })}{" "}
                          -{" "}
                          <a
                            href="#"
                            onclick={(e) => {
                              e.preventDefault();

                              this.twitterImage(null);
                              this.twitterImageSource("auto");
                              this.updateHasChanges();
                            }}
                          >
                            {t("twitter.reset_image")}
                          </a>
                        </div>
                      )}
                  </div>
                </div>
              </div>
            )}

            <div className="SeoItemContainer">
              <div className="SeoItemInfo">
                <div class={"SeoItemInfo-title"}>{t("open_graph.tags_title")}</div>
              </div>

              <div className="SeoItemContent">
                <div className="ManagedContainer">
                  {Switch.component(
                    {
                      state: !this.enableCustomOpenGraph,
                      onchange: (value) =>
                        (this.enableCustomOpenGraph = !value),
                      disabled: this.autoUpdateData(),
                    },
                    t("open_graph.switch_auto")
                  )}

                  {this.autoUpdateData() && this.managedTag()}
                </div>
              </div>
            </div>

            {this.enableCustomOpenGraph && (
              <div className="SeoItemContainer">
                <div className="SeoItemInfo">
                  <div class={"SeoItemInfo-title"}>{t("open_graph.title")}</div>
                </div>

                <div className="SeoItemContent">
                  <div className="ManagedContainer">
                    <input
                      className="FormControl"
                      bidi={this.openGraphTitle}
                      placeholder={this.metaTitle()}
                      disabled={this.autoUpdateData()}
                    />
                  </div>
                </div>
              </div>
            )}

            {this.enableCustomOpenGraph && (
              <div className="SeoItemContainer">
                <div className="SeoItemInfo">
                  <div class={"SeoItemInfo-title"}>{t("open_graph.description")}</div>
                </div>

                <div className="SeoItemContent">
                  <div className="ManagedContainer">
                    <textarea
                      className="FormControl"
                      bidi={this.openGraphDescription}
                      placeholder={t("open_graph.description_placeholder")}
                      disabled={this.autoUpdateData()}
                    />
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>
        <div style="padding: 25px 30px; text-align: center;">
          {this.closeInfoText && (
            <div style="margin-bottom: 15px; font-size: 12px;">
              <b>{t("footer.note_prefix")}</b> {this.closeInfoText}
            </div>
          )}
          {this.closeDialogButton()}
        </div>
      </div>
    );
  }

  returnFoFUploadButton(onSelect) {
    let fofUploadButton = null;

    // fof/upload integration: resolved via flarum.reg at runtime so the
    // webpack bundler doesn't need @fof-upload as a build-time dep.
    // (Static `require("@fof-upload")` would break the build any time
    // fof/upload isn't installed alongside this extension during
    // bundling — but the extension MUST keep working in the
    // overwhelmingly common case where fof/upload is absent.)
    if (
      "fof-upload" in (window.flarum?.extensions || {}) &&
      app.forum.attribute("fof-upload.canUpload")
    ) {
      const Uploader = flarum.reg.get("fof-upload", "components/Uploader");
      const FileManagerModal = flarum.reg.get(
        "fof-upload",
        "components/FileManagerModal"
      );
      if (!Uploader || !FileManagerModal) return null;

      const uploader = new Uploader();

      fofUploadButton = (
        <Button
          class="UploadButton Button"
          onclick={async () => {
            app.modal.show(
              FileManagerModal,
              {
                uploader: uploader,
                onSelect: (files) => {
                  const file = app.store.getById("files", files[0]);

                  onSelect(file.url());
                  this.updateHasChanges();
                },
              },
              true
            );
          }}
        >
          {t("upload_button")}
        </Button>
      );
    }

    return fofUploadButton;
  }

  returnTag(isEnabled, enabledText, disabledText) {
    return (
      <div className={clsx("SeoTag", !isEnabled && "SeoTagDisabled")}>
        {isEnabled ? enabledText : disabledText}
      </div>
    );
  }

  closeDialogButton() {
    return (
      <Button
        type="submit"
        className="Button Button--primary"
        loading={this.loading}
      >
        {this.closeText}
      </Button>
    );
  }

  updateHasChanges() {
    this.closeText =
      !this.wasManaged && this.autoUpdateData()
        ? t("buttons.save_and_autofill")
        : t("buttons.save");

    // Transform custom tags to managed tags
    if (!this.wasManaged && this.autoUpdateData()) {
      this.closeInfoText = t("footer.revert_warning");
    }

    this.hasChanges = true;
  }

  submitData() {
    let data = {};

    data.autoUpdateData = this.autoUpdateData();

    data.title = this.metaTitle();
    data.description = this.description();

    // Add keywords
    if (this.keywords() !== "") {
      data.keywords = this.keywords() ?? null;
    }

    // Add robot settings
    data.robotsNoindex = this.robotsNoindex();
    data.robotsNofollow = this.robotsNofollow();
    data.robotsNoarchive = this.robotsNoarchive();
    data.robotsNoimageindex = this.robotsNoimageindex();
    data.robotsNosnippet = this.robotsNosnippet();

    // Add Twitter info
    if (this.twitterTitle() !== "") {
      data.twitterTitle = this.twitterTitle() ?? null;
    }

    if (this.twitterDescription() !== "") {
      data.twitterDescription = this.twitterDescription() ?? null;
    }

    if (this.twitterImage() !== "") {
      data.twitterImage = this.twitterImage();
    }

    if (this.twitterImageSource() !== "auto") {
      data.twitterImageSource = this.twitterImageSource() ?? null;
    }

    // Open graph
    if (this.openGraphTitle() !== "") {
      data.openGraphTitle = this.openGraphTitle() ?? null;
    }

    if (this.openGraphDescription() !== "") {
      data.openGraphDescription = this.openGraphDescription() ?? null;
    }
    if (this.openGraphImage() !== "") {
      data.openGraphImage = this.openGraphImage() ?? null;
    }

    if (this.openGraphImageSource() !== "auto") {
      data.openGraphImageSource = this.openGraphImageSource() ?? null;
    }

    if (this.estimatedReadingTime() !== "") {
      data.estimatedReadingTime = this.estimatedReadingTime() ?? null;
    }

    return data;
  }

  // Close or save setting
  onsubmit(e) {
    e.preventDefault();

    if (!this.hasChanges) {
      this.hide();
      return;
    }

    this.loading = true;

    this.meta
      .save(this.submitData())
      .then(() => {
        app.alerts.show({ type: "success" }, t("alerts.saved"));
        this.hide();
      })
      .catch((e) => {
        // Surface the failure. Previously the catch only did a
        // `console.log(e)` and reset `saving`, so the admin clicked
        // Save, received no feedback, and assumed the metadata had
        // been written. Show the alert AND keep the modal open
        // (don't `this.hide()`) so the operator can read the error
        // and retry. The console.log is dropped — devtools-only
        // error reporting from a production extension is a §40.2
        // robustness finding.
        const detail =
          e?.response?.errors?.[0]?.detail ?? t("alerts.save_error");
        app.alerts.show({ type: "error" }, detail);
        this.loading = false;
      })
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  onsaved() {
    this.hide();
  }
}
