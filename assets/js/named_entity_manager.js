function namedEntityManager(entity, csrfToken, i18n) {
  return {
    entity: entity,
    csrfToken: csrfToken,
    i18n: i18n,

    modalOpen: false,
    mode: 'add',
    saving: false,
    formError: '',
    form: { id: null, name: '' },
    busy: false,
    syncing: false,
    syncOpen: false,
    syncPhase: 'confirm',
    syncResult: null,
    syncError: '',

    openAdd() {
      this.mode = 'add';
      this.form = { id: null, name: '' };
      this.formError = '';
      this.modalOpen = true;
    },

    openEdit(id, name) {
      this.mode = 'edit';
      this.form = { id: id, name: name };
      this.formError = '';
      this.modalOpen = true;
    },

    closeModal() {
      this.modalOpen = false;
    },

    async callApi(action, payload) {
      const res = await fetch(`../api/master_data_api.php?entity=${this.entity}&action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
        body: JSON.stringify(payload),
      });
      return res.json();
    },

    async submitForm() {
      this.formError = '';
      if (!this.form.name.trim()) {
        this.formError = this.i18n.invalid_input;
        return;
      }
      this.saving = true;
      try {
        const action = this.mode === 'add' ? 'create' : 'update';
        const data = await this.callApi(action, this.form);
        if (!data.success) {
          this.formError = this.i18n[data.message] || data.message;
          this.saving = false;
          return;
        }
        window.location.reload();
      } catch (e) {
        this.formError = this.i18n.server_error;
        this.saving = false;
      }
    },

    // ปุ่ม "Sync จาก API" (ใช้เฉพาะหน้า customers)
    // syncPhase: 'confirm' -> 'syncing' -> 'done' | 'error'
    openSync() {
      if (this.syncing) return;
      this.syncPhase = 'confirm';
      this.syncResult = null;
      this.syncError = '';
      this.syncOpen = true;
    },

    closeSync() {
      if (this.syncPhase === 'syncing') return;
      if (this.syncPhase === 'done') {
        window.location.reload();
        return;
      }
      this.syncOpen = false;
    },

    async startSync() {
      if (this.syncing) return;
      this.syncing = true;
      this.syncPhase = 'syncing';
      try {
        const data = await this.callApi('sync', {});
        if (!data.success) {
          const base = this.i18n[data.message] || data.message;
          this.syncError = data.detail ? base + '\n' + data.detail : base;
          this.syncPhase = 'error';
          return;
        }
        this.syncResult = data.result;
        this.syncPhase = 'done';
      } catch (e) {
        this.syncError = this.i18n.server_error;
        this.syncPhase = 'error';
      } finally {
        this.syncing = false;
      }
    },

    async toggleActive(id, currentlyActive) {
      if (this.busy) return;
      this.busy = true;
      try {
        const action = currentlyActive ? 'deactivate' : 'activate';
        const data = await this.callApi(action, { id });
        if (data.success) {
          window.location.reload();
        } else {
          alert(this.i18n[data.message] || data.message);
        }
      } catch (e) {
        alert(this.i18n.server_error);
      } finally {
        this.busy = false;
      }
    },

    async remove(id) {
      if (this.busy || !confirm(this.i18n.confirm_delete)) return;
      this.busy = true;
      try {
        const data = await this.callApi('delete', { id });
        if (data.success) {
          window.location.reload();
          return;
        }
        if (data.message === 'in_use') {
          if (confirm(this.i18n.confirm_deactivate_instead)) {
            const data2 = await this.callApi('deactivate', { id });
            if (data2.success) {
              window.location.reload();
            } else {
              alert(this.i18n[data2.message] || data2.message);
            }
          }
          return;
        }
        alert(this.i18n[data.message] || data.message);
      } catch (e) {
        alert(this.i18n.server_error);
      } finally {
        this.busy = false;
      }
    },
  };
}
