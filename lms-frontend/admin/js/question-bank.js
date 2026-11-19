(function () {
  'use strict';

  const state = {
    questions: [],
    pagination: { current_page: 1, per_page: 25, total: 0, total_pages: 1 },
    filters: { q: '', category_id: '', subject_id: '', approval_status: 'pending' },
    categories: [],
    subjects: [],
    topics: [],
    reviewQuestionId: null,
    reviewAction: 'approve',
    bound: false,
  };

  let els = {};

  function hasPageRoot() { return !!document.getElementById('adminQuestionBankRoot'); }

  function cacheElements() {
    els = {
      root: document.getElementById('adminQuestionBankRoot'),
      notice: document.getElementById('qbAdminNotice'),
      search: document.getElementById('qbAdminSearch'),
      filterStatus: document.getElementById('qbAdminFilterStatus'),
      filterCategory: document.getElementById('qbAdminFilterCategory'),
      filterSubject: document.getElementById('qbAdminFilterSubject'),
      refreshBtn: document.getElementById('qbAdminRefreshBtn'),
      listBody: document.getElementById('qbAdminListBody'),
      pagination: document.getElementById('qbAdminPagination'),

      reviewModal: document.getElementById('qbReviewModal'),
      reviewTitle: document.getElementById('qbReviewTitle'),
      reviewPreview: document.getElementById('qbReviewPreview'),
      reviewNote: document.getElementById('qbReviewNote'),
      reviewClose: document.getElementById('qbReviewClose'),
      reviewCancel: document.getElementById('qbReviewCancel'),
      reviewApprove: document.getElementById('qbReviewApprove'),
      reviewReject: document.getElementById('qbReviewReject'),

      versionsModal: document.getElementById('qbAdminVersionsModal'),
      versionsTitle: document.getElementById('qbAdminVersionsTitle'),
      versionsBody: document.getElementById('qbAdminVersionsBody'),
      versionsClose: document.getElementById('qbAdminVersionsClose'),
      versionsDone: document.getElementById('qbAdminVersionsDone'),

      newCategoryName: document.getElementById('qbNewCategoryName'),
      newCategoryParent: document.getElementById('qbNewCategoryParent'),
      addCategoryBtn: document.getElementById('qbAddCategoryBtn'),
      categoryList: document.getElementById('qbCategoryList'),
      newTopicName: document.getElementById('qbNewTopicName'),
      newTopicSubject: document.getElementById('qbNewTopicSubject'),
      addTopicBtn: document.getElementById('qbAddTopicBtn'),
      topicList: document.getElementById('qbTopicList'),

      tabs: document.querySelectorAll('.qb-tab'),
      questionsPanel: document.getElementById('qbAdminQuestionsPanel'),
      taxonomyPanel: document.getElementById('qbAdminTaxonomyPanel'),
    };
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function setNotice(message, type) {
    if (!els.notice) return;
    if (!message) { els.notice.className = 'qb-notice'; els.notice.textContent = ''; return; }
    els.notice.className = 'qb-notice ' + (type || 'success');
    els.notice.textContent = message;
  }

  function normalizeRows(response, key) {
    if (!response) return [];
    const data = response.data || response;
    if (Array.isArray(data)) return data;
    if (Array.isArray(data[key])) return data[key];
    if (Array.isArray(data.rows)) return data.rows;
    return [];
  }

  function typeLabel(type) {
    const map = { multiple_choice: 'Multiple choice', true_false: 'True / False', short_answer: 'Short answer', essay: 'Essay' };
    return map[type] || type || '-';
  }

  function statusBadge(status) {
    const s = String(status || 'draft');
    return '<span class="qb-badge qb-badge-' + escapeHtml(s) + '">' + escapeHtml(s) + '</span>';
  }

  function visibilityBadge(v) {
    const value = String(v || 'private');
    return '<span class="qb-badge qb-badge-draft">' + escapeHtml(value) + '</span>';
  }

  function openModal(modal) { if (modal) modal.classList.add('open'); }
  function closeModal(modal) { if (modal) modal.classList.remove('open'); }

  /* ---------------- Data loading ---------------- */

  async function loadTaxonomy() {
    try {
      const [catRes, subRes, topicRes] = await Promise.all([
        QuestionBankAPI.getCategories(),
        SubjectAPI.getAll({ limit: 500 }),
        QuestionBankAPI.getTopics(),
      ]);
      state.categories = normalizeRows(catRes, 'categories');
      state.subjects = normalizeRows(subRes, 'subjects');
      state.topics = normalizeRows(topicRes, 'topics');
    } catch (error) {
      console.warn('Failed to load taxonomy:', error);
    }
  }

  function fillSelect(select, rows, labelKey, valueKey, placeholder, selected) {
    if (!select) return;
    select.innerHTML = '<option value="">' + escapeHtml(placeholder) + '</option>';
    rows.forEach(function (row) {
      const option = document.createElement('option');
      option.value = String(row[valueKey]);
      option.textContent = String(row[labelKey]);
      if (String(selected || '') === String(row[valueKey])) option.selected = true;
      select.appendChild(option);
    });
  }

  function populateSelects() {
    fillSelect(els.filterCategory, state.categories, 'name', 'category_id', 'All categories', state.filters.category_id);
    fillSelect(els.filterSubject, state.subjects, 'subject_name', 'subject_id', 'All subjects', state.filters.subject_id);

    // Category parent selector (exclude nothing; server guards cycles).
    if (els.newCategoryParent) {
      els.newCategoryParent.innerHTML = '<option value="">No parent</option>';
      state.categories.forEach(function (c) {
        const option = document.createElement('option');
        option.value = String(c.category_id);
        option.textContent = String(c.name);
        els.newCategoryParent.appendChild(option);
      });
    }
    // Topic subject selector.
    if (els.newTopicSubject) {
      els.newTopicSubject.innerHTML = '<option value="">General (no subject)</option>';
      state.subjects.forEach(function (s) {
        const option = document.createElement('option');
        option.value = String(s.subject_id);
        option.textContent = String(s.subject_name);
        els.newTopicSubject.appendChild(option);
      });
    }
  }

  function buildParams() {
    const params = {
      q: state.filters.q,
      category_id: state.filters.category_id,
      subject_id: state.filters.subject_id,
      approval_status: state.filters.approval_status,
      page: state.pagination.current_page,
      limit: state.pagination.per_page,
    };
    const out = {};
    Object.keys(params).forEach(function (k) {
      if (params[k] !== null && params[k] !== undefined && params[k] !== '') out[k] = params[k];
    });
    return out;
  }

  async function loadQuestions() {
    if (els.listBody) els.listBody.innerHTML = '<tr><td colspan="7" class="qb-empty">Loading questions...</td></tr>';
    try {
      const response = await QuestionBankAPI.getQuestions(buildParams());
      const data = response.data || {};
      state.questions = Array.isArray(data.questions) ? data.questions : [];
      const p = data.pagination || {};
      state.pagination = {
        current_page: Number(p.current_page) || 1,
        per_page: Number(p.per_page) || 25,
        total: Number(p.total) || 0,
        total_pages: Number(p.total_pages) || 1,
      };
      renderTable();
      renderPagination();
      setNotice('');
    } catch (error) {
      if (els.listBody) els.listBody.innerHTML = '<tr><td colspan="7" class="qb-empty">Failed to load questions.</td></tr>';
      setNotice(error.message || 'Unable to load questions.', 'error');
    }
  }

  function renderTable() {
    if (!els.listBody) return;
    if (state.questions.length === 0) {
      els.listBody.innerHTML = '<tr><td colspan="7" class="qb-empty">No questions match your filters.</td></tr>';
      return;
    }
    els.listBody.innerHTML = state.questions.map(function (q) {
      const text = String(q.question_text || '');
      const preview = text.length > 150 ? text.substring(0, 150) + '…' : text;
      const actions = [];
      if (String(q.approval_status) === 'pending') {
        actions.push('<button class="qb-btn qb-btn-primary qb-btn-sm" data-action="review" data-id="' + q.question_id + '">Review</button>');
      } else {
        actions.push('<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="review" data-id="' + q.question_id + '">Manage</button>');
      }
      actions.push('<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="versions" data-id="' + q.question_id + '">Versions</button>');

      return '<tr>' +
        '<td class="qb-text-preview" title="' + escapeHtml(text) + '">' + escapeHtml(preview) + '</td>' +
        '<td>' + escapeHtml(typeLabel(q.question_type)) + '</td>' +
        '<td>' + escapeHtml(q.category_name || '-') + '</td>' +
        '<td>' + escapeHtml(q.owner_username || 'Institution') + '</td>' +
        '<td>' + statusBadge(q.approval_status) + '</td>' +
        '<td>' + visibilityBadge(q.visibility) + '</td>' +
        '<td><div class="qb-row-actions">' + actions.join('') + '</div></td>' +
        '</tr>';
    }).join('');
  }

  function renderPagination() {
    if (!els.pagination) return;
    const p = state.pagination;
    if (!p.total_pages || p.total_pages <= 1) {
      els.pagination.innerHTML = '<span style="font-size:0.8rem;color:#64748b;">' + p.total + ' question(s)</span>';
      return;
    }
    let html = '<span style="font-size:0.8rem;color:#64748b;margin-right:0.5rem;">' + p.total + ' question(s)</span>';
    html += '<button data-page="' + (p.current_page - 1) + '"' + (p.current_page <= 1 ? ' disabled' : '') + '>‹</button>';
    for (let i = 1; i <= p.total_pages; i++) {
      html += '<button data-page="' + i + '"' + (i === p.current_page ? ' class="active"' : '') + '>' + i + '</button>';
    }
    html += '<button data-page="' + (p.current_page + 1) + '"' + (p.current_page >= p.total_pages ? ' disabled' : '') + '>›</button>';
    els.pagination.innerHTML = html;
  }

  /* ---------------- Review ---------------- */

  function openReview(q) {
    state.reviewQuestionId = q.question_id;
    els.reviewTitle.textContent = String(q.approval_status) === 'pending' ? 'Review Question #' + q.question_id : 'Manage Question #' + q.question_id;
    const opts = [];
    if (q.question_type === 'multiple_choice') {
      // Options are shown via versions; keep the preview concise here.
    }
    els.reviewPreview.innerHTML =
      '<p style="margin:0 0 0.4rem;"><strong>' + escapeHtml(q.question_text) + '</strong></p>' +
      '<p style="margin:0;color:#475569;">Type: ' + escapeHtml(typeLabel(q.question_type)) + ' · Points: ' + escapeHtml(q.points) + ' · Difficulty: ' + escapeHtml(q.difficulty) + '</p>' +
      (q.correct_answer ? '<p style="margin:0.4rem 0 0;color:#475569;">Answer: ' + escapeHtml(q.correct_answer) + '</p>' : '');
    els.reviewNote.value = '';
    openModal(els.reviewModal);
  }

  async function submitReview(action) {
    const id = state.reviewQuestionId;
    if (!id) return;
    const note = String(els.reviewNote.value || '').trim();
    if (action === 'reject' && !note) {
      setNotice('A review note is required to reject a question.', 'error');
      return;
    }
    try {
      if (action === 'approve') await QuestionBankAPI.approve(id, note);
      else await QuestionBankAPI.reject(id, note);
      showToast(action === 'approve' ? 'Question approved' : 'Question rejected', 'success');
      closeModal(els.reviewModal);
      state.reviewQuestionId = null;
      await loadQuestions();
    } catch (error) {
      setNotice(error.message || 'Unable to review question.', 'error');
    }
  }

  /* ---------------- Versions ---------------- */

  async function showVersions(id) {
    if (!els.versionsBody) return;
    els.versionsTitle.textContent = 'Version History #' + id;
    els.versionsBody.innerHTML = '<p>Loading versions...</p>';
    openModal(els.versionsModal);
    try {
      const response = await QuestionBankAPI.getVersions(id);
      const versions = normalizeRows(response, 'versions');
      if (!versions.length) { els.versionsBody.innerHTML = '<p>No versions found.</p>'; return; }
      els.versionsBody.innerHTML = versions.map(function (v) {
        const c = v.content || {};
        const opts = Array.isArray(c.options) ? c.options : [];
        const optionsHtml = opts.length
          ? '<p><strong>Options:</strong> ' + opts.map(function (o) { return escapeHtml(o.option_label) + '. ' + escapeHtml(o.option_text) + (Number(o.is_correct) === 1 ? ' ✓' : ''); }).join('; ') + '</p>'
          : '';
        return '<div class="qb-version">' +
          '<h4>Version ' + escapeHtml(v.version_number) + ' <span style="font-weight:normal;color:#64748b;font-size:0.75rem;">' + escapeHtml(v.created_at || '') + ' by ' + escapeHtml(v.created_by_username || 'system') + '</span></h4>' +
          '<p><strong>Text:</strong> ' + escapeHtml(c.question_text) + '</p>' +
          '<p><strong>Type:</strong> ' + escapeHtml(typeLabel(c.question_type)) + ' · <strong>Points:</strong> ' + escapeHtml(c.points) + ' · <strong>Difficulty:</strong> ' + escapeHtml(c.difficulty) + '</p>' +
          '<p><strong>Correct answer:</strong> ' + escapeHtml(c.correct_answer || '-') + '</p>' +
          optionsHtml + '</div>';
      }).join('');
    } catch (error) {
      els.versionsBody.innerHTML = '<p>Failed to load versions.</p>';
    }
  }

  /* ---------------- Taxonomy ---------------- */

  function renderTaxonomyLists() {
    if (els.categoryList) {
      if (!state.categories.length) {
        els.categoryList.innerHTML = '<div class="qb-empty" style="padding:0.75rem;">No categories yet.</div>';
      } else {
        els.categoryList.innerHTML = state.categories.map(function (c) {
          const parent = c.parent_name ? ' <span style="color:#94a3b8;font-size:0.72rem;">(under ' + escapeHtml(c.parent_name) + ')</span>' : '';
          return '<div class="qb-tax-item">' +
            '<span>' + escapeHtml(c.name) + parent + '</span>' +
            '<span class="qb-tax-item-actions">' +
            '<button class="qb-btn qb-btn-secondary qb-btn-sm" data-kind="category" data-action="edit" data-id="' + c.category_id + '" data-name="' + escapeHtml(c.name) + '">Edit</button>' +
            '<button class="qb-btn qb-btn-danger qb-btn-sm" data-kind="category" data-action="delete" data-id="' + c.category_id + '">Delete</button>' +
            '</span></div>';
        }).join('');
      }
    }
    if (els.topicList) {
      if (!state.topics.length) {
        els.topicList.innerHTML = '<div class="qb-empty" style="padding:0.75rem;">No topics yet.</div>';
      } else {
        els.topicList.innerHTML = state.topics.map(function (t) {
          const scope = t.subject_name ? ' <span style="color:#94a3b8;font-size:0.72rem;">(' + escapeHtml(t.subject_name) + ')</span>' : ' <span style="color:#94a3b8;font-size:0.72rem;">(general)</span>';
          return '<div class="qb-tax-item">' +
            '<span>' + escapeHtml(t.name) + scope + '</span>' +
            '<span class="qb-tax-item-actions">' +
            '<button class="qb-btn qb-btn-secondary qb-btn-sm" data-kind="topic" data-action="edit" data-id="' + t.topic_id + '" data-name="' + escapeHtml(t.name) + '">Edit</button>' +
            '<button class="qb-btn qb-btn-danger qb-btn-sm" data-kind="topic" data-action="delete" data-id="' + t.topic_id + '">Delete</button>' +
            '</span></div>';
        }).join('');
      }
    }
  }

  async function refreshTaxonomy() {
    await loadTaxonomy();
    populateSelects();
    renderTaxonomyLists();
  }

  async function addCategory() {
    const name = String(els.newCategoryName.value || '').trim();
    if (!name) { setNotice('Enter a category name.', 'error'); return; }
    try {
      await QuestionBankAPI.createCategory({ name: name, parent_id: els.newCategoryParent.value || null });
      els.newCategoryName.value = '';
      showToast('Category added', 'success');
      await refreshTaxonomy();
    } catch (error) { setNotice(error.message || 'Unable to add category.', 'error'); }
  }

  async function addTopic() {
    const name = String(els.newTopicName.value || '').trim();
    if (!name) { setNotice('Enter a topic name.', 'error'); return; }
    try {
      await QuestionBankAPI.createTopic({ name: name, subject_id: els.newTopicSubject.value || null });
      els.newTopicName.value = '';
      showToast('Topic added', 'success');
      await refreshTaxonomy();
    } catch (error) { setNotice(error.message || 'Unable to add topic.', 'error'); }
  }

  async function editTaxonomy(kind, id, currentName) {
    const nextName = window.prompt('New name:', currentName);
    if (nextName === null) return;
    const name = String(nextName).trim();
    if (!name) { setNotice('Name cannot be empty.', 'error'); return; }
    try {
      if (kind === 'category') await QuestionBankAPI.updateCategory(id, { name: name });
      else await QuestionBankAPI.updateTopic(id, { name: name });
      showToast(kind === 'category' ? 'Category updated' : 'Topic updated', 'success');
      await refreshTaxonomy();
      if (kind === 'category') await loadQuestions();
    } catch (error) { setNotice(error.message || 'Unable to update.', 'error'); }
  }

  async function deleteTaxonomy(kind, id) {
    if (!window.confirm('Delete this ' + kind + '? This cannot be undone.')) return;
    try {
      if (kind === 'category') await QuestionBankAPI.deleteCategory(id);
      else await QuestionBankAPI.deleteTopic(id);
      showToast(kind === 'category' ? 'Category deleted' : 'Topic deleted', 'success');
      await refreshTaxonomy();
    } catch (error) { setNotice(error.message || 'Unable to delete.', 'error'); }
  }

  /* ---------------- Events ---------------- */

  function bindEvents() {
    els.tabs?.forEach(function (tab) {
      tab.addEventListener('click', function () {
        const name = tab.getAttribute('data-tab');
        els.tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
        if (els.questionsPanel) els.questionsPanel.classList.toggle('active', name === 'questions');
        if (els.taxonomyPanel) els.taxonomyPanel.classList.toggle('active', name === 'taxonomy');
        setNotice('');
      });
    });

    els.refreshBtn?.addEventListener('click', function () { loadQuestions(); });

    let searchTimer = null;
    els.search?.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.filters.q = els.search.value.trim();
        state.pagination.current_page = 1;
        loadQuestions();
      }, 300);
    });
    els.filterStatus?.addEventListener('change', function () {
      state.filters.approval_status = els.filterStatus.value;
      state.pagination.current_page = 1;
      loadQuestions();
    });
    els.filterCategory?.addEventListener('change', function () {
      state.filters.category_id = els.filterCategory.value;
      state.pagination.current_page = 1;
      loadQuestions();
    });
    els.filterSubject?.addEventListener('change', function () {
      state.filters.subject_id = els.filterSubject.value;
      state.pagination.current_page = 1;
      loadQuestions();
    });
    els.pagination?.addEventListener('click', function (e) {
      const btn = e.target.closest('button[data-page]');
      if (!btn || btn.disabled) return;
      state.pagination.current_page = Number(btn.getAttribute('data-page')) || 1;
      loadQuestions();
    });

    els.listBody?.addEventListener('click', function (e) {
      const btn = e.target.closest('button[data-action]');
      if (!btn) return;
      const id = Number(btn.getAttribute('data-id'));
      const action = btn.getAttribute('data-action');
      const q = state.questions.find(function (item) { return Number(item.question_id) === id; });
      if (action === 'review' && q) openReview(q);
      else if (action === 'versions') showVersions(id);
    });

    els.reviewClose?.addEventListener('click', function () { closeModal(els.reviewModal); });
    els.reviewCancel?.addEventListener('click', function () { closeModal(els.reviewModal); });
    els.reviewApprove?.addEventListener('click', function () { submitReview('approve'); });
    els.reviewReject?.addEventListener('click', function () { submitReview('reject'); });

    els.versionsClose?.addEventListener('click', function () { closeModal(els.versionsModal); });
    els.versionsDone?.addEventListener('click', function () { closeModal(els.versionsModal); });

    els.addCategoryBtn?.addEventListener('click', addCategory);
    els.addTopicBtn?.addEventListener('click', addTopic);
    els.newCategoryName?.addEventListener('keydown', function (e) { if (e.key === 'Enter') addCategory(); });
    els.newTopicName?.addEventListener('keydown', function (e) { if (e.key === 'Enter') addTopic(); });

    function taxonomyClick(e) {
      const btn = e.target.closest('button[data-kind][data-action]');
      if (!btn) return;
      const kind = btn.getAttribute('data-kind');
      const action = btn.getAttribute('data-action');
      const id = Number(btn.getAttribute('data-id'));
      const name = btn.getAttribute('data-name') || '';
      if (action === 'edit') editTaxonomy(kind, id, name);
      else if (action === 'delete') deleteTaxonomy(kind, id);
    }
    els.categoryList?.addEventListener('click', taxonomyClick);
    els.topicList?.addEventListener('click', taxonomyClick);

    document.querySelectorAll('.qb-modal-overlay').forEach(function (overlay) {
      overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(overlay); });
    });
  }

  async function initPage() {
    if (!hasPageRoot()) return;
    cacheElements();
    if (!state.bound) { bindEvents(); state.bound = true; }
    state.pagination.current_page = 1;
    if (els.filterStatus) els.filterStatus.value = state.filters.approval_status;
    await loadTaxonomy();
    populateSelects();
    renderTaxonomyLists();
    await loadQuestions();
  }

  document.addEventListener('page:loaded', function (event) {
    if (event && event.detail && event.detail.page === 'question-bank') {
      initPage();
    }
  });
})();
