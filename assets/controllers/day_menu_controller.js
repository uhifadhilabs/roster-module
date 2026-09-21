import { Controller } from '@hotwired/stimulus';

/*
 * THE BY-HAND MENU ON ONE DAY.
 *
 * One open at a time, clicking the day again closes it, and clicking
 * anywhere else closes whichever is open — the ordinary behaviour of a menu,
 * written out because there is no menu element that does it.
 */
export default class extends Controller {
    connect() {
        this.dismiss = (event) => {
            if (event.target.closest('.pmenu') || event.target.closest('[data-action*="day-menu#open"]')) {
                return;
            }

            this.closeAll();
        };

        document.addEventListener('click', this.dismiss);
    }

    disconnect() {
        document.removeEventListener('click', this.dismiss);
    }

    open(event) {
        const wrap = event.target.closest('.pmenuwrap');
        if (!wrap) {
            return;
        }

        const wasOpen = wrap.classList.contains('on');
        this.closeAll();
        wrap.classList.toggle('on', !wasOpen);
    }

    closeAll() {
        this.element.querySelectorAll('.pmenuwrap.on').forEach((wrap) => wrap.classList.remove('on'));
    }
}
