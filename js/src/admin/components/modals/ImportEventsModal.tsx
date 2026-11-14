import app from 'flarum/admin/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Season from '../../../common/models/Season';
import Week from '../../../common/models/Week';
import type Mithril from 'mithril';

interface ImportEventsModalAttrs extends IFormModalAttrs {
  onsave: () => void;
}

export default class ImportEventsModal extends FormModal<ImportEventsModalAttrs> {
  private seasonId = '';
  private weekId = '';
  private csv = '';
  private error = '';
  loading = false;

  className(): string {
    return 'ImportEventsModal Modal--medium';
  }

  title(): Mithril.Children {
    return app.translator.trans('huseyinfiliz-pickem.admin.import.title');
  }

  content() {
    const seasons = app.store.all<Season>('pickem-seasons');
    const weeks = app.store.all<Week>('pickem-weeks').filter((week) => String(week.seasonId()) === this.seasonId);
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label>{app.translator.trans('huseyinfiliz-pickem.lib.common.season')}</label>
          <select
            className="FormControl"
            value={this.seasonId}
            required
            onchange={(e: Event) => {
              this.seasonId = (e.target as HTMLSelectElement).value;
              this.weekId = '';
            }}
          >
            <option value="">{app.translator.trans('huseyinfiliz-pickem.lib.form.select_season')}</option>
            {seasons.map((season) => (
              <option key={season.id()} value={season.id()}>
                {season.name()}
              </option>
            ))}
          </select>
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('huseyinfiliz-pickem.lib.common.week')}</label>
          <select
            className="FormControl"
            value={this.weekId}
            required
            disabled={!this.seasonId}
            onchange={(e: Event) => {
              this.weekId = (e.target as HTMLSelectElement).value;
            }}
          >
            <option value="">{app.translator.trans('huseyinfiliz-pickem.lib.form.select_week')}</option>
            {weeks.map((week) => (
              <option key={week.id()} value={week.id()}>
                {week.name()}
              </option>
            ))}
          </select>
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('huseyinfiliz-pickem.admin.import.csv_label')}</label>
          <p>{app.translator.trans('huseyinfiliz-pickem.admin.import.help', { timezone })}</p>
          <textarea
            className="FormControl"
            rows={10}
            required
            value={this.csv}
            placeholder={'home_slug,away_slug,start_time\nteam-a,team-b,2026-12-01 20:00'}
            oninput={(e: InputEvent) => {
              this.csv = (e.target as HTMLTextAreaElement).value;
            }}
          />
        </div>
        {this.error && (
          <div className="Alert Alert--error" role="alert" style="white-space: pre-wrap">
            {this.error}
          </div>
        )}
        <Button className="Button Button--primary" type="submit" loading={this.loading}>
          {app.translator.trans('huseyinfiliz-pickem.admin.import.submit')}
        </Button>
      </div>
    );
  }

  async onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (this.loading) return;
    this.error = '';
    this.loading = true;
    m.redraw();

    try {
      const response = await app.request<{
        count: number;
        teams: { type: string; id: string; attributes: { name: string; slug: string; logoUrl: string | null } }[];
      }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/pickem-events/import',
        body: {
          data: {
            attributes: {
              seasonId: Number(this.seasonId),
              weekId: Number(this.weekId),
              csv: this.csv,
              timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            },
          },
        },
      });
      app.store.pushPayload({ data: response.teams });
      this.attrs.onsave();
      this.hide();
      app.alerts.show({ type: 'success' }, app.translator.trans('huseyinfiliz-pickem.admin.import.success', { count: response.count }));
    } catch (error: any) {
      this.error =
        error.response?.errors
          ?.map((item: { detail?: string }) => item.detail)
          .filter(Boolean)
          .join('\n') || String(app.translator.trans('huseyinfiliz-pickem.admin.import.failed'));
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
