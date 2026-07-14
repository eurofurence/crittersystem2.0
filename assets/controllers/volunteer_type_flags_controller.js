import { Controller } from '@hotwired/stimulus';

/*
 * Mirrors the volunteer-type flag interdependencies in the UI. The backend
 * still validates; this only keeps the checkboxes coherent as the admin edits.
 *   - Staff only OFF -> Department only OFF, Hide-on-shift-view OFF, Show-on-dashboard ON.
 *   - Staff only ON  -> Hide-on-shift-view ON, Show-on-dashboard OFF.
 *   - Department only ON requires Staff only ON.
 */
export default class extends Controller {
    static targets = ['staffOnly', 'departmentOnly', 'hideOnShiftView', 'showOnDashboard'];

    connect() {
        this.sync();
    }

    sync() {
        const staff = this.staffOnlyTarget.checked;
        if (!staff) {
            this.set(this.departmentOnlyTarget, false);
            this.set(this.hideOnShiftViewTarget, false);
            this.set(this.showOnDashboardTarget, true);
            this.departmentOnlyTarget.disabled = true;
        } else {
            this.set(this.hideOnShiftViewTarget, true);
            this.set(this.showOnDashboardTarget, false);
            this.departmentOnlyTarget.disabled = false;
        }
    }

    set(checkbox, value) {
        if (checkbox) {
            checkbox.checked = value;
        }
    }
}
