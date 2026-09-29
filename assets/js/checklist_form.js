function checklistForm(config) {
  return {
    mode: config.mode,
    checklistId: config.checklistId,
    csrfToken: config.csrfToken,
    i18n: config.i18n,
    models: config.models,
    customers: config.customers,
    materials: config.materials || [],
    tempers: config.tempers || [],
    managers: config.managers,
    items: config.items,
    readOnly: config.readOnly,
    previousStatus: config.initial.status || 'draft',

    step: 1,
    formError: '',
    savingDraft: false,
    submitting: false,

    dieNo: config.initial.die_no || '',
    tech: config.initial.tech || '',
    modelId: config.initial.model_id || null,
    customerId: config.initial.customer_id || null,
    dieType: config.initial.die_type || 'solid',
    // Seeds for the name-based selects below; not used after init().
    materialId: config.initial.material_id || null,
    temperId: config.initial.temper_id || null,
    materialName: '',
    temperName: '',
    managerId: config.initial.assigned_manager_id || null,

    selectedItemIds: (config.initial.selected_item_ids || []).slice(),
    results: JSON.parse(JSON.stringify(config.initial.results || {})),
    // Snapshot of the results as loaded, kept untouched, so an edit can be
    // compared against what was last saved (e.g. to flag a Pass -> Fail flip).
    originalResults: JSON.parse(JSON.stringify(config.initial.results || {})),
    customCounter: 0,

    pdfPath: config.initial.design_pdf_path || null,
    pdfUrl: config.initial.design_pdf_url || null,
    pdfUploading: false,
    pdfError: '',
    pdfModalOpen: false,

    // Design drawing picture: manual upload, used until the drawing-image
    // API is connected (same upload endpoint as per-item images).
    designImagePath: config.initial.design_image_path || null,
    designImageUrl: config.initial.design_image_url || null,
    designImageUploading: false,
    designImageError: config.imageFetchNotice || '',
    // ปุ่ม "ดึงจาก API" (รูปภาพแบบจากระบบภายนอก ตาม Tech) — แสดงเมื่อตั้งค่า .env ครบเท่านั้น
    imageApiEnabled: !!config.imageApiEnabled,
    designImageFetching: false,

    // Results table: which row (by result key) is expanded for editing. Only
    // one row expands at a time so the table stays scannable.
    expandedKey: null,

    // Master search bars for Model / Customer (replaces the old dropdown +
    // inline "add new" controls). Customer options come from the Master Data
    // pages only; Model additionally accepts a name that is not there yet
    // when allowNewModel is on — see that flag below.
    modelQuery: '',
    modelDropdownOpen: false,
    // When on (the "new checklist" page), a Model name that is not in
    // master data yet may be typed straight into the box: it is sent to the
    // save API as model_name and inserted into die_models there.
    allowNewModel: !!config.allowNewModel,
    customerQuery: '',
    customerDropdownOpen: false,
    // Same free entry as allowNewModel, for the Customer box: a name that is
    // not in master data yet is sent as customer_name and inserted into
    // customers by the save API.
    allowNewCustomer: !!config.allowNewCustomer,
    // When set, typing in the Customer box searches the SAP Customer API by
    // CardName through this endpoint instead of only filtering the customers
    // already in our table. Unset (e.g. on checklist_view.php) keeps the old
    // local-only filtering.
    customerSearchUrl: config.customerSearchUrl || null,
    // Endpoint behind the "ดึงจากโปรแกรมพี่ตุ้ม" button next to Tech. One SAP
    // lookup fills Model, Material, Temper and die type together, because
    // the feed keys all of them by the Tech drawing code.
    techFetchUrl: config.techFetchUrl || null,
    techFetching: false,
    techFetchMessage: '',
    techFetchOk: false,
    customerResults: [],
    customerSearching: false,
    customerSearchFailed: false,
    // SAP code of the picked customer, when it came from the API and has no
    // local row yet — sent on save so the new row is linked to SAP.
    customerCardCode: null,
    // Name of the row last picked from the dropdown. Needed because a SAP
    // customer has no local id yet, so customerName cannot look it up.
    customerPickedName: '',
    _customerSearchTimer: null,
    // Guards against a slow earlier request overwriting a newer one.
    _customerSearchSeq: 0,

    init() {
      // Brand-new checklist: pre-tick every master item for the default die type.
      if (this.mode === 'new' && this.selectedItemIds.length === 0) {
        this.selectedItemIds = this.currentItems.map((item) => item.id);
      }
      // Brand-new checklist: default the material to 6063 if that's still an
      // active option, else just the first one — same convenience the old
      // hardcoded <select> gave for free.
      this.materialName = this.nameOf(this.materials, this.materialId) || '';
      if (this.mode === 'new' && !this.materialName && this.materials.length) {
        const common = this.materials.find((m) => m.name === '6063');
        this.materialName = (common || this.materials[0]).name;
      }
      // Same idea for Temper: T5 covers 4240 of the 4341 drawings SAP lists.
      this.temperName = this.nameOf(this.tempers, this.temperId) || '';
      if (this.mode === 'new' && !this.temperName && this.tempers.length) {
        const commonTemper = this.tempers.find((t) => t.name === 'T5');
        this.temperName = (commonTemper || this.tempers[0]).name;
      }
      // Seed the search boxes with whatever is already selected (edit mode).
      this.modelQuery = this.modelName;
      this.customerQuery = this.customerName;
      // Typing away from the confirmed name un-selects it — otherwise a
      // stale modelId/customerId could sneak past validation while the
      // box shows text that was never actually picked from the list.
      this.$watch('modelQuery', (value) => {
        if (value !== this.modelName) this.modelId = null;
      });
      this.$watch('customerQuery', (value) => {
        if (value !== this.customerName) {
          this.customerId = null;
          this.customerCardCode = null;
          this.customerPickedName = '';
        }
        this.scheduleCustomerSearch();
      });
    },

    get currentItems() {
      return this.items[this.dieType] || [];
    },

    get customItemKeys() {
      return Object.keys(this.results).filter((key) => this.results[key] && this.results[key].is_custom_item);
    },

    get selectedItemsDetailed() {
      const list = [];
      this.currentItems.forEach((item) => {
        if (this.selectedItemIds.includes(item.id)) {
          list.push({ key: String(item.id), topic: item.topic, note: item.note, isCustom: false });
        }
      });
      this.customItemKeys.forEach((key) => {
        const r = this.results[key];
        list.push({ key, topic: r.custom_topic, note: r.custom_note, isCustom: true });
      });
      return list;
    },

    get modelName() {
      const m = this.models.find((x) => x.id === this.modelId);
      return m ? m.name : '';
    },
    get customerName() {
      const c = this.customers.find((x) => x.id === this.customerId);
      if (c) return c.name;
      // Picked from SAP: no local row to look up, so use the picked name.
      return this.customerPickedName || '';
    },
    get managerName() {
      const m = this.managers.find((x) => x.id === this.managerId);
      return m ? m.full_name : '';
    },

    get filteredModels() {
      const q = this.modelQuery.trim().toLowerCase();
      if (!q) return this.models;
      return this.models.filter((m) => m.name.toLowerCase().includes(q));
    },
    get filteredCustomers() {
      const q = this.customerQuery.trim().toLowerCase();
      if (!q) return this.customers;
      // Remote search active: the endpoint has already merged our own
      // customers with the SAP matches, so use its list verbatim.
      if (this.customerSearchUrl) return this.customerResults;
      return this.customers.filter((c) => c.name.toLowerCase().includes(q));
    },
    // The exact master-data row matching what is typed, if there is one.
    // Matched case-insensitively because die_models.name is a utf8mb4_unicode_ci
    // unique key, so "AL-6063" and "al-6063" are the same row server-side.
    get modelMatch() {
      const q = this.modelQuery.trim().toLowerCase();
      if (!q) return null;
      return this.models.find((m) => m.name.toLowerCase() === q) || null;
    },
    // True when the typed Model is not in master data yet and would be
    // created on save — drives the hint under the box and the extra
    // "+ Add new model" row in the dropdown.
    get modelIsNew() {
      return this.allowNewModel && !!this.modelQuery.trim() && !this.modelMatch;
    },
    // True once the typed text is acceptable: blank, an actual master-data
    // row, or — where free entry is allowed — any name, which gets added to
    // master data on save. Drives the red border on the box.
    get modelConfirmed() {
      if (!this.modelQuery.trim()) return true;
      // Free entry allowed: any non-blank name is acceptable, whether it is
      // an existing row or one that will be added on save.
      if (this.allowNewModel) return true;
      return !!this.modelId && this.modelName === this.modelQuery;
    },
    // The exact master-data row matching what is typed, if there is one.
    // Case-insensitive, matching the utf8mb4_unicode_ci unique key on
    // customers.name that the INSERT would collide on.
    get customerMatch() {
      const q = this.customerQuery.trim().toLowerCase();
      if (!q) return null;
      const inList = (list) => (list || []).find((c) => c.name.toLowerCase() === q);
      return inList(this.customers) || inList(this.customerResults) || null;
    },
    // True when the typed Customer is not in master data yet and would be
    // created on save.
    get customerIsNew() {
      if (!this.allowNewCustomer || !this.customerQuery.trim()) return false;
      // Do not promise to create it while the SAP lookup is still running —
      // the answer may well be that it already exists there.
      if (this.customerSearching) return false;
      return !this.customerMatch;
    },
    // Blank, an actual master-data row, or — where free entry is allowed —
    // any name, which gets added to master data on save.
    get customerConfirmed() {
      if (!this.customerQuery.trim()) return true;
      if (this.allowNewCustomer) return true;
      return !!this.customerId && this.customerName === this.customerQuery;
    },

    isSelected(itemId) {
      return this.selectedItemIds.includes(itemId);
    },

    toggleExpand(key) {
      if (this.readOnly) return;
      this.expandedKey = this.expandedKey === key ? null : key;
    },

    // True when a selected row still needs attention before it can be
    // submitted (no pass/fail yet, fail without a comment, or an unconfirmed
    // Pass<->Fail flip) — drives the small warning dot on collapsed rows.
    rowNeedsAttention(key) {
      const r = this.results[key];
      if (!r || !r.result) return true;
      if (this.failCommentMissing(key)) return true;
      return this.failToPassNeedsNewComment(key);
    },

    // True once a previously-saved Pass has been switched to Fail in this
    // editing session — shows a hint nudging the engineer to explain why in
    // the comment (also enforced server-side).
    changedFromPassToFail(key) {
      const original = this.originalResults[key];
      const current = this.results[key];
      return !!(original && original.result === 'pass' && current && current.result === 'fail');
    },

    // True once a previously-saved Fail has been switched to Pass — this
    // also needs a fresh comment confirming what was fixed, since simply
    // leaving the old Fail comment in place doesn't explain anything.
    changedFromFailToPass(key) {
      const original = this.originalResults[key];
      const current = this.results[key];
      return !!(original && original.result === 'fail' && current && current.result === 'pass');
    },

    // True when a Fail -> Pass change still needs that fresh comment: either
    // left blank, or left exactly as the old Fail comment was.
    failToPassNeedsNewComment(key) {
      if (!this.changedFromFailToPass(key)) return false;
      const original = this.originalResults[key];
      const current = this.results[key];
      const newComment = (current.comment || '').trim();
      const oldComment = (original.comment || '').trim();
      return newComment === '' || newComment === oldComment;
    },

    // A result row keeps the comment from before its last Pass<->Fail flip
    // (prev_result/note_before). These expose it to the results table so the
    // Fail reason stays visible after the item has been switched to Pass,
    // instead of only the Pass comment that replaced it.
    hasPreviousNote(key) {
      const r = this.results[key];
      return !!(r && r.prev_result && r.note_before && String(r.note_before).trim());
    },
    previousNote(key) {
      const r = this.results[key];
      return r ? r.note_before : '';
    },
    // Label for a stored result value, reused for both the before and the
    // after line so neither is ambiguous about which one it belongs to.
    resultLabel(value) {
      return value === 'fail' ? this.i18n.result_fail : this.i18n.result_pass;
    },
    resultLabelClass(value) {
      return value === 'fail' ? 'text-status-dangerText' : 'text-status-successText';
    },

    rowBgClass(key) {
      const r = this.results[key];
      if (!r || !r.result) return '';
      return r.result === 'fail' ? 'bg-red-50' : 'bg-green-50';
    },

    toggleItem(itemId) {
      if (this.readOnly) return;
      const idx = this.selectedItemIds.indexOf(itemId);
      if (idx === -1) {
        this.selectedItemIds.push(itemId);
        this.resultOf(itemId);
      } else {
        this.selectedItemIds.splice(idx, 1);
      }
    },

    selectAllItems() {
      if (this.readOnly) return;
      this.selectedItemIds = this.currentItems.map((item) => item.id);
    },
    deselectAllItems() {
      if (this.readOnly) return;
      this.selectedItemIds = [];
    },

    addCustomItem() {
      if (this.readOnly) return;
      const key = 'custom_new_' + (this.customCounter++);
      this.results[key] = {
        result: null, comment: '', is_custom_item: true, custom_topic: '', custom_note: '',
      };
    },
    removeCustomItem(key) {
      if (this.readOnly) return;
      delete this.results[key];
    },

    onDieTypeChange() {
      this.results = {};
      this.selectedItemIds = this.currentItems.map((item) => item.id);
      this.selectedItemIds.forEach((id) => this.resultOf(id));
    },

    resultOf(key) {
      if (!this.results[key]) {
        this.results[key] = { result: null, comment: '' };
      }
      return this.results[key];
    },

    setResult(key, value) {
      if (this.readOnly) return;
      const r = this.resultOf(key);
      const original = this.originalResults[key];
      // Flipping a saved Fail to Pass needs a fresh reason explaining what was
      // fixed, so empty the box instead of leaving the old Fail text sitting in
      // it to be re-saved unchanged. The old text is not lost: it is shown
      // read-only beside the box, and the API keeps it as note_before.
      // Only cleared while the box still holds the old text — never wipes
      // something already typed in this session.
      if (original) {
        const untouched = (r.comment || '') === (original.comment || '');
        if (value === 'pass' && r.result !== 'pass' && original.result === 'fail' && untouched) {
          r.comment = '';
        } else if (value === original.result && (r.comment || '').trim() === '') {
          // Toggled back to the saved result with the box still empty: put the
          // saved comment back, so flipping Pass/Fail twice does not discard it.
          r.comment = original.comment || '';
        }
      }
      r.result = value;
    },

    // The comment as last saved, shown as context while a Fail is being
    // turned into a Pass so the engineer can see what they are answering.
    savedNoteFor(key) {
      const original = this.originalResults[key];
      return original && original.comment ? String(original.comment).trim() : '';
    },
    showSavedNote(key) {
      return this.changedFromFailToPass(key) && this.savedNoteFor(key) !== '';
    },

    failCommentMissing(key) {
      const r = this.results[key];
      return !!(r && r.result === 'fail' && !r.comment.trim());
    },

    hasAnyFailCommentMissing() {
      return this.selectedItemsDetailed.some((item) => this.failCommentMissing(item.key));
    },

    async callJson(url, payload) {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
        body: JSON.stringify(payload),
      });
      return res.json();
    },

    selectModel(m) {
      this.modelId = m.id;
      this.modelQuery = m.name;
      this.modelDropdownOpen = false;
    },
    // Dropdown row shown when the typed Model has no master-data match:
    // keeps the typed text and leaves modelId null, so the save API creates
    // the die_models row and links the checklist to it.
    useTypedModel() {
      if (!this.allowNewModel) return;
      const typed = this.modelQuery.trim();
      if (!typed) return;
      this.modelId = null;
      this.modelQuery = typed;
      this.modelDropdownOpen = false;
    },
    onModelBlur() {
      // Give a click on a dropdown option (which fires @mousedown.prevent)
      // a chance to run first, then close the list.
      setTimeout(() => {
        this.modelDropdownOpen = false;
        if (this.allowNewModel) {
          // Free entry: keep whatever was typed. If it happens to spell an
          // existing model, adopt that row's id so no duplicate is created.
          const match = this.modelMatch;
          if (match) {
            this.modelId = match.id;
            this.modelQuery = match.name;
          }
          return;
        }
        // Master-data only: snap the text back to the actual selection so a
        // half-typed query can't masquerade as a pick.
        this.modelQuery = this.modelName;
      }, 150);
    },

    selectCustomer(c) {
      // A row from the SAP side of the search has id null and a card_code;
      // the save API turns that into a local, SAP-linked customer row.
      this.customerId = c.id || null;
      this.customerCardCode = c.card_code || null;
      this.customerPickedName = c.name;
      this.customerQuery = c.name;
      this.customerDropdownOpen = false;
    },
    // Debounced lookup against customer_search_api.php. Results replace the
    // dropdown contents while a query is present; clearing the box falls back
    // to the local list the page was rendered with.
    scheduleCustomerSearch() {
      if (!this.customerSearchUrl) return;
      if (this._customerSearchTimer) clearTimeout(this._customerSearchTimer);
      const term = this.customerQuery.trim();
      if (!term) {
        this.customerResults = [];
        this.customerSearching = false;
        this.customerSearchFailed = false;
        return;
      }
      this.customerSearching = true;
      this._customerSearchTimer = setTimeout(() => this.runCustomerSearch(term), 250);
    },

    async runCustomerSearch(term) {
      const seq = ++this._customerSearchSeq;
      try {
        const res = await fetch(this.customerSearchUrl + '?q=' + encodeURIComponent(term), {
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        // A newer keystroke already started its own request — drop this answer.
        if (seq !== this._customerSearchSeq) return;
        this.customerResults = data.success ? (data.items || []) : [];
        this.customerSearchFailed = !data.success || data.api_available === false;
      } catch (e) {
        if (seq !== this._customerSearchSeq) return;
        this.customerResults = [];
        this.customerSearchFailed = true;
      } finally {
        if (seq === this._customerSearchSeq) this.customerSearching = false;
      }
    },

    // Dropdown row shown when the typed Customer has no master-data match.
    useTypedCustomer() {
      if (!this.allowNewCustomer) return;
      const typed = this.customerQuery.trim();
      if (!typed) return;
      this.customerId = null;
      this.customerCardCode = null;
      this.customerPickedName = '';
      this.customerQuery = typed;
      this.customerDropdownOpen = false;
    },
    onCustomerBlur() {
      setTimeout(() => {
        this.customerDropdownOpen = false;
        if (this.allowNewCustomer) {
          // Free entry: keep what was typed, but adopt an existing row when
          // the text spells one, so no duplicate customer is created.
          const match = this.customerMatch;
          if (match) {
            this.customerId = match.id || null;
            this.customerCardCode = match.card_code || null;
            this.customerPickedName = match.name;
            this.customerQuery = match.name;
          }
          return;
        }
        this.customerQuery = this.customerName;
      }, 150);
    },

    // Small helper for the id -> name lookups the payload needs.
    nameOf(list, id) {
      const row = (list || []).find((x) => x.id === id);
      return row ? row.name : null;
    },
    // ...and back again. Returns null for a name the master list does not
    // have yet, which tells the save API to create that row.
    idOfName(list, name) {
      const clean = (name || '').trim();
      if (!clean) return null;
      const row = (list || []).find((x) => x.name === clean);
      return row && row.id ? row.id : null;
    },

    // Adopt a master-data value that came back from the SAP lookup. If the name
    // is already in our list we select that row; if not we add it to the local
    // list with a null id, and buildPayload sends the name so the save API
    // creates the master row. Returns the chosen id (or null for a new name).
    adoptMasterValue(list, name) {
      const clean = (name || '').trim();
      if (!clean) return '';
      const existing = list.find((x) => x.name.toLowerCase() === clean.toLowerCase());
      if (existing) return existing.name;
      list.push({ id: null, name: clean });
      return clean;
    },

    // "ดึงจากโปรแกรมพี่ตุ้ม": looks the typed Tech up in the SAP tech-drawing
    // feed and fills Model, Material, Temper and die type from it. Whatever the
    // engineer had typed is overwritten, since SAP is the authority here.
    async fetchFromTechProgramClicked() {
      if (this.techFetching) return;
      this.techFetchMessage = '';
      this.techFetchOk = false;

      const tech = (this.tech || '').trim();
      if (!tech) {
        this.techFetchMessage = this.i18n.drawing_tech_required;
        return;
      }
      if (!this.techFetchUrl) {
        this.techFetchMessage = this.i18n.fetch_from_tech_program_not_implemented_yet;
        return;
      }

      this.techFetching = true;
      try {
        const res = await fetch(this.techFetchUrl + '?tech=' + encodeURIComponent(tech), {
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        if (!data.success) {
          this.techFetchMessage = this.i18n[data.message] || data.message;
          return;
        }

        const d = data.drawing;
        const filled = [];

        // Model is a free-entry search box, so set both the id and the text.
        // SAP writes "-" when a drawing has no model code; the helper turns that
        // into an empty string, and an empty field is never overwritten.
        if (d.model) {
          const modelMatch = this.models.find((m) => m.name.toLowerCase() === d.model.toLowerCase());
          this.modelId = modelMatch ? modelMatch.id : null;
          this.modelQuery = d.model;
          this.modelDropdownOpen = false;
          filled.push(this.i18n.label_model);
        }
        if (d.material) {
          this.materialName = this.adoptMasterValue(this.materials, d.material);
          filled.push(this.i18n.label_material);
        }
        if (d.temper) {
          this.temperName = this.adoptMasterValue(this.tempers, d.temper);
          filled.push(this.i18n.label_temper);
        }
        // Changing the die type resets the item selection, so only touch it when
        // SAP actually said which type it is and it differs from the current one.
        if (d.die_type && d.die_type !== this.dieType) {
          this.dieType = d.die_type;
          this.onDieTypeChange();
          filled.push(this.i18n.die_type_solid_hollow);
        }

        // Name what actually changed rather than claiming all four every time.
        this.techFetchOk = filled.length > 0;
        this.techFetchMessage = filled.length
          ? this.i18n.tech_fetch_filled + ' ' + filled.join(', ')
            + (d.section_name ? ' — ' + d.section_name : '')
          : this.i18n.tech_fetch_nothing;
      } catch (e) {
        this.techFetchMessage = this.i18n.server_error;
      } finally {
        this.techFetching = false;
      }
    },

    // A Model is set either by picking a master-data row (modelId) or, when
    // free entry is allowed, by typing a name that will be created on save.
    get modelProvided() {
      return !!this.modelId || (this.allowNewModel && !!this.modelQuery.trim());
    },
    get customerProvided() {
      return !!this.customerId || (this.allowNewCustomer && !!this.customerQuery.trim());
    },
    step1Valid() {
      return !!(this.dieNo.trim() && this.modelProvided && this.customerProvided && this.dieType && this.materialName && this.managerId);
    },

    goNext() {
      this.formError = '';
      if (this.step === 1 && !this.step1Valid()) {
        this.formError = this.i18n.invalid_input;
        return;
      }
      if (this.step === 3 && this.hasAnyFailCommentMissing()) {
        this.formError = this.i18n.fail_needs_comment;
        return;
      }
      this.step++;
    },
    goBack() {
      this.formError = '';
      this.step--;
    },

    async uploadPdf(event) {
      const file = event.target.files[0];
      if (!file) return;
      this.pdfError = '';
      const fd = new FormData();
      fd.append('type', 'pdf');
      fd.append('file', file);
      this.pdfUploading = true;
      try {
        const res = await fetch('../api/upload_api.php', {
          method: 'POST',
          headers: { 'X-CSRF-Token': this.csrfToken },
          body: fd,
        });
        const data = await res.json();
        if (!data.success) {
          this.pdfError = this.i18n[data.message] || data.message;
          return;
        }
        this.pdfPath = data.filename;
        this.pdfUrl = data.url;
      } catch (e) {
        this.pdfError = this.i18n.server_error;
      } finally {
        this.pdfUploading = false;
        event.target.value = '';
      }
    },
    removePdf() {
      this.pdfPath = null;
      this.pdfUrl = null;
    },

    async uploadDesignImage(event) {
      const file = event.target.files[0];
      if (!file) return;
      this.designImageError = '';
      const fd = new FormData();
      fd.append('type', 'image');
      fd.append('file', file);
      this.designImageUploading = true;
      try {
        const res = await fetch('../api/upload_api.php', {
          method: 'POST',
          headers: { 'X-CSRF-Token': this.csrfToken },
          body: fd,
        });
        const data = await res.json();
        if (!data.success) {
          this.designImageError = this.i18n[data.message] || data.message;
          return;
        }
        this.designImagePath = data.filename;
        this.designImageUrl = data.url;
      } catch (e) {
        this.designImageError = this.i18n.server_error;
      } finally {
        this.designImageUploading = false;
        event.target.value = '';
      }
    },
    async fetchDesignImageFromApi() {
      if (this.designImageFetching) return;
      this.designImageError = '';
      const tdNo = (this.tech || '').trim();
      if (!tdNo) {
        this.designImageError = this.i18n.drawing_tech_required;
        return;
      }
      this.designImageFetching = true;
      try {
        const res = await fetch('../api/drawing_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
          body: JSON.stringify({ td_no: tdNo }),
        });
        const data = await res.json();
        if (!data.success) {
          this.designImageError = this.i18n[data.message] || data.message;
          return;
        }
        this.designImagePath = data.filename;
        this.designImageUrl = data.url;
      } catch (e) {
        this.designImageError = this.i18n.server_error;
      } finally {
        this.designImageFetching = false;
      }
    },
    removeDesignImage() {
      this.designImagePath = null;
      this.designImageUrl = null;
    },

    closePdfModal() {
      this.pdfModalOpen = false;
    },

    // Called from the "attach PDF" modal's own submit button once a file has
    // been uploaded — re-runs save('pending'), which will now pass the PDF
    // check and go through.
    async continueSubmitFromModal() {
      if (!this.pdfPath) return;
      this.pdfModalOpen = false;
      await this.save('pending');
    },

    buildPayload(status) {
      const resultsPayload = {};
      Object.keys(this.results).forEach((key) => {
        const r = this.results[key];
        if (!r) return;
        const isCustom = !!r.is_custom_item;
        if (!isCustom && (r.result || r.comment)) {
          resultsPayload[key] = { result: r.result, comment: r.comment };
        } else if (isCustom) {
          resultsPayload[key] = {
            result: r.result,
            comment: r.comment,
            is_custom_item: true,
            custom_topic: r.custom_topic,
            custom_note: r.custom_note,
          };
        }
      });
      return {
        id: this.checklistId,
        die_no: this.dieNo.trim(),
        tech: this.tech.trim(),
        model_id: this.modelId,
        // Sent so the API can find-or-create the die_models row when the
        // engineer typed a Model that is not in master data yet.
        model_name: this.allowNewModel ? this.modelQuery.trim() : null,
        customer_id: this.customerId,
        // Lets the API find-or-create the customers row when the engineer
        // typed a customer that is not in master data yet.
        customer_name: this.allowNewCustomer ? this.customerQuery.trim() : null,
        customer_card_code: this.customerCardCode,
        die_type: this.dieType,
        // Sent as id + name together: the id links an existing master row,
        // the name lets the API create one for a value the SAP lookup returned
        // that our lists never had (e.g. material 6106, temper T64).
        material_id: this.idOfName(this.materials, this.materialName),
        material_name: this.materialName,
        temper_id: this.idOfName(this.tempers, this.temperName),
        temper_name: this.temperName,
        assigned_manager_id: this.managerId,
        status: status,
        selected_item_ids: this.selectedItemIds,
        results: resultsPayload,
        design_pdf_path: this.pdfPath,
        design_image_path: this.designImagePath,
      };
    },

    // Final action of the 3-step "new checklist" wizard: creates the
    // checklist record right away as a draft. Attaching the PDF and
    // submitting it for manager approval happens afterwards, on the
    // checklist's own page.
    async createChecklist() {
      if (this.hasAnyFailCommentMissing()) {
        this.formError = this.i18n.fail_needs_comment;
        return;
      }
      await this.save('draft');
    },

    async save(status) {
      this.formError = '';
      const firstUnexplained = this.selectedItemsDetailed.find((item) => {
        if (!this.changedFromPassToFail(item.key)) return false;
        const r = this.results[item.key];
        return !r || !r.comment || !r.comment.trim();
      });
      if (firstUnexplained) {
        this.formError = this.i18n.pass_to_fail_reason_required;
        this.expandedKey = firstUnexplained.key;
        return;
      }
      const firstUnconfirmed = this.selectedItemsDetailed.find((item) => this.failToPassNeedsNewComment(item.key));
      if (firstUnconfirmed) {
        this.formError = this.i18n.fail_to_pass_comment_required;
        this.expandedKey = firstUnconfirmed.key;
        return;
      }
      if (this.hasAnyFailCommentMissing()) {
        this.formError = this.i18n.fail_needs_comment;
        const firstBad = this.selectedItemsDetailed.find((item) => this.failCommentMissing(item.key));
        if (firstBad) this.expandedKey = firstBad.key;
        return;
      }
      if (status === 'pending') {
        if (!this.step1Valid()) {
          this.formError = this.i18n.invalid_input;
          this.step = 1;
          return;
        }
        if (this.selectedItemsDetailed.length === 0) {
          this.formError = this.i18n.invalid_input;
          this.step = 2;
          return;
        }
        const firstIncomplete = this.selectedItemsDetailed.find((item) => !this.results[item.key] || !this.results[item.key].result);
        if (firstIncomplete) {
          this.formError = this.i18n.incomplete_results;
          this.expandedKey = firstIncomplete.key;
          return;
        }
        if (!this.pdfPath) {
          this.pdfModalOpen = true;
          return;
        }
      }

      const flag = status === 'draft' ? 'savingDraft' : 'submitting';
      this[flag] = true;
      try {
        const data = await this.callJson('../api/checklist_api.php?action=save', this.buildPayload(status));
        if (!data.success) {
          this.formError = this.i18n[data.message] || data.message;
          this[flag] = false;
          return;
        }
        // A brand-new checklist (mode 'new') lands on its own page next,
        // so the engineer can attach the PDF and submit it for approval.
        // An existing checklist being edited/submitted (mode 'view')
        // returns to the dashboard as before.
        let target = 'dashboard.php';
        if (this.mode === 'new') {
          target = 'checklist_view.php?id=' + data.id;
          // ถ้าดึงรูปอัตโนมัติไม่สำเร็จ ส่งสาเหตุไปแสดงที่หน้า checklist
          if (data.image_fetch && data.image_fetch !== 'ok') {
            target += '&img_msg=' + encodeURIComponent(data.image_fetch);
          }
        }
        window.location.href = target;
      } catch (e) {
        this.formError = this.i18n.server_error;
        this[flag] = false;
      }
    },
  };
}
