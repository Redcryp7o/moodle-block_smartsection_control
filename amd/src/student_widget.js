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
 * AMD module: SmartSection Control — student upcoming releases widget.
 *
 * Manages live countdown timers and smooth card rotation as sections
 * reach their scheduled release time.
 *
 * Timer strategy:
 *   - Items with < 24h remaining: update every second.
 *   - Items with >= 24h remaining: update every 60 seconds (minute-level
 *     accuracy is sufficient and avoids unnecessary DOM work).
 *
 * Accessibility:
 *   - The countdown <span> is updated visually every tick but has NO
 *     aria-live, preventing constant screen reader announcements.
 *   - The widget's .ssc-upcoming__announce region (aria-live="polite") is
 *     spoken ONLY on meaningful transitions (section becomes available).
 *
 * @module     block_smartsection_control/student_widget
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    var SECS_PER_DAY  = 86400;
    var SECS_PER_HOUR = 3600;
    var SECS_PER_MIN  = 60;

    /**
     * Format remaining seconds into compact countdown text.
     *
     * > 1 day : "2d 10h 29m"
     * < 1 day : "10h 29m 14s"
     * < 1 hour: "29m 14s"
     * < 1 min : "14s"
     *
     * @param {number} totalSeconds  Non-negative integer seconds.
     * @return {string}
     */
    function formatCountdown(totalSeconds) {
        if (totalSeconds <= 0) {
            return '0s';
        }

        var days  = Math.floor(totalSeconds / SECS_PER_DAY);
        var hours = Math.floor((totalSeconds % SECS_PER_DAY) / SECS_PER_HOUR);
        var mins  = Math.floor((totalSeconds % SECS_PER_HOUR) / SECS_PER_MIN);
        var secs  = totalSeconds % SECS_PER_MIN;

        var pad = function(n) { return n < 10 ? '0' + n : '' + n; };

        if (days >= 1) {
            return days + 'd ' + pad(hours) + 'h ' + pad(mins) + 'm';
        }
        if (hours >= 1) {
            return pad(hours) + 'h ' + pad(mins) + 'm ' + pad(secs) + 's';
        }
        if (mins >= 1) {
            return pad(mins) + 'm ' + pad(secs) + 's';
        }
        return secs + 's';
    }

    /**
     * XSS-safe HTML escape helper.
     *
     * @param {*} str
     * @return {string}
     */
    function esc(str) {
        if (!str) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Build the inner HTML string for a queued item's <li>.
     *
     * @param {Object} item       Queued section data object.
     * @param {string} nextLabel  Translated "Next" badge label.
     * @param {boolean} isNext    Whether this item is the new first (imminent).
     * @return {string}
     */
    function buildItemHtml(item, nextLabel, isNext) {
        var diff      = Math.max(0, item.unlocktime - Math.floor(Date.now() / 1000));
        var isSoon    = diff > 0 && diff < SECS_PER_DAY;
        var countdown = formatCountdown(diff);

        var badgeHtml = isNext
            ? '<span class="ssc-upcoming__next-badge" aria-label="' + esc(nextLabel) + '">' + esc(nextLabel) + '</span>'
            : '';

        var courseHtml = item.coursename
            ? '<div class="ssc-upcoming__row ssc-upcoming__row--date">'
              + '<i class="fa fa-graduation-cap ssc-upcoming__icon" aria-hidden="true"></i>'
              + '<span class="ssc-upcoming__date">' + esc(item.coursename) + '</span></div>'
            : '';

        var unlockLabel = item.unlocksinlabel || '';
        var ariaLabel   = unlockLabel ? unlockLabel + ': ' + countdown : countdown;

        return '<div class="ssc-upcoming__row ssc-upcoming__row--title">'
            + '<span class="ssc-upcoming__section-name">' + esc(item.sectionname) + '</span>'
            + badgeHtml
            + '</div>'
            + '<div class="ssc-upcoming__row ssc-upcoming__row--date">'
            + '<i class="fa fa-calendar-o ssc-upcoming__icon" aria-hidden="true"></i>'
            + '<span class="ssc-upcoming__date">' + esc(item.unlocktimeformatted) + '</span>'
            + '</div>'
            + courseHtml
            + '<div class="ssc-upcoming__countdown" aria-label="' + esc(ariaLabel) + '">'
            + '<i class="fa fa-clock-o ssc-upcoming__icon ssc-upcoming__icon--clock" aria-hidden="true"></i>'
            + '<span class="ssc-upcoming__timer" data-unlocktime="' + item.unlocktime + '"'
            + (isSoon ? ' data-issoon="1"' : '') + '>'
            + esc(countdown)
            + '</span>'
            + '</div>';
    }

    /**
     * Initialise the widget for one container element.
     *
     * @param {string} rootId  ID of the .ssc-upcoming container element.
     */
    function init(rootId) {
        var container = document.getElementById(rootId);
        if (!container) {
            return;
        }

        var listEl    = container.querySelector('.ssc-upcoming__list');
        var emptyEl   = container.querySelector('.ssc-upcoming__empty');
        var announceEl = container.querySelector('.ssc-upcoming__announce');
        var nextLabel  = container.getAttribute('data-next-label') || 'Next';
        var queue      = [];

        try {
            var raw = container.getAttribute('data-queued');
            if (raw) {
                queue = JSON.parse(raw);
            }
        } catch (e) {
            queue = [];
        }

        // Tick intervals.
        var secondInterval = null;
        var minuteInterval = null;

        // ──────────────────────────────────────────────────────────────────────
        // Timer logic
        // ──────────────────────────────────────────────────────────────────────

        /**
         * Whether this widget's container is still in the live DOM.
         *
         * @return {boolean}
         */
        function isAlive() {
            return container && document.body.contains(container);
        }

        /**
         * Update all visible countdown timers for a given set of items.
         *
         * @param {NodeList} timers  All .ssc-upcoming__timer elements to update.
         */
        function tickTimers(timers) {
            if (!isAlive()) {
                stopAll();
                return;
            }

            var now     = Math.floor(Date.now() / 1000);
            var expired = [];

            timers.forEach(function(timerEl) {
                var unlockTime = parseInt(timerEl.getAttribute('data-unlocktime'), 10);
                if (isNaN(unlockTime)) {
                    return;
                }
                var diff = unlockTime - now;
                if (diff <= 0) {
                    var li = timerEl.closest('.ssc-upcoming__item');
                    if (li && expired.indexOf(li) === -1) {
                        expired.push(li);
                    }
                } else {
                    timerEl.textContent = formatCountdown(diff);
                    // Update issoon state on the parent item.
                    var item = timerEl.closest('.ssc-upcoming__item');
                    if (item) {
                        if (diff < SECS_PER_DAY) {
                            item.classList.add('ssc-upcoming__item--soon');
                        } else {
                            item.classList.remove('ssc-upcoming__item--soon');
                        }
                    }
                }
            });

            expired.forEach(function(li) { expireItem(li); });
        }

        /**
         * Second-level tick: only updates items that are < 24h away.
         */
        function secondTick() {
            if (!isAlive()) { stopAll(); return; }
            var timers = container.querySelectorAll(
                '.ssc-upcoming__item--soon .ssc-upcoming__timer, '
                + '.ssc-upcoming__item--next .ssc-upcoming__timer'
            );
            // Also pick up the overall nearest item even if --soon not yet set.
            var allTimers = container.querySelectorAll('.ssc-upcoming__timer');
            tickTimers(allTimers.length ? allTimers : timers);
        }

        /**
         * Minute-level tick: updates distant items (>= 24h).
         */
        function minuteTick() {
            if (!isAlive()) { stopAll(); return; }
            var timers = container.querySelectorAll('.ssc-upcoming__timer');
            tickTimers(timers);
        }

        function stopAll() {
            if (secondInterval) { clearInterval(secondInterval); secondInterval = null; }
            if (minuteInterval) { clearInterval(minuteInterval); minuteInterval = null; }
        }

        // ──────────────────────────────────────────────────────────────────────
        // Card transitions
        // ──────────────────────────────────────────────────────────────────────

        /**
         * Handle a section whose countdown has reached zero.
         *
         * @param {Element} li  The .ssc-upcoming__item that expired.
         */
        function expireItem(li) {
            if (li.classList.contains('ssc-upcoming__item--exiting')) {
                return; // Already handling.
            }

            // Announce to screen readers.
            var nameEl = li.querySelector('.ssc-upcoming__section-name');
            var availableLabel = container.getAttribute('data-available-label') || 'Available now';
            if (announceEl && nameEl) {
                announceEl.textContent = nameEl.textContent + ' — ' + availableLabel;
            }

            li.classList.add('ssc-upcoming__item--exiting');

            setTimeout(function() {
                if (li.parentNode) {
                    li.parentNode.removeChild(li);
                }

                // Rotate in the next queued item.
                if (queue.length > 0) {
                    var next = queue.shift();
                    appendQueuedItem(next);
                }

                refreshIndices();
                checkEmpty();
            }, 200);
        }

        /**
         * Append a queued section item as the new last visible card.
         *
         * @param {Object} item  Queued section data object.
         */
        function appendQueuedItem(item) {
            if (!listEl || !item) {
                return;
            }
            var li = document.createElement('li');
            li.className = 'ssc-upcoming__item ssc-upcoming__item--entering';
            li.setAttribute('data-sectionid', item.sectionid);
            li.setAttribute('role', 'listitem');
            li.innerHTML = buildItemHtml(item, nextLabel, false);
            listEl.appendChild(li);
        }

        /**
         * Recalculate which item is --next and update NEXT badges.
         */
        function refreshIndices() {
            if (!listEl) {
                return;
            }
            var items = listEl.querySelectorAll('.ssc-upcoming__item');
            items.forEach(function(item, idx) {
                if (idx === 0) {
                    item.classList.add('ssc-upcoming__item--next');
                    // Inject NEXT badge if absent.
                    var titleRow = item.querySelector('.ssc-upcoming__row--title');
                    if (titleRow && !item.querySelector('.ssc-upcoming__next-badge')) {
                        var badge = document.createElement('span');
                        badge.className = 'ssc-upcoming__next-badge';
                        badge.setAttribute('aria-label', nextLabel);
                        badge.textContent = nextLabel;
                        titleRow.appendChild(badge);
                    }
                } else {
                    item.classList.remove('ssc-upcoming__item--next');
                    var oldBadge = item.querySelector('.ssc-upcoming__next-badge');
                    if (oldBadge && oldBadge.parentNode) {
                        oldBadge.parentNode.removeChild(oldBadge);
                    }
                }
            });
        }

        /**
         * Show empty state when no items remain.
         */
        function checkEmpty() {
            if (!listEl || !emptyEl) {
                return;
            }
            var remaining = listEl.querySelectorAll('.ssc-upcoming__item');
            if (remaining.length === 0) {
                listEl.style.display = 'none';
                emptyEl.classList.remove('ssc-upcoming__empty--hidden');
                emptyEl.classList.add('ssc-upcoming__empty--show');
            }
        }

        // ──────────────────────────────────────────────────────────────────────
        // Bootstrap
        // ──────────────────────────────────────────────────────────────────────

        // Initial render: check issoon for all visible items on load.
        var now = Math.floor(Date.now() / 1000);
        container.querySelectorAll('.ssc-upcoming__timer').forEach(function(timerEl) {
            var unlockTime = parseInt(timerEl.getAttribute('data-unlocktime'), 10);
            if (isNaN(unlockTime)) { return; }
            var diff = unlockTime - now;
            if (diff > 0 && diff < SECS_PER_DAY) {
                var item = timerEl.closest('.ssc-upcoming__item');
                if (item) { item.classList.add('ssc-upcoming__item--soon'); }
            }
        });

        // Second-level tick for items that are < 24h away.
        secondInterval = setInterval(secondTick, 1000);

        // Minute-level tick for all items (ensures distant ones stay accurate).
        minuteInterval = setInterval(minuteTick, 60000);

        // Run both immediately so display is correct on page load.
        secondTick();
    }

    return {
        init: init
    };
});
