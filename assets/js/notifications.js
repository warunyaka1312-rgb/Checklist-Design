function notificationCenter(config) {
  return {
    csrfToken: config.csrfToken,
    apiUrl: config.apiUrl,
    notifOpen: false,
    unreadCount: config.initialUnreadCount || 0,
    items: config.initialItems || [],

    init() {
      setInterval(() => this.refreshUnreadCount(), 30000);
    },

    toggle() {
      this.notifOpen = !this.notifOpen;
      if (this.notifOpen) {
        this.loadList();
      }
    },

    async loadList() {
      try {
        const res = await fetch(this.apiUrl + '?action=list');
        const data = await res.json();
        if (data.success) {
          this.items = data.items;
          this.unreadCount = data.unread_count;
        }
      } catch (e) {
        // silent: notification list is non-critical
      }
    },

    async refreshUnreadCount() {
      try {
        const res = await fetch(this.apiUrl + '?action=unread_count');
        const data = await res.json();
        if (data.success) {
          this.unreadCount = data.unread_count;
        }
      } catch (e) {
        // silent
      }
    },

    onClickItem(n, event) {
      if (!n.link) {
        event.preventDefault();
      }
      if (!n.is_read) {
        n.is_read = 1;
        this.unreadCount = Math.max(0, this.unreadCount - 1);
        fetch(this.apiUrl + '?action=mark_read', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
          body: JSON.stringify({ id: n.id }),
        }).catch(() => {});
      }
    },

    async markAllRead() {
      this.items.forEach((n) => { n.is_read = 1; });
      this.unreadCount = 0;
      try {
        await fetch(this.apiUrl + '?action=mark_all_read', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
        });
      } catch (e) {
        // silent
      }
    },

    async markAllReadAndDelete() {
      this.items = [];
      this.unreadCount = 0;
      try {
        await fetch(this.apiUrl + '?action=delete_all', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
        });
      } catch (e) {
        // silent
      }
    },
  };
}
