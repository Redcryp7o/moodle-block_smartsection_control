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
 * AMD module: SmartSection Control — Soft Lock teaser presentation.
 *
 * Applies the ssc-softlock class to the course-view elements belonging to
 * sections that are Soft Locked for the current user. The teaser appearance
 * itself is declared statically in the plugin styles.css, so this module only
 * toggles a class and never injects a stylesheet or inline styles.
 *
 * Access control is enforced server-side by Moodle section availability; this
 * module is presentation only.
 *
 * @module     block_smartsection_control/softlock
 * @copyright  2026 M. AFZAL RIAZ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    /** @var {string} Class that carries the Soft Lock teaser appearance. */
    var TEASER_CLASS = 'ssc-softlock';

    /**
     * Build the list of course-view selectors that represent one section.
     *
     * Mirrors the selector set the server previously emitted as a stylesheet, so
     * the affected elements are unchanged across supported course formats.
     *
     * @param {Object} section Section descriptor with sectionid and sectionnum.
     * @return {string[]} CSS selectors for this section.
     */
    function selectorsFor(section) {
        var sectionnum = parseInt(section.sectionnum, 10);
        var sectionid = parseInt(section.sectionid, 10);
        var selectors = [];

        if (!isNaN(sectionnum)) {
            selectors.push('#section-' + sectionnum);
            selectors.push('li#section-' + sectionnum);
            selectors.push('[data-sectionreturnid="' + sectionnum + '"]');
        }
        if (!isNaN(sectionid)) {
            selectors.push('[data-sectionid="' + sectionid + '"]');
        }

        return selectors;
    }

    /**
     * Tag every element belonging to the supplied sections as Soft Locked.
     *
     * @param {Array} sections Section descriptors provided by the server.
     */
    function applyTeaser(sections) {
        sections.forEach(function(section) {
            selectorsFor(section).forEach(function(selector) {
                document.querySelectorAll(selector).forEach(function(element) {
                    element.classList.add(TEASER_CLASS);
                });
            });
        });
    }

    return {
        /**
         * Initialise the Soft Lock teaser for the current course page.
         *
         * @param {Array} sections Section descriptors with sectionid and sectionnum.
         */
        init: function(sections) {
            if (!Array.isArray(sections) || sections.length === 0) {
                return;
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    applyTeaser(sections);
                });
                return;
            }

            applyTeaser(sections);
        }
    };
});
