import { Controller } from '@hotwired/stimulus';

/*
 * A STATION BAND FOLDS, AND THE FOLD IS THIS PERSON'S.
 *
 * The head is a row because a `<details>` cannot wrap `<tr>`s and the day
 * columns have to stay in one grid across every station. Open is the default
 * and closed is what is remembered, so a station added to the area tomorrow
 * is open tomorrow rather than hidden by a preference written before it
 * existed.
 *
 * THE FOLD HAPPENS HERE AND IS ONLY THEN REMEMBERED. Waiting for the server
 * would make folding one station of twelve feel like a page load; the post
 * answers 204 and nothing on screen depends on it.
 *
 * A FOLDED STATION STILL STATES ITS UNFILLED DAYS — that is markup, not this
 * controller's doing, and it is why folding can never hide a gap.
 */
export default class extends Controller {
    static values = { url: String, token: String };

    toggle(event) {
        const head = event.target.closest('.stfold');
        if (!head) {
            return;
        }

        this.apply(head, !head.classList.contains('shut'));
        this.remember();
    }

    foldAll() {
        this.heads().forEach((head) => this.apply(head, true));
        this.remember();
    }

    openAll() {
        this.heads().forEach((head) => this.apply(head, false));
        this.remember();
    }

    heads() {
        return Array.from(this.element.querySelectorAll('.stfold'));
    }

    apply(head, shut) {
        head.classList.toggle('shut', shut);
        head.classList.toggle('open', !shut);

        const button = head.querySelector('.stfoldb');
        if (button) {
            button.setAttribute('aria-expanded', shut ? 'false' : 'true');
        }

        this.element
            .querySelectorAll(`.strow[data-of="${CSS.escape(head.dataset.fold)}"]`)
            .forEach((row) => { row.hidden = shut; });
    }

    remember() {
        if (!this.hasUrlValue) {
            return;
        }

        const body = new FormData();
        body.append('_token', this.tokenValue);
        this.heads()
            .filter((head) => head.classList.contains('shut'))
            .forEach((head) => body.append('folded[]', head.dataset.fold));

        // An empty set still has to be sent, or "open them all" would never
        // be remembered — so the field is always present, even when nothing
        // is folded.
        if (!body.has('folded[]')) {
            body.append('folded[]', '');
        }

        fetch(this.urlValue, { method: 'POST', body, credentials: 'same-origin' }).catch(() => {});
    }
}
