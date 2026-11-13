import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Pick from '../../common/models/Pick';

interface PredictedScoreFormAttrs {
  pick: Pick;
  firstTeam: string;
  secondTeam: string;
  reverse: boolean;
  isLoading: boolean;
  onSave: (home: number | null, away: number | null) => Promise<boolean>;
}

export default class PredictedScoreForm extends Component<PredictedScoreFormAttrs> {
  private first = '';
  private second = '';
  private editing = true;

  oninit(vnode: any) {
    super.oninit(vnode);
    const { pick, reverse } = this.attrs;
    this.first = String((reverse ? pick.predictedAwayScore() : pick.predictedHomeScore()) ?? '');
    this.second = String((reverse ? pick.predictedHomeScore() : pick.predictedAwayScore()) ?? '');
    this.editing = pick.predictedHomeScore() == null || pick.predictedAwayScore() == null;
  }

  view() {
    const { pick, reverse, firstTeam, secondTeam, isLoading, onSave } = this.attrs;
    if (!this.editing) {
      return (
        <div className="EventCard-predictedScore">
          <div>
            {app.translator.trans('huseyinfiliz-pickem.forum.picks.exact_score')}: {reverse ? pick.predictedAwayScore() : pick.predictedHomeScore()} -{' '}
            {reverse ? pick.predictedHomeScore() : pick.predictedAwayScore()}
          </div>
          <Button className="Button" onclick={() => (this.editing = true)}>
            {app.translator.trans('huseyinfiliz-pickem.forum.picks.edit_score')}
          </Button>
        </div>
      );
    }

    return (
      <form
        className="EventCard-predictedScore"
        onsubmit={(e: SubmitEvent) => {
          e.preventDefault();
          const first = Number(this.first);
          const second = Number(this.second);
          if (!this.first || !this.second || !Number.isSafeInteger(first) || !Number.isSafeInteger(second) || first < 0 || second < 0) {
            app.alerts.show({ type: 'error' }, app.translator.trans('huseyinfiliz-pickem.lib.messages.invalid_predicted_scores'));
            return;
          }
          onSave(reverse ? second : first, reverse ? first : second).then((saved) => {
            if (saved) {
              this.editing = false;
              m.redraw();
            }
          });
        }}
      >
        <div>{app.translator.trans('huseyinfiliz-pickem.forum.picks.exact_score')}</div>
        <div className="EventCard-predictedScore-inputs">
          <label>
            {firstTeam}
            <input
              className="FormControl"
              type="number"
              min="0"
              max="2147483647"
              step="1"
              disabled={isLoading}
              value={this.first}
              oninput={(e: InputEvent) => (this.first = (e.target as HTMLInputElement).value)}
            />
          </label>
          <label>
            {secondTeam}
            <input
              className="FormControl"
              type="number"
              min="0"
              max="2147483647"
              step="1"
              disabled={isLoading}
              value={this.second}
              oninput={(e: InputEvent) => (this.second = (e.target as HTMLInputElement).value)}
            />
          </label>
        </div>
        <div className="EventCard-predictedScore-actions">
          <Button type="submit" className="Button Button--primary" disabled={isLoading} loading={isLoading}>
            {app.translator.trans('huseyinfiliz-pickem.forum.picks.save_score')}
          </Button>
          {pick.predictedHomeScore() != null && (
            <Button
              className="Button"
              disabled={isLoading}
              onclick={() =>
                onSave(null, null).then((saved) => {
                  if (saved) {
                    this.first = '';
                    this.second = '';
                    m.redraw();
                  }
                })
              }
            >
              {app.translator.trans('huseyinfiliz-pickem.forum.picks.clear_score')}
            </Button>
          )}
        </div>
      </form>
    );
  }
}
