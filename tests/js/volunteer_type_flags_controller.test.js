import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { Application } from '@hotwired/stimulus';
import VolunteerTypeFlagsController from '../../assets/controllers/volunteer_type_flags_controller.js';

/*
 * The flag interdependencies are validated server-side; this keeps the checkboxes from showing a
 * combination the server will refuse. "Department only" is the one that can hide a type from the
 * people who need it, so it must be unreachable while the type is global or non-staff.
 */

function markup({ staff = true, global = false, departmentOnly = false } = {}) {
    return `
        <div data-controller="volunteer-type-flags">
            <input type="checkbox" data-volunteer-type-flags-target="staffOnly"
                   data-action="change->volunteer-type-flags#sync" ${staff ? 'checked' : ''}>
            <input type="checkbox" data-volunteer-type-flags-target="departmentOnly" ${departmentOnly ? 'checked' : ''}>
            <input type="checkbox" data-volunteer-type-flags-target="hideOnShiftView">
            <input type="checkbox" data-volunteer-type-flags-target="showOnDashboard">
            <input type="checkbox" data-volunteer-type-flags-target="global"
                   data-action="change->volunteer-type-flags#sync" ${global ? 'checked' : ''}>
        </div>`;
}

let application;

async function start(options) {
    document.body.innerHTML = markup(options);
    application = Application.start();
    application.register('volunteer-type-flags', VolunteerTypeFlagsController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const field = (name) => document.querySelector(`[data-volunteer-type-flags-target="${name}"]`);

afterEach(() => {
    application?.stop();
    document.body.innerHTML = '';
});

describe('volunteer type flags', () => {
    it('locks department-only off while the type is global', async () => {
        await start({ staff: true, global: true, departmentOnly: true });

        expect(field('departmentOnly').checked).toBe(false);
        expect(field('departmentOnly').disabled).toBe(true);
    });

    it('offers department-only again when global is turned off', async () => {
        await start({ staff: true, global: true });

        field('global').checked = false;
        field('global').dispatchEvent(new Event('change'));

        expect(field('departmentOnly').disabled).toBe(false);
    });

    it('still locks department-only for a non-staff type', async () => {
        await start({ staff: false });

        expect(field('departmentOnly').disabled).toBe(true);
        expect(field('showOnDashboard').checked).toBe(true);
        expect(field('hideOnShiftView').checked).toBe(false);
    });

    it('keeps the staff-only defaults', async () => {
        await start({ staff: true });

        expect(field('hideOnShiftView').checked).toBe(true);
        expect(field('showOnDashboard').checked).toBe(false);
        expect(field('departmentOnly').disabled).toBe(false);
    });
});
