// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Drag and drop of the elements of a transcript layout.
 *
 * @module     local_transcript/designer
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

const MM_PER_POINT = 0.352778;

/**
 * Make the font sizes of text elements follow the size of the picture of the page.
 *
 * @param {HTMLElement} canvas
 */
const scaleFonts = (canvas) => {
    const scale = canvas.clientWidth / parseFloat(canvas.dataset.pagewidth);
    canvas.querySelectorAll('.lt-el[data-fontsize]').forEach((el) => {
        el.style.fontSize = (parseFloat(el.dataset.fontsize) * MM_PER_POINT * scale) + 'px';
    });
};

/**
 * Round to half a millimetre.
 *
 * @param {Number} value
 * @returns {Number}
 */
const round = (value) => Math.round(value * 2) / 2;

/**
 * Let one element be dragged.
 *
 * @param {HTMLElement} canvas
 * @param {HTMLElement} el
 */
const makeDraggable = (canvas, el) => {
    // The same element in the other courses of the picture follows.
    const followers = () => document.querySelectorAll('.lt-el[data-followid="' + el.dataset.id + '"]');
    const place = (left, top) => {
        el.style.left = left;
        el.style.top = top;
        followers().forEach((follower) => {
            follower.style.left = left;
            follower.style.top = top;
        });
    };
    el.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        const scale = canvas.clientWidth / parseFloat(canvas.dataset.pagewidth);
        const startX = event.clientX;
        const startY = event.clientY;
        const originX = parseFloat(el.dataset.posx);
        const originY = parseFloat(el.dataset.posy);
        const regionTop = parseFloat(el.dataset.regiontop);
        const regionHeight = parseFloat(el.dataset.regionheight);
        const pageWidth = parseFloat(canvas.dataset.pagewidth);
        const pageHeight = parseFloat(canvas.dataset.sectionheight);
        const width = parseFloat(el.dataset.width);
        let moved = false;
        let posx = originX;
        let posy = originY;

        el.setPointerCapture(event.pointerId);
        el.classList.add('lt-dragging');

        const onMove = (moveEvent) => {
            const dx = moveEvent.clientX - startX;
            const dy = moveEvent.clientY - startY;
            if (Math.abs(dx) + Math.abs(dy) > 3) {
                moved = true;
            }
            posx = round(Math.max(0, Math.min(pageWidth - Math.min(width, pageWidth), originX + dx / scale)));
            posy = round(Math.max(0, Math.min(Math.max(0, regionHeight - 1), originY + dy / scale)));
            place((posx / pageWidth * 100) + '%', ((regionTop + posy) / pageHeight * 100) + '%');
        };

        const onUp = () => {
            el.removeEventListener('pointermove', onMove);
            el.removeEventListener('pointerup', onUp);
            el.removeEventListener('pointercancel', onUp);
            el.classList.remove('lt-dragging');
            if (!moved) {
                window.location.href = el.dataset.editurl;
                return;
            }
            Ajax.call([{
                methodname: 'local_transcript_set_element_position',
                args: {id: parseInt(el.dataset.id, 10), posx: posx, posy: posy},
            }])[0].then((result) => {
                el.dataset.posx = result.posx;
                el.dataset.posy = result.posy;
                const row = document.querySelector('[data-position-for="' + el.dataset.id + '"]');
                if (row) {
                    row.textContent = result.posx + ' / ' + result.posy;
                }
                return result;
            }).catch((error) => {
                // Put the element back where it was.
                place((originX / pageWidth * 100) + '%', ((regionTop + originY) / pageHeight * 100) + '%');
                Notification.exception(error);
            });
        };

        el.addEventListener('pointermove', onMove);
        el.addEventListener('pointerup', onUp);
        el.addEventListener('pointercancel', onUp);
    });
};

/**
 * Start the designer.
 */
export const init = () => {
    document.querySelectorAll('.lt-canvas').forEach((canvas) => {
        scaleFonts(canvas);
        window.addEventListener('resize', () => scaleFonts(canvas));
        canvas.querySelectorAll('.lt-el:not([data-followid])').forEach((el) => makeDraggable(canvas, el));
    });
};
