function managerReview(config) {
  return {
    checklistId: config.checklistId,
    csrfToken: config.csrfToken,
    i18n: config.i18n,
    redirectUrl: config.redirectUrl,

    rejecting: false,
    rejectNote: '',
    submitting: false,
    errorMsg: '',

    startReject() {
      this.errorMsg = '';
      this.rejecting = true;
    },
    cancelReject() {
      this.rejecting = false;
      this.rejectNote = '';
      this.errorMsg = '';
    },

    async decide(action, note) {
      this.errorMsg = '';
      this.submitting = true;
      try {
        const res = await fetch('../api/approval_api.php?action=' + action, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
          body: JSON.stringify({ id: this.checklistId, note: note || '' }),
        });
        const data = await res.json();
        if (!data.success) {
          this.errorMsg = this.i18n[data.message] || data.message;
          this.submitting = false;
          return;
        }
        window.location.href = this.redirectUrl;
      } catch (e) {
        this.errorMsg = this.i18n.server_error;
        this.submitting = false;
      }
    },

    approve() {
      if (!window.confirm(this.i18n.confirm_approve)) return;
      this.decide('approve', '');
    },

    reject() {
      if (!this.rejectNote.trim()) {
        this.errorMsg = this.i18n.reject_reason_required;
        return;
      }
      this.decide('reject', this.rejectNote.trim());
    },
  };
}
