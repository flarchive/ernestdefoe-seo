import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import saveSettings from 'flarum/admin/utils/saveSettings';

// See CrawlPostModal for why this extends FormModal (form wrapper) and
// must implement className() (now abstract on Modal in Flarum 2).
export default class RobotsModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);

    this.value = typeof app.data.settings.seo_robots_text === "undefined" ? '' : app.data.settings.seo_robots_text;
    this.startValue = this.value;

    this.closeText = 'Close';
    this.loading = false;
  }

  className() {
    return 'Modal--medium RobotsModal';
  }

  title() {
    return 'Custom robots.txt';
  }

  content() {
    return (
      <div>
        <div className="Modal-body">
          {m('textarea', {
            className: "FormControl",
            value: this.value,
            placeholder: 'Add text to the robots.txt',
            rows: 15,
            oninput: (event) => {
              this.change(event.target.value);
            }
          })}
        </div>
        <div style="padding: 25px 30px; text-align: center;">
          {this.closeDialogButton()}
        </div>
      </div>
    );
  }

  change(value) {
    this.value = value;

    this.closeText = this.value !== this.startValue ? 'Save changes' : 'Close';
  }

  closeDialogButton() {
    return (
      <Button
        type="submit"
        className="Button Button--primary"
        loading={this.loading}>
        {this.closeText}
      </Button>
    );
  }

  // Close or save setting
  onsubmit(e) {
    if(this.value === this.startValue) {
      this.hide();
      return;
    }

    this.loading = true;

    let data = {};
    data.seo_robots_text = this.value;

    saveSettings(data).then(
      this.onsaved.bind(this)
    );
  }

  onsaved() {
    this.hide();
  }
}