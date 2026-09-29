/**
 * Admin > Master Data > ผู้อนุมัติ.
 *
 * Deliberately not namedEntityManager: an approver row has no name of its own
 * to add or edit — it points at a users row — so "add" picks an existing
 * Manager user and there is no edit at all.
 */
function approverManager(csrfToken, i18n) {
  return {
    csrfToken: csrfToken,
    i18n: i18n,

    newUserId: 0,
    busy: false,
    formError: '',

    async callApi(action, payload) {
      const res = await fetch(`../api/master_data_api.php?entity=approvers&action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
        body: JSON.stringify(payload),
      });
      return res.json();
    },

    // Every action reloads the page afterwards: the "add" picker lists only
    // users not already on the list, so it has to be rebuilt server-side.
    async run(action, payload) {
      if (this.busy) return false;
      this.busy = true;
      this.formError = '';
      try {
        const data = await this.callApi(action, payload);
        if (!data.success) {
          this.formError = this.i18n[data.message] || data.message;
          return false;
        }
        window.location.reload();
        return true;
      } catch (e) {
        this.formError = this.i18n.server_error;
        return false;
      } finally {
        this.busy = false;
      }
    },

    add() {
      if (!this.newUserId) return;
      return this.run('create', { user_id: this.newUserId });
    },

    toggleActive(id, isActive) {
      return this.run(isActive ? 'deactivate' : 'activate', { id: id });
    },

    async remove(id) {
      if (!window.confirm(this.i18n.confirm_delete)) return;
      this.busy = true;
      this.formError = '';
      try {
        const data = await this.callApi('delete', { id: id });
        if (data.success) {
          window.location.reload();
          return;
        }
        // Still referenced by checklists: offer the same "deactivate instead"
        // escape hatch the other master-data pages give.
        if (data.message === 'in_use') {
          if (window.confirm(this.i18n.in_use + '\n\n' + this.i18n.confirm_deactivate_instead)) {
            this.busy = false;
            await this.toggleActive(id, 1);
            return;
          }
          return;
        }
        this.formError = this.i18n[data.message] || data.message;
      } catch (e) {
        this.formError = this.i18n.server_error;
      } finally {
        this.busy = false;
      }
    },
  };
}
