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
 * AMD module for the SmartSection Control manage page.
 *
 * Show/hide rule-specific fields inside accordion rows.
 * Does not intercept Save submission.
 *
 * @module     block_smartsection_control/manage
 * @copyright  2026 M. AFZAL RIAZ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
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

    return {
        init: function() {
            document.querySelectorAll('[id^="unlocktype_"]').forEach(function(select) {
                var sectionId = select.id.replace('unlocktype_', '');
                select.addEventListener('change', function() {
                    updateVisibility(select, sectionId);
                });
                updateVisibility(select, sectionId);
            });
        }
    };
});
