// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AMD module for the SmartSection Control manage page.
 *
 * Show/hide rule-specific fields inside accordion rows, and confirm
 * high-impact submissions declared with data-ssc-confirm.
 * Does not intercept plain Save submission.
 *
 * @module     block_smartsection_control/manage
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/notification', 'core/str'], function(Notification, Str) {
    'use strict';

    /**
     * Update visibility and disabled state for a section row.
     *
     * @param {HTMLSelectElement} select The unlock-type select.
     * @param {string} sectionId Section database id.
     */
    function updateVisibility(select, sectionId) {
        var val = select.value;
        var showDate = val === 'absolute';
        var showRelative = val === 'relative';
        var showEvent = val === 'event';
        var showLocktype = val !== 'none' && val !== '';

        var dateWrapper = document.getElementById('datewrapper_' + sectionId);
        var relativeWrapper = document.getElementById('relativewrapper_' + sectionId);
        var eventWrapper = document.getElementById('eventwrapper_' + sectionId);
        var locktypeWrapper = document.getElementById('locktypewrapper_' + sectionId);
        var lockSelect = document.getElementById('locktype_' + sectionId);
        var eventNote = document.getElementById('lockeventnote_' + sectionId);
        var unlockBtn = document.querySelector('.ssc-unlock-now[data-sectionid="' + sectionId + '"]');
        var item = select.closest('.ssc-section-item');

        if (dateWrapper) {
            dateWrapper.style.display = showDate ? 'flex' : 'none';
        }
        if (relativeWrapper) {
            relativeWrapper.style.display = showRelative ? 'flex' : 'none';
        }
        if (eventWrapper) {
            eventWrapper.style.display = showEvent ? 'flex' : 'none';
        }
        if (locktypeWrapper) {
            locktypeWrapper.style.display = showLocktype ? 'flex' : 'none';
        }
        if (lockSelect) {
            lockSelect.disabled = !showLocktype;
            var softOption = lockSelect.querySelector('option[value="soft"]');
            if (showEvent) {
                lockSelect.value = 'hard';
                if (softOption) {
                    softOption.disabled = true;
                }
            } else if (softOption) {
                softOption.disabled = false;
            }
        }
        if (eventNote) {
            eventNote.style.display = showEvent ? 'block' : 'none';
        }
        if (unlockBtn) {
            unlockBtn.style.display = showLocktype ? '' : 'none';
        }
        if (item) {
            item.classList.toggle('is-configured', showLocktype);
            item.classList.toggle('is-unmanaged', !showLocktype);
        }
    }

    /**
     * Attach confirmation prompts to buttons carrying a data-ssc-confirm message.
     *
     * Replaces the inline onclick attributes previously emitted by manage.php.
     * The message text is a server-rendered language string held in the data
     * attribute, so no user-facing string is defined here.
     *
     * Uses Moodle's save/cancel modal instead of window.confirm(). On confirm,
     * the original click is replayed so submit buttons still post their name
     * and value. Cancel leaves the form unsubmitted.
     */
    function registerConfirmations() {
        document.querySelectorAll('[data-ssc-confirm]').forEach(function(trigger) {
            trigger.addEventListener('click', function(event) {
                var message = trigger.getAttribute('data-ssc-confirm');
                if (!message) {
                    return;
                }
                if (trigger.getAttribute('data-ssc-confirmed') === '1') {
                    trigger.removeAttribute('data-ssc-confirmed');
                    return;
                }
                event.preventDefault();
                Notification.saveCancelPromise(
                    Str.get_string('confirm', 'core'),
                    message,
                    Str.get_string('ok', 'core'),
                    {triggerElement: trigger}
                ).then(function() {
                    trigger.setAttribute('data-ssc-confirmed', '1');
                    trigger.click();
                    return;
                }).catch(function() {
                    return;
                });
            });
        });
    }

    return {
        init: function() {
            document.querySelectorAll('[id^="unlocktype_"]').forEach(function(select) {
                var sectionId = select.id.replace('unlocktype_', '');
                select.addEventListener('change', function() {
                    updateVisibility(select, sectionId);
                });
                updateVisibility(select, sectionId);
            });

            registerConfirmations();
        }
    };
});
